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
import http.client
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


def bulk(docs, index=INDEX):
    """Add documents (id, body) to a data stream; ones already there are left alone."""
    if not docs:
        return 0
    with open(os.path.join(CONF, "es.pw")) as f:
        pw = f.read().strip()
    lines = []
    for i, d in docs:
        lines.append(json.dumps({"create": {"_index": index, "_id": i}}))
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
    issues = len(docs)
    # each service's state now (1 working, 0.5 a problem being looked at or fixed, 0 down), for the wall's tiles
    o = g.get("/v1.0/admin/serviceAnnouncement/healthOverviews")
    stamp = now().strftime("%Y%m%d%H%M")[:-1]  # (one reading per service every 10 minutes)
    for s in o.get("value", []):
        name, status = str(s.get("service") or "")[:128], str(s.get("status") or "")
        if not name:
            continue
        h = 0.0 if status in SERVICE_DOWN else 1.0 if status in SERVICE_OK else 0.5
        doc = base("service-health")
        doc.update({"@timestamp": iso(now()), "m365": {"kind": "service-health", "service": name, "status": status[:64], "health": h,
                                                        "state": SERVICE_WORDS.get(status, "working" if h == 1.0 else "a problem")}})
        docs.append(("svc-" + hashlib.sha1(name.encode()).hexdigest()[:16] + "-" + stamp, doc))
    added = bulk(docs)
    return "service health: %d open, %d service(s), %d new" % (issues, len(docs) - issues, added)


SERVICE_OK = {"serviceOperational", "serviceRestored", "falsePositive", "postIncidentReviewPublished", "investigationSuspended", "resolved"}
SERVICE_DOWN = {"serviceInterruption"}
SERVICE_WORDS = {"serviceOperational": "working", "serviceRestored": "restored", "serviceDegradation": "slow or partly down", "serviceInterruption": "down",
                 "investigating": "Microsoft is looking", "restoringService": "being fixed", "verifyingService": "fix being checked",
                 "extendedRecovery": "recovering", "falsePositive": "working", "postIncidentReviewPublished": "working", "investigationSuspended": "working"}


# ---- Veeam Backup & Replication's REST API (port 9419, its own certificate, pinned by its SHA-256 when switched on)
HEALTH = {"Success": 1.0, "Warning": 0.5, "Failed": 0.0}


class PinnedHTTPS(http.client.HTTPSConnection):
    """HTTPS to a server whose certificate is known by its fingerprint (Veeam's is its own, not from an authority)."""
    def __init__(self, host, port, pin):
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE  # (checked below, by fingerprint, before anything is sent)
        ctx.minimum_version = ssl.TLSVersion.TLSv1_2
        super().__init__(host, port, context=ctx, timeout=60)
        self.pin = pin.lower().replace(":", "")

    def connect(self):
        super().connect()
        got = hashlib.sha256(self.sock.getpeercert(binary_form=True)).hexdigest()
        if got != self.pin:
            self.sock.close()
            raise ssl.SSLError("Veeam's certificate changed (now %s): run sentora-elk-node veeam-api again to check and accept it" % got)


def veeam_call(cfg, method, path, body=None, token=None):
    c = PinnedHTTPS(cfg["host"], int(cfg.get("port", 9419)), cfg["sha256"])
    h = {"x-api-version": "1.1-rev0", "Accept": "application/json"}
    if token:
        h["Authorization"] = "Bearer " + token
    if body is not None:
        h["Content-Type"] = "application/x-www-form-urlencoded"
    c.request(method, path, body=body, headers=h)
    r = c.getresponse()
    data = r.read(20 << 20)
    c.close()
    if r.status >= 400:
        raise urllib.error.HTTPError(path, r.status, r.reason, r.headers, None)
    return json.loads(data or b"{}")


