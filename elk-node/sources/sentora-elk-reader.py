#!/usr/bin/python3 -I
"""Sentora Elk node: Microsoft 365 / Entra for Kibana (the service sentora-elk-reader, its own user).

Reads Microsoft Graph with an app the administrator registered in Entra (application permissions, read only:
AuditLog.Read.All, Reports.Read.All, ServiceHealth.Read.All; admin consent), and writes to the data stream
logs-m365-default (Elasticsearch user sentora_reader: may only add to logs-m365-*):
  - sign-ins (every 5 minutes): who, from where (Entra's own place for the address), which app, success or why not,
    MFA (needs Entra ID P1 or P2: without it Graph refuses, and the rest still works);
  - Teams and mail activity (every 6 hours, Microsoft's daily reports): per day, and per person for the last 7 days;
  - Microsoft 365 service health (every 5 minutes): the problems Microsoft has open now.
Each document has a fixed id, so nothing is counted twice when a run repeats.

    sentora-elk-reader.py serve     the service
    sentora-elk-reader.py check     one run now, and what each part said

Settings: /etc/sentora-elk-reader/m365.json {"tenant", "app", "secret"} (root and this user only), es.pw, http_ca.crt.
Written by RusTus, 2026-11-20. GPL-3.0.
"""
import base64
import csv
import datetime
import hashlib
import io
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

CONF = "/etc/sentora-elk-reader"
STATE = "/var/lib/sentora-elk-reader"
ES = "https://127.0.0.1:9200"
INDEX = "logs-m365-default"
# (the whole-way test points these at its stand-in for Microsoft; a service started by systemd never has them)
LOGIN = os.environ.get("SENTORA_TEST_LOGIN", "https://login.microsoftonline.com")
GRAPH = os.environ.get("SENTORA_TEST_GRAPH", "https://graph.microsoft.com")
GRAPH_CA = os.environ.get("SENTORA_TEST_CA")


def now():
    return datetime.datetime.now(datetime.timezone.utc)


def iso(t):
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


def load(path, default):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def save_state(st):
    os.makedirs(STATE, exist_ok=True)
    tmp = os.path.join(STATE, "state.json.tmp")
    with open(tmp, "w") as f:
        json.dump(st, f)
    os.replace(tmp, os.path.join(STATE, "state.json"))


class Graph:
    def __init__(self, cfg):
        self.cfg = cfg
        self.token = None
        self.until = 0
        self.ctx = ssl.create_default_context(cafile=GRAPH_CA) if GRAPH_CA else ssl.create_default_context()

    def _token(self):
        if self.token and time.time() < self.until - 120:
            return self.token
        body = urllib.parse.urlencode({"client_id": self.cfg["app"], "client_secret": self.cfg["secret"], "grant_type": "client_credentials",
                                       "scope": GRAPH + "/.default"}).encode()
        req = urllib.request.Request(LOGIN + "/" + urllib.parse.quote(self.cfg["tenant"]) + "/oauth2/v2.0/token", data=body, method="POST")
        with urllib.request.urlopen(req, context=self.ctx, timeout=30) as r:
            d = json.load(r)
        self.token, self.until = d["access_token"], time.time() + int(d.get("expires_in", 3600))
        return self.token

    def get(self, url, raw=False):
        """GET a Graph address (or a nextLink); a report's 302 is followed without the token."""
        for attempt in range(4):
            req = urllib.request.Request(url if url.startswith("https://") else GRAPH + url, headers={"Authorization": "Bearer " + self._token()})
            opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPSHandler(context=self.ctx))
            try:
                with opener.open(req, timeout=60) as r:
                    data = r.read(50 << 20)
                return data if raw else json.loads(data)
            except urllib.error.HTTPError as e:
                if e.code in (301, 302, 303, 307) and e.headers.get("Location", "").startswith("https://"):
                    with urllib.request.urlopen(urllib.request.Request(e.headers["Location"]), context=self.ctx, timeout=60) as r:
                        return r.read(50 << 20)
                if e.code == 429 or e.code >= 500:
                    time.sleep(min(60, int(e.headers.get("Retry-After", "10") or 10)))
                    continue
                raise
        raise RuntimeError("Microsoft Graph kept answering busy")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def bulk(docs):
    """Add documents (id, body) to the data stream; ones already there are left alone."""
    if not docs:
        return 0
    with open(os.path.join(CONF, "es.pw")) as f:
        pw = f.read().strip()
    lines = []
    for i, d in docs:
        lines.append(json.dumps({"create": {"_index": INDEX, "_id": i}}))
        lines.append(json.dumps(d))
    req = urllib.request.Request(ES + "/_bulk", data=("\n".join(lines) + "\n").encode(), method="POST",
                                 headers={"Content-Type": "application/x-ndjson",
                                          "Authorization": "Basic " + base64.b64encode(("sentora_reader:" + pw).encode()).decode()})
    ctx = ssl.create_default_context(cafile=os.path.join(CONF, "http_ca.crt"))
    with urllib.request.urlopen(req, context=ctx, timeout=60) as r:
        res = json.load(r)
    return sum(1 for it in res.get("items", []) if it.get("create", {}).get("status") == 201)


