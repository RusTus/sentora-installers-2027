"""Sentora Elk node: dashboards someone else made, added to this node's Kibana with one command (sentora-elk-node dashboard).

    sentora-elk-node dashboard install FILE.ndjson [NAME]   a Kibana export (Stack Management > Saved objects > Export),
                                                         or an https:// address of one
    sentora-elk-node dashboard list                       what was added, and what is in it
    sentora-elk-node dashboard remove NAME                takes away exactly what that file added

The file is checked first: one Kibana saved object per line, only kinds that show things (dashboards, visualizations,
Lens, maps, saved searches, data views, tags, Canvas), never ones that act or send data elsewhere (connectors, alerting
rules, actions, cases, synthetics); Sentora's own dashboards (ids sentora-...) are never replaced by it (sentora-elk-node
update keeps those). Kibana imports it through its own API, as the installer does Sentora's dashboards. What each file
added is kept in /etc/sentora-elk/dashboards/NAME.json, so remove takes away only that.
Written by RusTus, 2026-11-23. GPL-3.0.
"""
import base64
import http.client
import json
import os
import re
import ssl
import sys
import time
import urllib.parse
import urllib.request
import uuid

C = "/etc/sentora-elk"
D = C + "/dashboards"
NODE_CN = "sentora-elk-node"
MAX = 20 * 1024 * 1024
ALLOWED = {"dashboard", "visualization", "lens", "map", "search", "index-pattern", "tag", "canvas-workpad", "canvas-element",
           "links", "event-annotation-group"}
NAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")


def die(msg, code=1):
    print(msg)
    sys.exit(code)


def api(method, path, body=None, ctype="application/json"):
    """Kibana's API on this node, as the built-in superuser (its password only in /etc/sentora-elk, root's)."""
    pw = open(C + "/elastic.pw").read().strip()
    ctx = ssl.create_default_context(cafile=C + "/ca.crt")

    class Conn(http.client.HTTPSConnection):
        def connect(self):
            sock = __import__("socket").create_connection(("127.0.0.1", 443), timeout=120)
            self.sock = ctx.wrap_socket(sock, server_hostname=NODE_CN)

    c = Conn(NODE_CN, 443, context=ctx, timeout=120)
    h = {"Authorization": "Basic " + base64.b64encode(("elastic:" + pw).encode()).decode(), "kbn-xsrf": "sentora",
         "elastic-api-version": "2023-10-31"}
    if body is not None:
        h["Content-Type"] = ctype
    c.request(method, path, body=body, headers=h)
    r = c.getresponse()
    data = r.read()
    try:
        return r.status, json.loads(data.decode() or "{}")
    except ValueError:
        return r.status, {"raw": data[:300].decode(errors="replace")}


def read_source(src):
    """The file's bytes, from a file here or an https:// address (never more than 20 MB)."""
    if src.startswith("https://"):
        req = urllib.request.Request(src, headers={"User-Agent": "sentora-elk-node"})
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                if not r.geturl().startswith("https://"):
                    die("Refused: the address sent it on over plain http.")
                data = r.read(MAX + 1)
        except Exception as e:
            die("Could not download it: %s" % e)
    elif re.match(r"^[a-z]+://", src):
        die("Only a file here or an https:// address.")
    else:
        try:
            with open(src, "rb") as f:
                data = f.read(MAX + 1)
        except OSError as e:
            die("Could not read %s: %s" % (src, e.strerror))
    if len(data) > MAX:
        die("Refused: more than 20 MB.")
    return data


def check(data):
    """The saved objects in the file, or why it is refused."""
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError:
        return None, "it is not text (UTF-8): a Kibana export is an .ndjson file"
    objs = []
    for n, line in enumerate(text.splitlines(), 1):
        if not line.strip():
            continue
        try:
            o = json.loads(line)
        except ValueError:
            return None, "line %d is not JSON: a Kibana export is an .ndjson file (one object per line)" % n
        if not isinstance(o, dict):
            return None, "line %d is not a Kibana saved object" % n
        if "exportedCount" in o and "type" not in o:
            continue  # (the summary line at the end of an export)
        t, i = o.get("type"), o.get("id")
        if not isinstance(t, str) or not isinstance(i, str) or not isinstance(o.get("attributes"), dict):
            return None, "line %d is not a Kibana saved object (type, id, attributes)" % n
        if t not in ALLOWED:
            return None, "it holds a '%s' (line %d): only dashboards and what they show are added this way, never things that act or send data elsewhere" % (t, n)
        if i.startswith("sentora-"):
            return None, "it would replace Sentora's own '%s' (line %d): give the objects ids of their own" % (i, n)
        if len(i) > 200 or re.search(r"[\x00-\x1f]", i):
            return None, "line %d has an id that is not usable" % n
        objs.append(o)
    if not objs:
        return None, "there is nothing in it"
    if not any(o["type"] in ("dashboard", "canvas-workpad") for o in objs):
        return None, "there is no dashboard in it"
    return objs, ""