def veeam(st):
    cfg = load(os.path.join(CONF, "veeam.json"), {})
    if not all(cfg.get(k) for k in ("host", "user", "password", "sha256")):
        return None
    tok = veeam_call(cfg, "POST", "/api/oauth2/token", urllib.parse.urlencode(
        {"grant_type": "password", "username": cfg["user"], "password": cfg["password"]}))["access_token"]
    hour = now().strftime("%Y%m%d%H")
    stamp = iso(now())
    docs, newest = [], {}
    # every machine's newest restore point (newest first, up to 5000)
    for skip in range(0, 5000, 1000):
        d = veeam_call(cfg, "GET", "/api/v1/objectRestorePoints?limit=1000&skip=%d&orderColumn=CreationTime&orderAsc=false" % skip, token=tok)
        rows = d.get("data") or []
        for x in rows:
            n, t = str(x.get("name") or "")[:255], str(x.get("creationTime") or "")
            if n and t and n not in newest:
                newest[n] = (t, str(x.get("platformName") or "")[:64])
        if len(rows) < 1000:
            break
    for n, (t, plat) in newest.items():
        try:
            age = (now() - datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))).total_seconds() / 3600
        except ValueError:
            continue
        docs.append(("cover-%s-%s" % (hour, hashlib.sha1(n.encode()).hexdigest()[:16]), {
            "@timestamp": stamp, "event": {"module": "veeam", "dataset": "veeam.coverage", "action": "coverage"}, "host": {"name": n},
            "veeam_job": {"last_backup": t, "hours_since": round(age, 1), "platform": plat}, "message": "coverage"}))
    # each job's state: its last result, when it last ran and runs next
    d = veeam_call(cfg, "GET", "/api/v1/jobs/states?limit=1000", token=tok)
    for j in d.get("data") or []:
        name = str(j.get("name") or "")[:255]
        if not name:
            continue
        res = str(j.get("lastResult") or "")[:32].capitalize() or None
        docs.append(("job-%s-%s" % (hour, hashlib.sha1(name.encode()).hexdigest()[:16]), {
            "@timestamp": stamp, "event": {"module": "veeam", "dataset": "veeam.jobs", "action": "job-state"},
            "veeam_job": {"name": name, "result": res, "health": HEALTH.get(res), "status": str(j.get("status") or "")[:32],
                          "last_run": j.get("lastRun"), "next_run": j.get("nextRun"), "type": str(j.get("type") or "")[:32]}, "message": "job-state"}))
    njobs = len(docs) - len(newest)
    # each repository: how full it is
    nrepo = 0
    try:
        d = veeam_call(cfg, "GET", "/api/v1/backupInfrastructure/repositories/states?limit=500", token=tok)
    except urllib.error.HTTPError:
        d = {}
    for x in d.get("data") or []:
        name = str(x.get("name") or "")[:255]
        cap, free = x.get("capacityGB"), x.get("freeGB")
        if not name or not isinstance(cap, (int, float)) or not isinstance(free, (int, float)) or cap <= 0:
            continue
        nrepo += 1
        docs.append(("repo-%s-%s" % (hour, hashlib.sha1(name.encode()).hexdigest()[:16]), {
            "@timestamp": stamp, "event": {"module": "veeam", "dataset": "veeam.repositories", "action": "repo-state"},
            "veeam_repo": {"name": name, "capacity_gb": float(cap), "free_gb": float(free), "used_gb": float(cap - free),
                           "used_pct": round(100.0 * (cap - free) / cap, 1), "type": str(x.get("type") or "")[:32], "host": str(x.get("hostName") or "")[:255]},
            "message": "repo-state"}))
    added = bulk(docs, "logs-veeam-default")
    return "%d machine(s) with backups, %d job(s), %d repositor%s, %d new" % (len(newest), njobs, nrepo, "y" if nrepo == 1 else "ies", added)


def run(say=None):
    cfg = load(os.path.join(CONF, "m365.json"), {})
    st = load(os.path.join(STATE, "state.json"), {})
    out = {}
    if not all(cfg.get(k) for k in ("tenant", "app", "secret")):
        pass
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
    try:
        v = veeam(st) if time.time() - st.get("veeam_at", 0) >= 3000 or say else None
        if v is not None:
            out["veeam"] = v
            st["veeam_at"] = time.time()
    except urllib.error.HTTPError as e:
        out["veeam"] = "Veeam said %d %s" % (e.code, {401: "the user or password is not right", 403: "that user may not read this"}.get(e.code, ""))
    except Exception as e:
        out["veeam"] = "failed: %s" % str(e.args[0] if len(e.args) == 1 else e)[:200]
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