def base(kind):
    return {"event": {"module": "m365", "dataset": "m365." + kind, "kind": "event"}, "m365": {"kind": kind}}


def signins(g, st):
    since = st.get("signins_since") or iso(now() - datetime.timedelta(days=7))
    url = "/v1.0/auditLogs/signIns?$top=500&$filter=" + urllib.parse.quote("createdDateTime ge " + since)
    docs, newest, pages = [], since, 0
    while url and pages < 20:
        d = g.get(url)
        pages += 1
        for s in d.get("value", []):
            t = s.get("createdDateTime") or ""
            if not t:
                continue
            newest = max(newest, t)
            st_ = s.get("status") or {}
            loc = s.get("location") or {}
            geo = loc.get("geoCoordinates") or {}
            dev = s.get("deviceDetail") or {}
            ok = (st_.get("errorCode") or 0) == 0
            doc = base("signin")
            doc.update({"@timestamp": t, "user": {"name": (s.get("userPrincipalName") or "")[:255], "full_name": (s.get("userDisplayName") or "")[:255]},
                        "event": dict(doc["event"], action="sign-in", outcome="success" if ok else "failure", category=["authentication"])})
            if s.get("ipAddress"):
                doc["source"] = {"ip": s["ipAddress"]}
                g_ = {k: v for k, v in (("city_name", loc.get("city")), ("region_name", loc.get("state")), ("country_iso_code", loc.get("countryOrRegion"))) if v}
                if geo.get("latitude") is not None and geo.get("longitude") is not None:
                    g_["location"] = {"lat": geo["latitude"], "lon": geo["longitude"]}
                if g_:
                    doc["source"]["geo"] = g_
            if not ok:
                doc["error"] = {"code": str(st_.get("errorCode")), "message": (st_.get("failureReason") or "")[:500]}
            extra = (st_.get("additionalDetails") or "")
            doc["m365"].update({"app": s.get("appDisplayName"), "client_app": s.get("clientAppUsed"), "conditional_access": s.get("conditionalAccessStatus"),
                                "interactive": bool(s.get("isInteractive")), "risk": s.get("riskLevelDuringSignIn"),
                                "mfa": "MFA" in extra or "multi" in extra.lower(), "details": extra[:300]})
            if not ok:
                doc["m365"]["reason"] = (st_.get("failureReason") or str(st_.get("errorCode")))[:300]
            if dev.get("operatingSystem") or dev.get("browser"):
                doc["user_agent"] = {"os": {"name": dev.get("operatingSystem")}, "name": dev.get("browser")}
            docs.append((s.get("id") or hashlib.sha1(json.dumps(s, sort_keys=True).encode()).hexdigest(), doc))
        url = d.get("@odata.nextLink")
    added = bulk(docs)
    st["signins_since"] = newest
    return "%d sign-in(s) read, %d new" % (len(docs), added)


def report(g, name):
    """One of Microsoft's usage reports as rows (CSV)."""
    data = g.get("/v1.0/reports/" + name, raw=True)
    text = data.decode("utf-8-sig", "replace")
    return list(csv.DictReader(io.StringIO(text)))


