#!/usr/bin/env python3
"""Sentora Elk node: the Kibana dashboards, written as Kibana 9.5's dashboards API takes them.

    python3 build.py          writes dashboards/<id>.json (one request body per dashboard)

The node installer sends each file with PUT /api/dashboards/<id> (create or replace), so a change here
reaches every node on its next `sentora2027-elk-node --update`. Data view: `sentora-logs` (logs-sentora-*),
made by the installer. Fields: see ../logstash/sentora.conf (sentora.log is the kind of log).
"""
import json
import os

DV = {"type": "data_view_reference", "ref_id": "sentora-logs"}
WEB = 'sentora.log : "web-access"'
DASHBOARDS = [
    ("sentora-overview", "Overview"),
    ("sentora-websites", "Websites"),
    ("sentora-mail", "Mail"),
    ("sentora-security", "Security"),
    ("sentora-panel", "Panel and server"),
]


# column headings for the fields the tables group by
NAMES = {"sentora.domain": "Website", "url.path": "Page", "source.ip": "Address", "source.geo.country_name": "Country",
         "user_agent.name": "Browser or bot", "error.code": "Apache message", "email.to.address": "To",
         "sentora.mail.status": "Result", "user.name": "Name", "event.action": "What", "rule.name": "Jail",
         "process.command_line": "Command", "sentora.zsudo.job": "Job", "sentora.ftp.command": "FTP command",
         "sentora.log": "Kind of log", "sentora.visitor": "Visitor", "event.outcome": "Result",
         "sentora.status_class": "Answer"}


def q(kql):
    return {"language": "kql", "expression": kql}


def metric(title, kql, label, op=None):
    m = {"type": "primary", "operation": "count", "label": ""}
    if op:
        m.update(op)
    return {"type": "metric", "title": title, "data_source": DV, "query": q(kql), "metrics": [m]}


def bars(title, kql, by=None, limit=6, label="Lines"):
    layer = {"type": "bar_stacked", "data_source": DV, "x": {"operation": "date_histogram", "field": "@timestamp"},
             "y": [{"operation": "count", "label": label}]}
    if by:
        layer["breakdown_by"] = {"operation": "terms", "fields": [by], "limit": limit, "label": NAMES.get(by, by)}
    return {"type": "xy", "title": title, "query": q(kql), "layers": [layer],
            "legend": {"visibility": "visible", "position": "right"}}


def pie(title, kql, field, limit=6, label="Lines"):
    return {"type": "pie", "title": title, "data_source": DV, "query": q(kql),
            "metrics": [{"operation": "count", "label": label}],
            "group_by": [{"operation": "terms", "fields": [field], "limit": limit, "label": NAMES.get(field, field)}]}


def table(title, kql, fields, limit=10, label="Count"):
    return {"type": "data_table", "title": title, "data_source": DV, "query": q(kql),
            "metrics": [{"operation": "count", "label": label}],
            "rows": [{"operation": "terms", "fields": [f], "limit": limit, "label": NAMES.get(f, f)} for f in fields]}


def world(title, kql, label="Lines"):
    return {"type": "region_map", "title": title, "data_source": DV, "query": q(kql),
            "metric": {"operation": "count", "label": label},
            "region": {"operation": "terms", "fields": ["source.geo.country_iso_code"], "limit": 100}}


def note(text):
    return ("markdown", {"content": text})


def build(did, title, rows):
    """rows: lists of (width, height, panel); each row starts below the tallest panel of the row before."""
    links = {"layout": "horizontal", "links": [
        {"type": "dashboardLink", "destination": d, "label": t} for d, t in DASHBOARDS]}
    panels = [{"type": "links", "grid": {"x": 0, "y": 0, "w": 48, "h": 2}, "config": links}]
    y = 2
    for row in rows:
        x = 0
        height = 0
        for w, h, p in row:
            kind, cfg = p if isinstance(p, tuple) else ("vis", p)
            panels.append({"type": kind, "grid": {"x": x, "y": y, "w": w, "h": h}, "config": cfg})
            x += w
            height = max(height, h)
        y += height
    return {"title": "Sentora: " + title, "description": "Made by the Sentora Elk node installer; "
            "a copy with another name keeps your own changes (an update replaces this one).",
            "time_range": {"from": "now-24h", "to": "now"}, "refresh_interval": {"pause": False, "value": 60000},
            "panels": panels}