def install(src, name=None):
    data = read_source(src)
    objs, why = check(data)
    if objs is None:
        die("Refused: " + why + ".")
    if name is None:
        base = os.path.basename(urllib.parse.urlparse(src).path if src.startswith("https://") else src)
        name = re.sub(r"\.(ndjson|json)$", "", base)
        name = re.sub(r"[^A-Za-z0-9._-]+", "-", name).strip("-.") or "dashboard"
    if not NAME_RE.match(name):
        die("NAME: letters, digits, dot, dash or underscore (64 at most).", 2)
    os.makedirs(D, exist_ok=True)
    os.chmod(D, 0o700)
    # (a name already used by another file: its objects stay unless they are the same ones)
    body_lines = "\n".join(json.dumps(o, separators=(",", ":")) for o in objs) + "\n"
    b = "----sentora" + uuid.uuid4().hex
    body = ("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"import.ndjson\"\r\nContent-Type: application/ndjson\r\n\r\n%s\r\n--%s--\r\n"
            % (b, body_lines, b)).encode()
    st, r = api("POST", "/api/saved_objects/_import?overwrite=true", body, "multipart/form-data; boundary=" + b)
    if st != 200:
        die("Kibana refused it (%s): %s" % (st, json.dumps(r)[:400]))
    added = [{"type": x.get("type"), "id": x.get("destinationId") or x.get("id"), "title": (x.get("meta") or {}).get("title", "")}
             for x in r.get("successResults", [])]
    errors = r.get("errors") or []
    if not added:
        die("Kibana added nothing: %s" % json.dumps(errors)[:400])
    rec = {"name": name, "source": src if src.startswith("https://") else os.path.abspath(src), "added": int(time.time()), "objects": added}
    p = "%s/%s.json" % (D, name)
    old = []
    if os.path.exists(p):
        try:
            old = json.load(open(p)).get("objects", [])
        except ValueError:
            old = []
    keep = {(o["type"], o["id"]) for o in added}
    rec["objects"] += [o for o in old if (o.get("type"), o.get("id")) not in keep]
    tmp = p + ".tmp"
    with open(tmp, "w") as f:
        json.dump(rec, f, indent=1)
    os.chmod(tmp, 0o600)
    os.replace(tmp, p)
    dash = [o["title"] or o["id"] for o in added if o["type"] in ("dashboard", "canvas-workpad")]
    print("Added '%s': %d object(s), dashboards: %s." % (name, len(added), ", ".join(dash) or "-"))
    if errors:
        print("Kibana left out %d: %s" % (len(errors), "; ".join("%s %s: %s" % (e.get("type"), e.get("id"), (e.get("error") or {}).get("type", "?")) for e in errors[:5])))
    print("In Kibana: Dashboards (top left menu). To take it away again: sentora-elk-node dashboard remove %s" % name)


def records():
    out = []
    if os.path.isdir(D):
        for f in sorted(os.listdir(D)):
            if f.endswith(".json"):
                try:
                    out.append(json.load(open(os.path.join(D, f))))
                except ValueError:
                    pass
    return out


def list_():
    recs = records()
    if not recs:
        print("No dashboards added (Sentora's own are always there). Add one: sentora-elk-node dashboard install FILE.ndjson")
        return
    for r in recs:
        dash = [o["title"] or o["id"] for o in r["objects"] if o["type"] in ("dashboard", "canvas-workpad")]
        print("%-24s %s  %d object(s)  dashboards: %s" % (r["name"], time.strftime("%Y-%m-%d", time.localtime(r.get("added", 0))), len(r["objects"]), ", ".join(dash)))
        print("%-24s from %s" % ("", r.get("source", "?")))


def remove(name):
    if not NAME_RE.match(name or ""):
        die("sentora-elk-node dashboard remove NAME (as dashboard list shows it)", 2)
    p = "%s/%s.json" % (D, name)
    if not os.path.exists(p):
        die("No dashboard file '%s' was added (sentora-elk-node dashboard list)." % name)
    rec = json.load(open(p))
    # (a data view another added file also uses stays)
    others = {(o["type"], o["id"]) for r in records() if r["name"] != name for o in r["objects"]}
    gone = kept = 0
    for o in rec["objects"]:
        if (o["type"], o["id"]) in others or o["id"].startswith("sentora-"):
            kept += 1
            continue
        st, _ = api("DELETE", "/api/saved_objects/%s/%s?force=true" % (urllib.parse.quote(o["type"]), urllib.parse.quote(o["id"], safe="")))
        if st in (200, 404):
            gone += 1
        else:
            print("Kibana did not remove %s %s (%s)" % (o["type"], o["id"], st))
    os.remove(p)
    print("Removed '%s': %d object(s)%s." % (name, gone, ", %d kept (another added file uses them)" % kept if kept else ""))


def main(argv):
    if not argv or argv[0] not in ("install", "list", "remove"):
        die("sentora-elk-node dashboard install FILE.ndjson|https://... [NAME] | list | remove NAME", 2)
    if argv[0] == "install":
        if len(argv) < 2:
            die("sentora-elk-node dashboard install FILE.ndjson|https://... [NAME]", 2)
        install(argv[1], argv[2] if len(argv) > 2 else None)
    elif argv[0] == "list":
        list_()
    else:
        remove(argv[1] if len(argv) > 1 else "")


if __name__ == "__main__":
    main(sys.argv[1:])