def num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def reports(g, st):
    if time.time() - st.get("reports_at", 0) < 6 * 3600:
        return "reports: read less than 6 hours ago"
    docs = []
    for r in report(g, "getTeamsUserActivityCounts(period='D7')"):
        day = r.get("Report Date")
        if not day:
            continue
        doc = base("teams-day")
        doc.update({"@timestamp": day + "T12:00:00Z", "m365": {"kind": "teams-day", "teams": {
            "team_chat": num(r.get("Team Chat Messages")), "private_chat": num(r.get("Private Chat Messages")),
            "calls": num(r.get("Calls")), "meetings": num(r.get("Meetings")), "posts": num(r.get("Post Messages"))}}})
        docs.append(("teams-day-" + day, doc))
    for r in report(g, "getEmailActivityCounts(period='D7')"):
        day = r.get("Report Date")
        if not day:
            continue
        doc = base("mail-day")
        doc.update({"@timestamp": day + "T12:00:00Z", "m365": {"kind": "mail-day", "mail": {
            "sent": num(r.get("Send")), "received": num(r.get("Receive")), "read": num(r.get("Read"))}}})
        docs.append(("mail-day-" + day, doc))
    for r in report(g, "getTeamsUserActivityUserDetail(period='D7')"):
        day, who = r.get("Report Refresh Date"), r.get("User Principal Name")
        if not day or not who:
            continue
        doc = base("teams-user")
        doc.update({"@timestamp": day + "T12:00:00Z", "user": {"name": who[:255]}, "m365": {"kind": "teams-user", "teams": {
            "team_chat": num(r.get("Team Chat Message Count")), "private_chat": num(r.get("Private Chat Message Count")),
            "calls": num(r.get("Call Count")), "meetings": num(r.get("Meeting Count")), "last_active": r.get("Last Activity Date") or None}}})
        docs.append(("teams-user-" + day + "-" + hashlib.sha1(who.encode()).hexdigest()[:16], doc))
    for r in report(g, "getEmailActivityUserDetail(period='D7')"):
        day, who = r.get("Report Refresh Date"), r.get("User Principal Name")
        if not day or not who:
            continue
        doc = base("mail-user")
        doc.update({"@timestamp": day + "T12:00:00Z", "user": {"name": who[:255]}, "m365": {"kind": "mail-user", "mail": {
            "sent": num(r.get("Send Count")), "received": num(r.get("Receive Count")), "read": num(r.get("Read Count")),
            "last_active": r.get("Last Activity Date") or None}}})
        docs.append(("mail-user-" + day + "-" + hashlib.sha1(who.encode()).hexdigest()[:16], doc))
    added = bulk(docs)
    st["reports_at"] = time.time()
    return "reports: %d row(s), %d new" % (len(docs), added)


def health(g, st):
    d = g.get("/v1.0/admin/serviceAnnouncement/issues?$filter=" + urllib.parse.quote("isResolved eq false"))
    docs, hour = [], now().strftime("%Y%m%d%H")
    for i in d.get("value", []):
        doc = base("service-issue")
        doc.update({"@timestamp": iso(now()), "m365": {"kind": "service-issue", "issue": {
            "id": i.get("id"), "title": (i.get("title") or "")[:300], "service": i.get("service"), "classification": i.get("classification"),
            "status": i.get("status"), "since": i.get("startDateTime"), "impact": (i.get("impactDescription") or "")[:500]}}})
        docs.append(("issue-" + str(i.get("id")) + "-" + hour, doc))
    added = bulk(docs)
    return "service health: %d open, %d new this hour" % (len(docs), added)


def run(say=None):
    cfg = load(os.path.join(CONF, "m365.json"), {})
    st = load(os.path.join(STATE, "state.json"), {})
    out = {}
    if not all(cfg.get(k) for k in ("tenant", "app", "secret")):
        out["m365"] = "not set up (sentora-elk-node m365)"
    else:
        g = Graph(cfg)
        for name, part in (("sign-ins", signins), ("reports", reports), ("health", health)):
            try:
                out[name] = part(g, st)
            except urllib.error.HTTPError as e:
                why = {401: "the app's secret or IDs are not right", 403: "the app lacks that permission (or admin consent)" +
                       (", or the tenant has no Entra ID P1/P2 (needed for sign-ins)" if name == "sign-ins" else "")}.get(e.code, "")
                out[name] = "Microsoft said %d %s" % (e.code, why)
            except Exception as e:
                out[name] = "failed: %s" % str(e)[:200]
    st["last"] = {"time": int(time.time()), "parts": out}
    save_state(st)
    if say:
        for k, v in out.items():
            say("%-9s %s" % (k, v))
    return out


if __name__ == "__main__":
    what = sys.argv[1] if len(sys.argv) > 1 else ""
    if what == "serve":
        while True:
            run()
            time.sleep(300)
    elif what == "check":
        run(print)
    else:
        print(__doc__)
        sys.exit(2)