BOARDS = {
    "sentora-overview": build("sentora-overview", "Overview", [
        [(8, 5, metric("Website requests", WEB, "Requests")),
         (8, 5, metric("Visitors (addresses)", WEB + " and sentora.bot : false", "Visitors",
                       {"operation": "unique_count", "field": "source.ip"})),
         (8, 5, metric("Website errors (5xx)", WEB + " and sentora.status_class : \"5xx\"", "Errors")),
         (8, 5, metric("Mail sent", 'sentora.log : "mail" and sentora.mail.status : "sent"', "Mails")),
         (8, 5, metric("Failed logins", "event.outcome : \"failure\" and event.action : *", "Failed logins")),
         (8, 5, metric("Addresses banned", 'sentora.log : "fail2ban" and event.action : "ban"', "Bans"))],
        [(32, 12, bars("Everything the panel logged", "*", "sentora.log", 8)),
         (16, 12, pie("Kinds of log", "*", "sentora.log", 8))],
        [(24, 16, world("Where website visitors come from", WEB + " and sentora.bot : false", "Requests")),
         (24, 16, table("Busiest websites", WEB, ["sentora.domain"], 10, "Requests"))],
    ]),
    "sentora-websites": build("sentora-websites", "Websites", [
        [(12, 5, metric("Requests", WEB, "Requests")),
         (12, 5, metric("Visitors (addresses)", WEB + " and sentora.bot : false", "Visitors",
                        {"operation": "unique_count", "field": "source.ip"})),
         (12, 5, metric("Not found (404)", WEB + " and http.response.status_code : 404", "Not found")),
         (12, 5, metric("Server errors (5xx)", WEB + " and sentora.status_class : \"5xx\"", "Errors"))],
        [(32, 12, bars("Requests by answer", WEB, "sentora.status_class", 5, "Requests")),
         (16, 12, pie("People and bots", WEB, "sentora.visitor", 2, "Requests"))],
        [(16, 14, table("Websites", WEB, ["sentora.domain"], 15, "Requests")),
         (16, 14, table("Most requested pages", WEB, ["sentora.domain", "url.path"], 15, "Requests")),
         (16, 14, table("Pages not found (404)", WEB + " and http.response.status_code : 404",
                        ["sentora.domain", "url.path"], 15, "Requests"))],
        [(16, 14, world("Visitors by country", WEB + " and sentora.bot : false", "Requests")),
         (16, 14, table("Bots and crawlers", WEB + " and sentora.bot : true", ["user_agent.name"], 15, "Requests")),
         (16, 14, table("Busiest addresses", WEB, ["source.ip"], 15, "Requests"))],
        [(24, 12, table("Error log lines by website", 'sentora.log : "web-error"', ["sentora.domain", "error.code"], 15,
                        "Lines")),
         (24, 12, table("Browsers", WEB + " and sentora.bot : false", ["user_agent.name"], 10, "Requests"))],
    ]),
    "sentora-mail": build("sentora-mail", "Mail", [
        [(12, 5, metric("Sent", 'sentora.mail.status : "sent"', "Mails")),
         (12, 5, metric("Bounced", 'sentora.mail.status : "bounced"', "Mails")),
         (12, 5, metric("Waiting to retry", 'sentora.mail.status : "deferred"', "Mails")),
         (12, 5, metric("Refused at the door", 'sentora.mail.status : "rejected"', "Attempts"))],
        [(32, 12, bars("Mail by result", "sentora.mail.status : *", "sentora.mail.status", 6, "Mails")),
         (16, 12, pie("Mailbox logins", 'event.action : "mail-login"', "event.outcome", 2, "Logins"))],
        [(16, 14, table("Where mail went", 'sentora.mail.status : "sent"', ["email.to.address"], 15, "Mails")),
         (16, 14, table("Not delivered", 'sentora.mail.status : ("bounced" or "deferred")',
                        ["email.to.address", "sentora.mail.status"], 15, "Mails")),
         (16, 14, table("Refused senders (addresses)", 'sentora.mail.status : ("rejected" or "login-failed")',
                        ["source.ip"], 15, "Attempts"))],
        [(24, 12, table("Mailbox logins that failed", 'event.action : "mail-login" and event.outcome : "failure"',
                        ["user.name", "source.ip"], 15, "Attempts")),
         (24, 12, table("Mailboxes in use", 'event.action : "mail-login" and event.outcome : "success"',
                        ["user.name"], 15, "Logins"))],
    ]),
    "sentora-security": build("sentora-security", "Security", [
        [(12, 5, metric("Failed logins", 'event.outcome : "failure" and event.action : *', "Attempts")),
         (12, 5, metric("Addresses trying", 'event.outcome : "failure" and event.action : *', "Addresses",
                        {"operation": "unique_count", "field": "source.ip"})),
         (12, 5, metric("Bans", 'sentora.log : "fail2ban" and event.action : "ban"', "Bans")),
         (12, 5, metric("Probes for weak spots", WEB + " and http.response.status_code : (403 or 404) and url.path : "
                        "(\"/wp-login.php\" or \"/xmlrpc.php\" or \"/.env\" or \"/.git/config\" or \"/phpmyadmin/\")",
                        "Probes"))],
        [(32, 12, bars("Failed logins by door", 'event.outcome : "failure" and event.action : *', "event.action", 8,
                       "Attempts")),
         (16, 12, pie("Bans by jail", 'sentora.log : "fail2ban" and event.action : "ban"', "rule.name", 8, "Bans"))],
        [(16, 14, world("Where the attempts come from", 'event.outcome : "failure" and event.action : *', "Attempts")),
         (16, 14, table("Addresses trying hardest", 'event.outcome : "failure" and event.action : *',
                        ["source.ip", "source.geo.country_name"], 15, "Attempts")),
         (16, 14, table("Names they try", 'event.outcome : "failure" and event.action : *',
                        ["event.action", "user.name"], 15, "Attempts"))],
        [(24, 12, table("Server logins that worked (SSH)", 'event.action : "ssh-login" and event.outcome : "success"',
                        ["user.name", "source.ip"], 10, "Logins")),
         (24, 12, table("Commands run as root (sudo)", 'event.action : "sudo"', ["user.name", "process.command_line"], 10,
                        "Times"))],
    ]),
    "sentora-panel": build("sentora-panel", "Panel and server", [
        [(12, 5, metric("Panel pages opened", 'sentora.log : "panel-access"', "Requests")),
         (12, 5, metric("Panel login failures", 'event.action : "panel-login" and event.outcome : "failure"', "Attempts")),
         (12, 5, metric("Jobs root ran for the panel", 'sentora.log : "zsudo" and event.action : "ran"', "Jobs")),
         (12, 5, metric("Problems noted", 'sentora.log : "problems"', "Lines"))],
        [(32, 12, bars("Panel and server logs", 'sentora.log : ("panel-access" or "panel-error" or "zsudo" or '
                       '"daemon" or "problems" or "dns" or "ftp" or "ftp-login")', "sentora.log", 8)),
         (16, 12, table("Root's jobs for the panel", 'sentora.log : "zsudo" and event.action : *',
                        ["event.action", "sentora.zsudo.job"], 10, "Times"))],
        [(24, 12, table("Panel pages", 'sentora.log : "panel-access"', ["url.path"], 15, "Requests")),
         (24, 12, table("FTP uploads and changes", 'sentora.log : "ftp"', ["user.name", "sentora.ftp.command"], 15,
                        "Actions"))],
        [(48, 3, note("Every line itself: **Discover**, data view *Sentora logs*, and filter on `sentora.log` "
                      "(web-access, web-error, panel-access, panel-error, panel-login, mail, dovecot, auth, ftp, ftp-login, "
                      "dns, fail2ban, zsudo, daemon, problems, webmail)."))],
    ]),
}

if __name__ == "__main__":
    here = os.path.dirname(os.path.abspath(__file__))
    out = os.path.join(here, "dashboards")
    os.makedirs(out, exist_ok=True)
    for did, body in BOARDS.items():
        with open(os.path.join(out, did + ".json"), "w") as f:
            json.dump(body, f, indent=1, sort_keys=True)
            f.write("\n")
    print("wrote", len(BOARDS), "dashboards")
