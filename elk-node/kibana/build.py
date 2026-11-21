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
    ("sentora-today", "Today"),
    ("sentora-serverwall", "Server wall"),
    ("sentora-overview", "Overview"),
    ("sentora-worldmap", "World map"),
    ("sentora-uptime", "Uptime"),
    ("sentora-websites", "Websites"),
    ("sentora-mail", "Mail"),
    ("sentora-delivery", "Mail delivery"),
    ("sentora-security", "Security"),
    ("sentora-attackers", "Attackers"),
    ("sentora-firewall", "Web firewall"),
    ("sentora-panel", "Panel and server"),
    ("sentora-health", "Server health"),
    ("sentora-speed", "Website speed"),
]


# column headings for the fields the tables group by
NAMES = {"sentora.domain": "Website", "url.path": "Page", "source.ip": "Address", "source.geo.country_name": "Country",
         "user_agent.name": "Browser or bot", "error.code": "Apache message", "email.to.address": "To",
         "sentora.mail.status": "Result", "user.name": "Name", "event.action": "What", "rule.name": "Jail",
         "process.command_line": "Command", "sentora.zsudo.job": "Job", "sentora.ftp.command": "FTP command",
         "sentora.log": "Kind of log", "sentora.visitor": "Visitor", "event.outcome": "Result",
         "sentora.status_class": "Answer", "rule.id": "Rule", "rule.name": "What it looks for", "sentora.waf.attack": "Attack",
         "email.to.domain": "Receiving domain", "email.from.address": "Sender", "sentora.mail.reason": "Reason",
         "sentora.mail.spam_rules": "Spam rule", "monitor.name": "Website", "monitor.status": "Status", "error.type": "Why",
         "url.full": "Address checked", "host.name": "Panel"}


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
            "group_by": [{"operation": "terms", "fields": [field], "limit": limit, "label": NAMES.get(field, field)}], "styling": {"donut_hole": "m"}}


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


OWN_TIME = {"use_time_range": False}  # (each board opens with its own time range: a wall's last hour, the week's 7 days)


def build(did, title, rows):
    """rows: lists of (width, height, panel); each row starts below the tallest panel of the row before."""
    links = {"layout": "horizontal", "links": [
        {"type": "dashboardLink", "destination": d, "label": t, "options": OWN_TIME} for d, t in DASHBOARDS]}
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
         (8, 5, metric("Failed logins", 'event.outcome : "failure" and event.action : * and not event.action : waf-*', "Failed logins")),
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
        [(12, 5, metric("Failed logins", 'event.outcome : "failure" and event.action : * and not event.action : waf-*', "Attempts")),
         (12, 5, metric("Addresses trying", 'event.outcome : "failure" and event.action : * and not event.action : waf-*', "Addresses",
                        {"operation": "unique_count", "field": "source.ip"})),
         (12, 5, metric("Bans", 'sentora.log : "fail2ban" and event.action : "ban"', "Bans")),
         (12, 5, metric("Probes for weak spots", WEB + " and http.response.status_code : (403 or 404) and url.path : "
                        "(\"/wp-login.php\" or \"/xmlrpc.php\" or \"/.env\" or \"/.git/config\" or \"/phpmyadmin/\")",
                        "Probes"))],
        [(32, 12, bars("Failed logins by door", 'event.outcome : "failure" and event.action : * and not event.action : waf-*', "event.action", 8,
                       "Attempts")),
         (16, 12, pie("Bans by jail", 'sentora.log : "fail2ban" and event.action : "ban"', "rule.name", 8, "Bans"))],
        [(16, 14, world("Where the attempts come from", 'event.outcome : "failure" and event.action : * and not event.action : waf-*', "Attempts")),
         (16, 14, table("Addresses trying hardest", 'event.outcome : "failure" and event.action : * and not event.action : waf-*',
                        ["source.ip", "source.geo.country_name"], 15, "Attempts")),
         (16, 14, table("Names they try", 'event.outcome : "failure" and event.action : * and not event.action : waf-*',
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

# ---- The world map (Kibana Maps; the dashboards API takes no map panel, so the installer adds it to the
# World map dashboard by reference with addmap.py): a dark world, where visitors come from as a heat map,
# attacks and failed logins as red circles with their count, countries shaded by visits.
PROBES = '("/wp-login.php" or "/xmlrpc.php" or "/.env" or "/.git/config" or "/phpmyadmin/" or "/admin/" or "/wp-admin/setup-config.php")'
BAD = ('(event.outcome : "failure" and event.action : *) or (sentora.log : "fail2ban" and event.action : "ban") '
       'or (sentora.log : "web-access" and http.response.status_code : (403 or 404) and url.path : ' + PROBES + ')')
PEOPLE = WEB + " and sentora.bot : false"


def kq(s):
    return {"query": s, "language": "kuery"}


MAP_LAYERS = [
    {"id": "world", "label": "World", "type": "EMS_VECTOR_TILE", "alpha": 1, "visible": True, "minZoom": 0, "maxZoom": 24,
     "sourceDescriptor": {"type": "EMS_TMS", "isAutoSelect": False, "id": "dark_map"},
     "style": {"type": "EMS_VECTOR_TILE", "color": ""}, "includeInFitToBounds": True},
    {"id": "countries", "label": "Visits by country", "type": "GEOJSON_VECTOR", "alpha": 0.45, "visible": True, "minZoom": 0, "maxZoom": 24,
     "sourceDescriptor": {"type": "EMS_FILE", "id": "world_countries", "tooltipProperties": ["name"]},
     "joins": [{"leftField": "iso2", "right": {"type": "ES_TERM_SOURCE", "id": "visits", "indexPatternRefName": "layer_1_join_0_index_pattern",
                "term": "source.geo.country_iso_code", "size": 250, "metrics": [{"type": "count", "label": "Visits"}],
                "applyGlobalQuery": True, "applyGlobalTime": True, "whereQuery": kq(PEOPLE)}}],
     "style": {"type": "VECTOR", "isTimeAware": True, "properties": {
         "fillColor": {"type": "DYNAMIC", "options": {"color": "Blues", "colorCategory": "palette_0", "type": "ORDINAL",
                       "field": {"name": "__kbnjoin__count__visits", "origin": "join"}, "fieldMetaOptions": {"isEnabled": True, "sigma": 3}}},
         "lineColor": {"type": "STATIC", "options": {"color": "#41506a"}}, "lineWidth": {"type": "STATIC", "options": {"size": 0.5}}}}},
    {"id": "visitors", "label": "Where visitors come from", "type": "HEATMAP", "alpha": 0.9, "visible": True, "minZoom": 0, "maxZoom": 24,
     "query": kq(PEOPLE),
     "sourceDescriptor": {"type": "ES_GEO_GRID", "id": "visitors", "indexPatternRefName": "layer_2_source_index_pattern",
                          "geoField": "source.geo.location", "requestType": "heatmap", "resolution": "FINE", "metrics": [{"type": "count"}],
                          "applyGlobalQuery": True, "applyGlobalTime": True},
     "style": {"type": "HEATMAP", "colorRampName": "theclassic"}},
    {"id": "attacks", "label": "Attacks and failed logins", "type": "GEOJSON_VECTOR", "alpha": 0.9, "visible": True, "minZoom": 0, "maxZoom": 24,
     "query": kq(BAD),
     "sourceDescriptor": {"type": "ES_GEO_GRID", "id": "attacks", "indexPatternRefName": "layer_3_source_index_pattern",
                          "geoField": "source.geo.location", "requestType": "point", "resolution": "COARSE",
                          "metrics": [{"type": "count", "label": "Attempts"}], "applyGlobalQuery": True, "applyGlobalTime": True},
     "style": {"type": "VECTOR", "isTimeAware": True, "properties": {
         "fillColor": {"type": "STATIC", "options": {"color": "#f04e3e"}},
         "lineColor": {"type": "STATIC", "options": {"color": "#ffd8d0"}}, "lineWidth": {"type": "STATIC", "options": {"size": 1.5}},
         "iconSize": {"type": "DYNAMIC", "options": {"minSize": 7, "maxSize": 28, "field": {"name": "doc_count", "origin": "source"},
                      "fieldMetaOptions": {"isEnabled": True, "sigma": 3}}},
         "labelText": {"type": "DYNAMIC", "options": {"field": {"name": "doc_count", "origin": "source"}}},
         "labelColor": {"type": "STATIC", "options": {"color": "#ffffff"}}, "labelSize": {"type": "STATIC", "options": {"size": 11}}}}},
]
WORLD_MAP = {
    "attributes": {
        "title": "Sentora: World map",
        "description": "Where the hits come from (heat), attacks and failed logins (red), visits by country. Made by the Sentora Elk node installer.",
        "layerListJSON": json.dumps(MAP_LAYERS),
        "mapStateJSON": json.dumps({"zoom": 1.4, "center": {"lon": 10, "lat": 25}, "timeFilters": {"from": "now-24h", "to": "now"},
                                    "refreshConfig": {"isPaused": False, "interval": 30000}, "query": kq(""), "filters": [],
                                    "settings": {"autoFitToDataBounds": False, "backgroundColor": "#0b1626"}}),
        "uiStateJSON": json.dumps({"isLayerTOCOpen": True, "openTOCDetails": []}),
    },
    "references": [{"name": n, "type": "index-pattern", "id": "sentora-logs"} for n in
                   ("layer_1_join_0_index_pattern", "layer_2_source_index_pattern", "layer_3_source_index_pattern")],
}
# The World map dashboard: room for the map on the left (added by addmap.py at x 0, y 2, 34 x 30), live figures on the right
WORLD_PANELS = []
for i, (title, kql, op) in enumerate([
        ("Visitors", PEOPLE, {"operation": "unique_count", "field": "source.ip"}),
        ("Countries", PEOPLE, {"operation": "unique_count", "field": "source.geo.country_iso_code"}),
        ("Attacks and failed logins", BAD, None),
        ("Addresses banned", 'sentora.log : "fail2ban" and event.action : "ban"', None)]):
    WORLD_PANELS.append((34 + (i % 2) * 7, 2 + (i // 2) * 5, 7, 5, metric(title, kql, "", op)))
WORLD_PANELS.append((34, 12, 14, 10, table("Visitors by country", PEOPLE, ["source.geo.country_name"], 10, "Visits")))
WORLD_PANELS.append((34, 22, 14, 10, table("Attacks by country", BAD, ["source.geo.country_name"], 10, "Attempts")))


def build_free(title, placed, refresh=30000):
    links = {"layout": "horizontal", "links": [{"type": "dashboardLink", "destination": d, "label": t} for d, t in DASHBOARDS]}
    panels = [{"type": "links", "grid": {"x": 0, "y": 0, "w": 48, "h": 2}, "config": links}]
    for x, y, w, h, cfg in placed:
        panels.append({"type": "vis", "grid": {"x": x, "y": y, "w": w, "h": h}, "config": cfg})
    return {"title": "Sentora: " + title, "description": "Made by the Sentora Elk node installer; a copy with another name keeps your own changes.",
            "time_range": {"from": "now-24h", "to": "now"}, "refresh_interval": {"pause": False, "value": refresh}, "panels": panels}


BOARDS["sentora-worldmap"] = build_free("World map", WORLD_PANELS)

# ---- Web firewall: what ModSecurity (Web Hardening) caught, from the websites' error logs
WAF = 'event.module : "modsecurity"'
WAF_STOP = 'event.action : ("waf-blocked" or "waf-watched")'
WAF_RULE = 'event.action : "waf-rule"'
BOARDS["sentora-firewall"] = build("sentora-firewall", "Web firewall", [
    [(12, 5, metric("Requests stopped", 'event.action : "waf-blocked"', "")),
     (12, 5, metric("Would be stopped (watch only)", 'event.action : "waf-watched"', "")),
     (12, 5, metric("Addresses caught", WAF_STOP, "", {"operation": "unique_count", "field": "source.ip"})),
     (12, 5, metric("Websites attacked", WAF_STOP, "", {"operation": "unique_count", "field": "sentora.domain"}))],
    [(32, 12, bars("Caught requests by verdict", WAF_STOP, "event.action", 2, "Requests")),
     (16, 12, pie("Kinds of attack", WAF_RULE, "sentora.waf.attack", 8, "Rule matches"))],
    [(16, 14, world("Where the attacks come from", WAF_STOP, "Requests")),
     (16, 14, table("Rules that matched most", WAF_RULE, ["rule.id", "rule.name"], 15, "Matches")),
     (16, 14, table("Websites and pages attacked", WAF_STOP, ["sentora.domain", "url.path"], 15, "Requests"))],
    [(24, 12, table("Addresses caught most", WAF_STOP, ["source.ip", "source.geo.country_name"], 15, "Requests")),
     (24, 12, note("A request is **stopped** when its rule matches add up to Web Hardening's limit (**watched** in watch-only "
                   "mode: it would have been). Each rule a request matched counts under *Rules that matched most*. "
                   "A rule that stops your own visitors by mistake can be left alone in Server Admin > Web Hardening."))],
])

# ---- Mail delivery: where mail goes, what comes back, spam scores, who sends
SENT = 'sentora.mail.status : "sent"'
BOARDS["sentora-delivery"] = build("sentora-delivery", "Mail delivery", [
    [(12, 5, metric("Delivered", SENT, "")),
     (12, 5, metric("Bounced", 'sentora.mail.status : "bounced"', "")),
     (12, 5, metric("Spam caught", 'sentora.mail.status : "spam"', "")),
     (12, 5, metric("Receiving domains", SENT, "", {"operation": "unique_count", "field": "email.to.domain"}))],
    [(32, 12, bars("Delivery results", 'sentora.mail.status : ("sent" or "bounced" or "deferred" or "expired")', "sentora.mail.status", 4, "Mails")),
     (16, 12, pie("Spam check", 'sentora.mail.status : ("spam" or "clean")', "sentora.mail.status", 2, "Mails"))],
    [(16, 14, table("Bounced or waiting, by receiving domain", 'sentora.mail.status : ("bounced" or "deferred" or "expired")',
                    ["email.to.domain", "sentora.mail.status"], 15, "Mails")),
     (16, 14, table("Why mail came back", 'sentora.mail.status : ("bounced" or "deferred")', ["sentora.mail.reason"], 15, "Mails")),
     (16, 14, table("Where mail went", SENT, ["email.to.domain"], 15, "Mails"))],
    [(16, 14, table("Who sends most", 'email.from.address : *', ["email.from.address"], 15, "Mails")),
     (16, 14, table("Mailboxes getting spam", 'sentora.mail.status : "spam"', ["user.name"], 15, "Spam")),
     (16, 14, table("Spam rules that fired most", 'sentora.mail.status : "spam"', ["sentora.mail.spam_rules"], 15, "Times"))],
])

# ---- Uptime: the node's Heartbeat checks every website from outside, every minute (data view sentora-uptime, heartbeat-*)
UPDV = {"type": "data_view_reference", "ref_id": "sentora-uptime"}


def up(cfg):
    """The same chart on the uptime checks' data view."""
    if "layers" in cfg:
        for layer in cfg["layers"]:
            layer["data_source"] = UPDV
    else:
        cfg["data_source"] = UPDV
    return cfg


DOWN = 'monitor.status : "down"'
SITE = 'tags : "sentora-site"'
BOARDS["sentora-uptime"] = build("sentora-uptime", "Uptime", [
    [(12, 5, up(metric("Websites watched", SITE, "", {"operation": "unique_count", "field": "monitor.name"}))),
     (12, 5, up({"type": "metric", "title": "Available", "data_source": UPDV, "query": q(SITE),
                 "metrics": [{"type": "primary", "operation": "formula", "label": "of the checks answered",
                              "formula": "count(kql='monitor.status : \"up\"') / count()", "format": {"type": "percent"}}]})),
     (12, 5, up(metric("Checks that failed", SITE + " and " + DOWN, ""))),
     (12, 5, up(metric("Websites with a failed check", SITE + " and " + DOWN, "", {"operation": "unique_count", "field": "monitor.name"})))],
    [(32, 12, up(bars("Checks: up and down", SITE, "monitor.status", 2, "Checks"))),
     (16, 12, up(table("Failed checks by website", SITE + " and " + DOWN, ["monitor.name"], 15, "Failed")))],
    [(48, 12, up({"type": "xy", "title": "How fast each website answers (milliseconds)", "query": q(SITE),
                  "legend": {"visibility": "visible", "position": "right"},
                  "layers": [{"type": "line", "data_source": UPDV, "x": {"operation": "date_histogram", "field": "@timestamp"},
                              "y": [{"operation": "formula", "formula": "average(monitor.duration.us) / 1000", "label": "ms"}],
                              "breakdown_by": {"operation": "terms", "fields": ["monitor.name"], "limit": 10, "label": "Website",
                                               "rank_by": {"type": "alphabetical", "direction": "asc"}}}]}))],
    [(24, 14, up({"type": "data_table", "title": "Certificates: when they expire", "data_source": UPDV, "query": q(SITE + " and tls.server.x509.not_after : *"),
                  "metrics": [{"operation": "min", "field": "tls.server.x509.not_after", "label": "Expires"}],
                  "rows": [{"operation": "terms", "fields": ["monitor.name"], "limit": 50, "label": "Website"}]})),
     (24, 14, up(table("Why checks failed", SITE + " and " + DOWN, ["monitor.name", "error.type"], 15, "Times")))],
    [(48, 3, note("Checked from the Elk node every minute: a website is **up** when it answers (an error page such as 404 counts as "
                  "an answer; 5xx and no answer do not) and its certificate is valid. Kibana's own **Uptime** app (Observability) "
                  "shows the same checks with each one's history."))],
])

# ---- Server health: the panel's own figures (Log Forwarder 207+, every daemon run: 5 minutes), one line per panel
HEALTH = 'sentora.log : "health"'


def avg(title, field, label, fmt=None):
    m = {"type": "primary", "operation": "average", "field": "sentora.health." + field, "label": label,
         "format": fmt or {"type": "number", "decimals": 1}}
    return {"type": "metric", "title": title, "data_source": DV, "query": q(HEALTH), "metrics": [m]}


def lines(title, ys, fmt=None, per_panel=True):
    """A line chart over time of sentora.health figures: ys = [(field, label)]; one line per panel when one figure."""
    y = []
    for f, label in ys:
        d = {"operation": "average", "field": "sentora.health." + f, "label": label}
        if fmt:
            d["format"] = fmt
        y.append(d)
    layer = {"type": "line", "data_source": DV, "x": {"operation": "date_histogram", "field": "@timestamp"}, "y": y}
    if per_panel and len(ys) == 1:
        layer["breakdown_by"] = {"operation": "terms", "fields": ["host.name"], "limit": 10, "label": "Panel",
                                 "rank_by": {"type": "alphabetical", "direction": "asc"}}
    return {"type": "xy", "title": title, "query": q(HEALTH), "layers": [layer],
            "legend": {"visibility": "visible", "position": "right"}}


BYTES = {"type": "bytes"}
BOARDS["sentora-health"] = build("sentora-health", "Server health", [
    [(12, 5, avg("CPU busy", "cpu_pct", "% (average)")),
     (12, 5, avg("Memory used", "mem_used_pct", "% (average)")),
     (12, 5, avg("Disk used (websites)", "disk_sites_used_pct", "% (average)")),
     (12, 5, avg("Mail waiting to go", "mail_queue", "mails (average)"))],
    [(24, 12, lines("CPU busy (%)", [("cpu_pct", "CPU %")])),
     (24, 12, lines("Load (1 minute)", [("load1", "Load")]))],
    [(24, 12, lines("Memory and swap used (%)", [("mem_used_pct", "Memory %"), ("swap_used_pct", "Swap %")])),
     (24, 12, lines("Disk used (%)", [("disk_root_used_pct", "System %"), ("disk_sites_used_pct", "Websites %")]))],
    [(24, 12, lines("Network (bytes a second)", [("net_rx_bps", "In"), ("net_tx_bps", "Out")], BYTES)),
     (24, 12, lines("Apache: requests a second", [("apache_rps", "Requests/s")]))],
    [(24, 12, lines("Apache workers", [("apache_busy", "Busy"), ("apache_idle", "Idle")])),
     (24, 12, lines("MySQL: queries a second and connections", [("mysql_qps", "Queries/s"), ("mysql_threads", "Connections")]))],
    [(24, 12, lines("MySQL: slow queries a minute", [("mysql_slow_per_min", "Slow/min")])),
     (24, 12, lines("Mail waiting to go", [("mail_queue", "Mails")]))],
    [(48, 3, note("Sent by the panel on every daemon run (5 minutes) when **Server health** is ticked on Log Forwarder's page. "
                  "CPU and network are the averages since the run before; with several panels each has its own line."))],
])

# ---- Website speed: how long each page took (the panel's Apache timing log, kind web-timing; Log Forwarder 209+)
TIMED = 'sentora.log : "web-timing"'
MS = {"type": "number", "decimals": 0}


def ms_table(title, kql, rows, limit=15):
    return {"type": "data_table", "title": title, "data_source": DV, "query": q(kql),
            "metrics": [{"operation": "average", "field": "sentora.response_ms", "label": "Average ms", "format": MS},
                        {"operation": "percentile", "field": "sentora.response_ms", "percentile": 95, "label": "95% within ms", "format": MS},
                        {"operation": "count", "label": "Requests"}],
            "rows": [{"operation": "terms", "fields": [f], "limit": limit, "label": NAMES.get(f, f),
                      "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}} for f in rows]}


BOARDS["sentora-speed"] = build("sentora-speed", "Website speed", [
    [(12, 5, {"type": "metric", "title": "Half the pages within", "data_source": DV, "query": q(TIMED),
              "metrics": [{"type": "primary", "operation": "median", "field": "sentora.response_ms", "label": "ms", "format": MS}]}),
     (12, 5, {"type": "metric", "title": "95 in 100 pages within", "data_source": DV, "query": q(TIMED),
              "metrics": [{"type": "primary", "operation": "percentile", "field": "sentora.response_ms", "percentile": 95, "label": "ms", "format": MS}]}),
     (12, 5, metric("Slow pages (over 2 seconds)", TIMED + " and sentora.response_ms >= 2000", "")),
     (12, 5, metric("Server errors (5xx)", TIMED + ' and sentora.status_class : "5xx"', ""))],
    [(48, 12, {"type": "xy", "title": "How long pages take, by website (average, ms)", "query": q(TIMED),
               "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "line", "data_source": DV, "x": {"operation": "date_histogram", "field": "@timestamp"},
                           "y": [{"operation": "average", "field": "sentora.response_ms", "label": "ms", "format": MS}],
                           "breakdown_by": {"operation": "terms", "fields": ["sentora.domain"], "limit": 8, "label": "Website",
                                            "rank_by": {"type": "alphabetical", "direction": "asc"}}}]})],
    [(24, 14, ms_table("Slowest pages", TIMED, ["url.path"])),
     (24, 14, ms_table("Slowest websites", TIMED, ["sentora.domain"]))],
    [(24, 12, bars("Slow pages over time (over 2 seconds)", TIMED + " and sentora.response_ms >= 2000", "sentora.domain", 6, "Pages")),
     (24, 12, table("Pages with server errors (5xx)", TIMED + ' and sentora.status_class : "5xx"', ["sentora.domain", "url.path"], 15, "Errors"))],
    [(48, 3, note("How long the server took for each request, from the panel's Apache (the time until the last byte went out). "
                  "Pages are shown without what follows the ?, so no search words or tokens are kept. For a shop: the checkout and "
                  "cart pages are the ones to watch."))],
])

# ---- Other sources (each off until switched on with sentora-elk-node): FortiGate, Veeam, Microsoft 365, the shop.
# Their own links bar; "Overview" leads back to the panel's boards.
SOURCES = [("sentora-today", "Today"), ("sentora-week", "Week"), ("sentora-flows", "Flows"), ("sentora-serverwall", "Server wall"), ("sentora-backupwall", "Backup wall"),
           ("sentora-netwall", "Network wall"), ("sentora-secwall", "Security wall"), ("sentora-m365wall", "Microsoft 365 wall"), ("sentora-saleswall", "Sales wall"), ("sentora-adwall", "AD wall"),
           ("sentora-overview", "Panel: Overview"), ("sentora-attackers", "Attackers"),
           ("sentora-fortigate", "FortiGate: web"), ("sentora-vpn", "FortiGate: VPN"), ("sentora-threats", "FortiGate: threats"),
           ("sentora-internet", "Internet use"), ("sentora-veeam", "Veeam backups"), ("sentora-coverage", "Backup coverage"),
           ("sentora-ad", "Active Directory"), ("sentora-m365", "Microsoft 365"), ("sentora-shop", "Online store"), ("sentora-funnel", "Store funnel")]
FGT = {"type": "data_view_reference", "ref_id": "sentora-fortigate"}
VEEAM = {"type": "data_view_reference", "ref_id": "sentora-veeam"}
M365 = {"type": "data_view_reference", "ref_id": "sentora-m365"}
NAMES.update({"url.domain": "Site", "fortigate.category": "Category", "network.application": "App", "fortigate.appcat": "Kind of app",
              "fortigate.threat": "Threat", "fortigate.vpn.tunnel": "Kind of tunnel", "fortigate.vpn.name": "Tunnel", "source.geo.country_name": "Country", "source.geo.country_iso_code": "Country", "observer.name": "Device",
              "veeam_job.name": "Job", "veeam_job.why": "Why", "m365.reason": "Why", "veeam_repo.name": "Repository", "veeam_job.result": "Result", "host.name": "Machine", "m365.app": "App", "error.message": "Why",
              "m365.issue.service": "Service", "m365.issue.title": "Problem", "m365.client_app": "Client", "fortigate.subtype": "Kind",
              "sentora.shop.status": "Status", "sentora.shop.country": "Country", "sentora.shop.sku": "Product", "sentora.search.term": "Searched for",
              "sentora.shop.term": "Searched for", "sentora.store.step": "Step", "data_stream.dataset": "Seen by", "sentora.shop.product": "Product", "sentora.shop.status": "Status"})


def on(dv, cfg):
    """The same chart on another data view."""
    if "layers" in cfg:
        for layer in cfg["layers"]:
            layer["data_source"] = dv
    else:
        cfg["data_source"] = dv
    return cfg


def build_src(did, title, rows):
    b = build(did, title, rows)
    b["panels"][0]["config"] = {"layout": "horizontal", "links": [{"type": "dashboardLink", "destination": d, "label": t, "options": OWN_TIME} for d, t in SOURCES]}
    return b


def sum_table(title, dv, kql, rows, field, label, limit=15, fmt=None):
    m = {"operation": "sum", "field": field, "label": label}
    if fmt:
        m["format"] = fmt
    return {"type": "data_table", "title": title, "data_source": dv, "query": q(kql), "metrics": [m, {"operation": "count", "label": "Lines"}],
            "rows": [{"operation": "terms", "fields": [f], "limit": limit, "label": NAMES.get(f, f),
                      "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}} for f in rows]}


WEBF = 'fortigate.subtype : ("webfilter" or "forward") and url.domain : *'
BOARDS["sentora-fortigate"] = build_src("sentora-fortigate", "FortiGate: where people go", [
    [(12, 5, on(FGT, metric("Sites visited", WEBF, "", {"operation": "unique_count", "field": "url.domain"}))),
     (12, 5, on(FGT, metric("People", WEBF + " and user.name : *", "", {"operation": "unique_count", "field": "user.name"}))),
     (12, 5, on(FGT, metric("Sites blocked", 'fortigate.subtype : "webfilter" and event.outcome : "failure"', ""))),
     (12, 5, on(FGT, metric("Data moved", "network.bytes : *", "", {"operation": "sum", "field": "network.bytes", "format": {"type": "bytes"}})))],
    [(24, 14, on(FGT, table("Most visited sites", WEBF, ["url.domain"], 20, "Visits"))),
     (24, 14, on(FGT, table("Who went where", WEBF + " and user.name : *", ["user.name", "url.domain"], 20, "Visits")))],
    [(16, 12, on(FGT, pie("Web categories", 'fortigate.category : * and fortigate.subtype : "webfilter"', "fortigate.category", 8, "Visits"))),
     (16, 12, on(FGT, table("Blocked sites", 'fortigate.subtype : "webfilter" and event.outcome : "failure"', ["url.domain", "fortigate.category"], 15, "Times"))),
     (16, 12, on(FGT, table("Blocked, by person", 'fortigate.subtype : "webfilter" and event.outcome : "failure" and user.name : *', ["user.name"], 15, "Times")))],
    [(24, 14, sum_table("Bandwidth by person", FGT, "network.bytes : * and user.name : *", ["user.name"], "network.bytes", "Data", 15, {"type": "bytes"})),
     (24, 14, sum_table("Apps", FGT, "network.application : *", ["network.application"], "network.bytes", "Data", 15, {"type": "bytes"}))],
    [(48, 12, on(FGT, bars("Web traffic over time, by category", 'fortigate.category : *', "fortigate.category", 8, "Visits")))],
    [(48, 3, note("From the FortiGate's logs (sentora-elk-node fortigate). Sites come from the web filter and from traffic logs with a "
                  "host name; a web filter profile in monitor mode on the outgoing policy logs every site without blocking any."))],
])

VPNQ = 'event.category : "vpn" and not fortigate.vpn.kind : "site-to-site"'
SITEQ = 'event.category : "vpn" and fortigate.vpn.kind : "site-to-site"'
BOARDS["sentora-vpn"] = build_src("sentora-vpn", "FortiGate: VPN and FortiClient", [
    [(12, 5, on(FGT, metric("Connected now (last 15 minutes)", VPNQ + ' and event.action : ("tunnel-up" or "tunnel-stats") and @timestamp >= "now-15m"', "",
                            {"operation": "unique_count", "field": "user.name"}))),
     (12, 5, on(FGT, metric("Connections", VPNQ + ' and event.action : "tunnel-up"', ""))),
     (12, 5, on(FGT, metric("Failed VPN logins", VPNQ + ' and event.outcome : "failure"', ""))),
     (12, 5, on(FGT, metric("People who connected", VPNQ + ' and event.action : "tunnel-up"', "", {"operation": "unique_count", "field": "user.name"})))],
    [(24, 14, on(FGT, table("Connected in the last 15 minutes", VPNQ + ' and event.action : ("tunnel-up" or "tunnel-stats") and @timestamp >= "now-15m"',
                            ["user.name", "source.ip"], 25, "Updates"))),
     (24, 14, on(FGT, world("Where VPN users connect from", VPNQ, "Lines")))],
    [(24, 12, on(FGT, bars("Connections over time", VPNQ + ' and event.action : ("tunnel-up" or "tunnel-down")', "event.action", 2, "Times"))),
     (24, 12, on(FGT, table("Failed VPN logins", VPNQ + ' and event.outcome : "failure"', ["user.name", "source.ip"], 15, "Times")))],
    [(24, 12, sum_table("Data over VPN, by person", FGT, VPNQ + ' and event.action : "tunnel-down"', ["user.name"], "network.bytes", "Data", 15, {"type": "bytes"})),
     (24, 12, on(FGT, pie("Kind of tunnel", VPNQ + ' and event.action : "tunnel-up"', "fortigate.vpn.tunnel", 4, "Connections")))],
    [(48, 12, {"type": "data_table", "title": "Site-to-site tunnels (to other offices)", "data_source": FGT, "query": q(SITEQ),
               "metrics": [{"operation": "max", "field": "@timestamp", "label": "Last seen up"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "event.action", "label": "Last event"}],
               "rows": [{"operation": "terms", "fields": ["fortigate.vpn.name"], "limit": 30, "label": "Tunnel"},
                        {"operation": "terms", "fields": ["source.ip"], "limit": 3, "label": "Other end"}]})],
])

BOARDS["sentora-threats"] = build_src("sentora-threats", "FortiGate: threats", [
    [(12, 5, on(FGT, metric("Attacks stopped (IPS)", 'fortigate.subtype : "ips"', ""))),
     (12, 5, on(FGT, metric("Viruses stopped", 'fortigate.subtype : "virus"', ""))),
     (12, 5, on(FGT, metric("Botnet look-ups blocked", 'fortigate.subtype : "dns" and fortigate.threat : *', ""))),
     (12, 5, on(FGT, metric("Failed admin logins", 'event.category : "admin-login" and event.outcome : "failure"', "")))],
    [(24, 14, on(FGT, table("Attacks", 'fortigate.subtype : "ips"', ["fortigate.threat"], 15, "Times"))),
     (24, 14, on(FGT, world("Where attacks come from", 'fortigate.subtype : ("ips" or "anomaly") or (event.category : "admin-login" and event.outcome : "failure")', "Times")))],
    [(16, 12, on(FGT, table("Viruses", 'fortigate.subtype : "virus"', ["fortigate.threat", "user.name"], 15, "Times"))),
     (16, 12, on(FGT, table("Botnet look-ups, by machine", 'fortigate.subtype : "dns" and fortigate.threat : *', ["source.ip", "fortigate.threat"], 15, "Times"))),
     (16, 12, on(FGT, table("Admin logins", 'event.category : "admin-login"', ["user.name", "event.outcome", "source.ip"], 15, "Times")))],
    [(48, 12, on(FGT, bars("Threats over time", 'fortigate.subtype : ("ips" or "virus" or "dns" or "anomaly") and fortigate.threat : *', "fortigate.subtype", 4, "Times")))],
])

FIN = 'event.action : "job-finished"'
BOARDS["sentora-veeam"] = build_src("sentora-veeam", "Veeam backups", [
    [(12, 5, on(VEEAM, metric("Jobs run", FIN, ""))),
     (12, 5, on(VEEAM, metric("Failed", FIN + ' and veeam_job.result : "Failed"', ""))),
     (12, 5, on(VEEAM, metric("With warnings", FIN + ' and veeam_job.result : "Warning"', ""))),
     (12, 5, on(VEEAM, metric("Data moved", "veeam_job.transferred_gb : *", "", {"operation": "sum", "field": "veeam_job.transferred_gb", "label": "GB"})))],
    [(32, 12, on(VEEAM, bars("Job results over time", FIN, "veeam_job.result", 3, "Jobs"))),
     (16, 12, on(VEEAM, pie("Results", FIN, "veeam_job.result", 3, "Jobs")))],
    [(24, 14, {"type": "data_table", "title": "Each job: last run and its result", "data_source": VEEAM, "query": q(FIN),
               "metrics": [{"operation": "max", "field": "@timestamp", "label": "Last run"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "veeam_job.result", "label": "Last result"},
                           {"operation": "average", "field": "veeam_job.duration_min", "label": "Minutes (average)", "format": {"type": "number", "decimals": 0}}],
               "rows": [{"operation": "terms", "fields": ["veeam_job.name"], "limit": 50, "label": "Job"}]}),
     (24, 14, on(VEEAM, table("Failed or warned, and why", FIN + ' and veeam_job.result : ("Failed" or "Warning")', ["veeam_job.name", "veeam_job.why"], 15, "Times")))],
    [(24, 12, on(VEEAM, table("Malware detection", 'event.action : "malware-detected"', ["host.name"], 15, "Times"))),
     (24, 12, on(VEEAM, table("Console logins", 'event.action : "console-login"', ["user.name"], 15, "Times")))],
    [(48, 3, note("From Veeam Backup & Replication's events (sentora-elk-node veeam). A failed job is also an alert in the panel."))],
])

SIGN = 'm365.kind : "signin"'
BOARDS["sentora-m365"] = build_src("sentora-m365", "Microsoft 365", [
    [(12, 5, on(M365, metric("Sign-ins", SIGN, ""))),
     (12, 5, on(M365, metric("Failed sign-ins", SIGN + ' and event.outcome : "failure"', ""))),
     (12, 5, on(M365, metric("People signing in", SIGN, "", {"operation": "unique_count", "field": "user.name"}))),
     (12, 5, on(M365, metric("Microsoft problems open", 'm365.kind : "service-issue" and @timestamp >= "now-2h"', "",
                             {"operation": "unique_count", "field": "m365.issue.id"})))],
    [(24, 14, on(M365, world("Where people sign in from", SIGN, "Sign-ins"))),
     (24, 14, on(M365, table("Failed sign-ins and why", SIGN + ' and event.outcome : "failure"', ["user.name", "m365.reason"], 15, "Times")))],
    [(16, 12, on(M365, pie("Apps signed in to", SIGN, "m365.app", 8, "Sign-ins"))),
     (16, 12, on(M365, pie("With MFA", SIGN + " and m365.interactive : true", "m365.mfa", 2, "Sign-ins"))),
     (16, 12, on(M365, table("Sign-ins from abroad (not US)", SIGN + ' and not source.geo.country_iso_code : "US"', ["source.geo.country_iso_code", "user.name"], 15, "Sign-ins")))],
    [(24, 12, {"type": "xy", "title": "Teams per day", "query": q('m365.kind : "teams-day"'), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": M365, "x": {"operation": "date_histogram", "field": "@timestamp"},
                           "y": [{"operation": "sum", "field": "m365.teams.private_chat", "label": "Chat messages"},
                                 {"operation": "sum", "field": "m365.teams.team_chat", "label": "Channel messages"},
                                 {"operation": "sum", "field": "m365.teams.calls", "label": "Calls"},
                                 {"operation": "sum", "field": "m365.teams.meetings", "label": "Meetings"}]}]}),
     (24, 12, {"type": "xy", "title": "Mail per day", "query": q('m365.kind : "mail-day"'), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": M365, "x": {"operation": "date_histogram", "field": "@timestamp"},
                           "y": [{"operation": "sum", "field": "m365.mail.sent", "label": "Sent"},
                                 {"operation": "sum", "field": "m365.mail.received", "label": "Received"}]}]})],
    [(24, 14, {"type": "data_table", "title": "Teams by person (last 7 days)", "data_source": M365, "query": q('m365.kind : "teams-user"'),
               "metrics": [{"operation": "last_value", "time_field": "@timestamp", "field": "m365.teams.private_chat", "label": "Chats"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "m365.teams.calls", "label": "Calls"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "m365.teams.meetings", "label": "Meetings"}],
               "rows": [{"operation": "terms", "fields": ["user.name"], "limit": 50, "label": "Person"}]}),
     (24, 14, {"type": "data_table", "title": "Mail by person (last 7 days)", "data_source": M365, "query": q('m365.kind : "mail-user"'),
               "metrics": [{"operation": "last_value", "time_field": "@timestamp", "field": "m365.mail.sent", "label": "Sent"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "m365.mail.received", "label": "Received"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "m365.mail.read", "label": "Read"}],
               "rows": [{"operation": "terms", "fields": ["user.name"], "limit": 50, "label": "Person"}]})],
    [(48, 12, {"type": "data_table", "title": "People signing in from more than one country", "data_source": M365,
               "query": q(SIGN + ' and event.outcome : "success" and source.geo.country_iso_code : *'),
               "metrics": [{"operation": "unique_count", "field": "source.geo.country_iso_code", "label": "Countries"}, {"operation": "count", "label": "Sign-ins"}],
               "rows": [{"operation": "terms", "fields": ["user.name"], "limit": 15, "label": "Person", "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}},
                        {"operation": "terms", "fields": ["source.geo.country_iso_code"], "limit": 5, "label": "Country"}]})],
    [(48, 10, on(M365, table("Microsoft 365 problems open now", 'm365.kind : "service-issue" and @timestamp >= "now-2h"', ["m365.issue.service", "m365.issue.title"], 20, "Seen")))],
    [(48, 3, note("Read from Microsoft Graph every 5 minutes (sign-ins, service problems) and every 6 hours (Teams and mail, Microsoft's "
                  "daily reports, a day or two behind) by sentora-elk-node m365. Sign-ins need Entra ID P1 or P2."))],
])

SHOP = {"type": "data_view_reference", "ref_id": "sentora-shop"}
ORD = 'sentora.shop.kind : "order" and not sentora.shop.state : ("canceled" or "closed")'
MONEY = {"type": "number", "decimals": 2}
BOARDS["sentora-shop"] = build_src("sentora-shop", "Online store", [
    [(12, 5, on(SHOP, metric("Orders", ORD, ""))),
     (12, 5, on(SHOP, metric("Sales", ORD, "", {"operation": "sum", "field": "sentora.shop.base_total", "format": MONEY}))),
     (12, 5, on(SHOP, metric("Average order", ORD, "", {"operation": "average", "field": "sentora.shop.base_total", "format": MONEY}))),
     (12, 5, on(DV, metric("Searches on the site", "sentora.search.term : *", "")))],
    [(32, 12, {"type": "xy", "title": "Sales over time", "query": q(ORD), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": SHOP, "x": {"operation": "date_histogram", "field": "@timestamp"},
                           "y": [{"operation": "sum", "field": "sentora.shop.base_total", "label": "Sales", "format": MONEY}],
                           "breakdown_by": {"operation": "terms", "fields": ["sentora.shop.country"], "limit": 6, "label": "Country"}}]}),
     (16, 12, on(SHOP, pie("Order status", 'sentora.shop.kind : "order"', "sentora.shop.status", 8, "Orders")))],
    [(24, 14, sum_table("Best-selling products", SHOP, 'sentora.shop.kind : "item" and not sentora.shop.status : ("canceled" or "closed")',
                        ["sentora.shop.product"], "sentora.shop.qty", "Sold", 20)),
     (24, 14, sum_table("Sales by country", SHOP, ORD + " and sentora.shop.country : *", ["sentora.shop.country"], "sentora.shop.base_total", "Sales", 20, MONEY))],
    [(16, 14, on(DV, table("What visitors search for (from the visits)", "sentora.search.term : *", ["sentora.search.term"], 20, "Searches"))),
     (16, 14, {"type": "data_table", "title": "Magento's searches: most searched", "data_source": SHOP, "query": q('sentora.shop.kind : "search"'),
               "metrics": [{"operation": "max", "field": "sentora.shop.popularity", "label": "Times"},
                           {"operation": "max", "field": "sentora.shop.results", "label": "Results"}],
               "rows": [{"operation": "terms", "fields": ["sentora.shop.term"], "limit": 20, "label": "Searched for",
                         "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]}),
     (16, 14, {"type": "data_table", "title": "Searches that found nothing", "data_source": SHOP, "query": q('sentora.shop.kind : "search" and sentora.shop.results : 0'),
               "metrics": [{"operation": "max", "field": "sentora.shop.popularity", "label": "Times"}],
               "rows": [{"operation": "terms", "fields": ["sentora.shop.term"], "limit": 20, "label": "Searched for",
                         "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]})],
    [(24, 12, on(DV, bars("Store visits over time (people and bots)", WEB + " and sentora.domain : *", "sentora.visitor", 2, "Visits"))),
     (24, 12, on(DV, world("Where searchers come from", "sentora.search.term : *", "Searches")))],
    [(48, 3, note("Orders, products and Magento's search list come from the store's database on the panel (Log Forwarder > Elk node > "
                  "Online store); no customer names, e-mail or street addresses. Searches \"from the visits\" are read from the web logs. "
                  "Set the time range (top right) to see older orders. The visits chart is for every website: filter by Website to see the store's."))],
])
BOARDS["sentora-shop"]["time_range"] = {"from": "now-30d", "to": "now"}

# ---- Today: the morning look at everything, one page (each tile opens its own board through the links above)
ALL = {"type": "data_view_reference", "ref_id": "sentora-all"}
BAD = ('(event.outcome : "failure" or event.action : waf-* or fortigate.subtype : "ips" or event.action : "ban") and source.ip : * and '
       'not source.ip : ("10.0.0.0/8" or "172.16.0.0/12" or "192.168.0.0/16" or "127.0.0.0/8")')
FAILED_LOGINS = 'event.outcome : "failure" and event.action : * and not event.action : waf-*'
BOARDS["sentora-today"] = build_src("sentora-today", "Today", [
    [(8, 5, on(SHOP, metric("Orders", ORD, ""))),
     (8, 5, on(SHOP, metric("Sales", ORD, "", {"operation": "sum", "field": "sentora.shop.base_total", "format": MONEY}))),
     (8, 5, up({"type": "metric", "title": "Websites up", "data_source": UPDV, "query": q(SITE),
                "metrics": [{"type": "primary", "operation": "formula", "label": "of the checks", "formula": "count(kql='monitor.status : \"up\"') / count()",
                             "format": {"type": "percent"}}]})),
     (8, 5, on(VEEAM, metric("Backup jobs failed", FIN + ' and veeam_job.result : "Failed"', ""))),
     (8, 5, on(M365, metric("Microsoft problems open", 'm365.kind : "service-issue" and @timestamp >= "now-2h"', "", {"operation": "unique_count", "field": "m365.issue.id"}))),
     (8, 5, on(DV, metric("Average CPU busy", HEALTH, "", {"operation": "average", "field": "sentora.health.cpu_pct", "format": {"type": "number", "decimals": 0}})))],
    [(8, 5, on(DV, metric("Failed panel and server logins", FAILED_LOGINS, ""))),
     (8, 5, on(FGT, metric("Failed VPN logins", 'event.category : "vpn" and event.outcome : "failure"', ""))),
     (8, 5, on(M365, metric("Failed Microsoft sign-ins", 'm365.kind : "signin" and event.outcome : "failure"', ""))),
     (8, 5, on(DV, metric("Web firewall stopped", 'event.action : "waf-blocked"', ""))),
     (8, 5, on(FGT, metric("Attacks stopped (FortiGate)", 'fortigate.subtype : "ips"', ""))),
     (8, 5, on(FGT, metric("VPN users now", VPNQ + ' and event.action : ("tunnel-up" or "tunnel-stats") and @timestamp >= "now-15m"', "",
                             {"operation": "unique_count", "field": "user.name"})))],
    [(16, 12, up(table("Websites with failed checks", SITE + " and " + DOWN, ["monitor.name"], 10, "Failed"))),
     (16, 12, on(VEEAM, table("Backup jobs that failed or warned", FIN + ' and veeam_job.result : ("Failed" or "Warning")', ["veeam_job.name", "veeam_job.result"], 10, "Times"))),
     (16, 12, {"type": "data_table", "title": "Addresses attacking in several places", "data_source": ALL, "query": q(BAD),
               "metrics": [{"operation": "unique_count", "field": "data_stream.dataset", "label": "Places"}, {"operation": "count", "label": "Tries"}],
               "rows": [{"operation": "terms", "fields": ["source.ip"], "limit": 10, "label": "Address", "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]})],
    [(24, 12, on(SHOP, bars("Orders by status", 'sentora.shop.kind : "order"', "sentora.shop.status", 5, "Orders"))),
     (24, 12, on(DV, bars("Visits to the websites (people and bots)", WEB, "sentora.visitor", 2, "Visits")))],
    [(48, 3, note("Everything at a glance, for the last 24 hours (change it at the top right). Alerts for what needs a look come as notices in the panel and by mail."))],
])

# ---- Store funnel: how visitors become orders (Magento's own addresses in the visits), and bots in the store
PEOPLE = WEB + ' and sentora.visitor : "person"'
def step(n):
    return "count(kql='sentora.store.step : \"%s\"')" % n
BOARDS["sentora-funnel"] = build_src("sentora-funnel", "Store funnel", [
    [(12, 5, on(DV, metric("Searches", PEOPLE + ' and sentora.store.step : "search"', ""))),
     (12, 5, on(DV, metric("Added to cart", PEOPLE + ' and sentora.store.step : "cart-add"', ""))),
     (12, 5, on(DV, metric("Reached checkout", PEOPLE + ' and sentora.store.step : "checkout"', ""))),
     (12, 5, on(DV, metric("Orders placed", PEOPLE + ' and sentora.store.step : "order-placed"', "")))],
    [(48, 10, {"type": "data_table", "title": "The funnel, by store (people only, bots left out)", "data_source": DV,
               "query": q(PEOPLE + " and sentora.domain : *"),
               "metrics": [{"operation": "count", "label": "Page views"},
                           {"operation": "formula", "formula": step("search"), "label": "Searches"},
                           {"operation": "formula", "formula": step("cart-add"), "label": "Cart adds"},
                           {"operation": "formula", "formula": step("checkout"), "label": "Checkouts"},
                           {"operation": "formula", "formula": step("order-placed"), "label": "Orders"},
                           {"operation": "formula", "formula": step("order-placed") + " / " + step("cart-add"), "label": "Carts that became orders", "format": {"type": "percent"}},
                           {"operation": "formula", "formula": step("cart-add") + " - " + step("order-placed"), "label": "Carts left"}],
               "rows": [{"operation": "terms", "fields": ["sentora.domain"], "limit": 5, "label": "Store",
                         "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]})],
    [(48, 12, on(DV, bars("Steps over time (people)", PEOPLE + " and sentora.store.step : *", "sentora.store.step", 6, "Visits")))],
    [(24, 14, on(DV, table("Bots in the store's buying pages", WEB + ' and sentora.visitor : "bot" and sentora.store.step : *', ["user_agent.name", "sentora.store.step"], 15, "Hits"))),
     (24, 14, on(DV, table("Busiest bots on the websites", WEB + ' and sentora.visitor : "bot"', ["user_agent.name", "sentora.domain"], 15, "Hits")))],
    [(48, 3, note("Steps come from Magento's own addresses (search, add to cart, cart, checkout, order placed); bots are left out of the funnel. "
                  "A bot that keeps adding to carts wastes the server's time: QuickFig's Who is crawling can block it."))],
])

# ---- Attackers across everything: one address, seen by the panel, the FortiGate and Microsoft 365
BOARDS["sentora-attackers"] = build_src("sentora-attackers", "Attackers across everything", [
    [(12, 5, on(ALL, metric("Attacking addresses", BAD, "", {"operation": "unique_count", "field": "source.ip"}))),
     (12, 5, on(ALL, metric("Tries", BAD, ""))),
     (12, 5, on(ALL, metric("Countries", BAD, "", {"operation": "unique_count", "field": "source.geo.country_iso_code"}))),
     (12, 5, on(ALL, metric("Banned (fail2ban)", 'event.action : "ban"', "")))],
    [(28, 16, {"type": "data_table", "title": "Who attacks the most places (block these first)", "data_source": ALL, "query": q(BAD),
               "metrics": [{"operation": "unique_count", "field": "data_stream.dataset", "label": "Places"}, {"operation": "count", "label": "Tries"},
                           {"operation": "max", "field": "@timestamp", "label": "Last seen"}],
               "rows": [{"operation": "terms", "fields": ["source.ip"], "limit": 25, "label": "Address", "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}},
                        {"operation": "terms", "fields": ["source.geo.country_iso_code"], "limit": 1, "label": "Country"}]}),
     (20, 16, on(ALL, world("Where they come from", BAD, "Tries")))],
    [(24, 12, on(ALL, bars("Tries over time, by where they were seen", BAD, "data_stream.dataset", 3, "Tries"))),
     (24, 12, on(ALL, table("What they tried", BAD, ["data_stream.dataset", "event.action"], 15, "Tries")))],
    [(48, 3, note("Failed logins (panel, SSH, mail, FTP, VPN, Microsoft 365), requests the web firewall stopped, attacks the FortiGate stopped and "
                  "fail2ban bans, joined by address; inside addresses left out. An address in two places or more within an hour is also an alert. "
                  "sentora = this panel, fortigate = the FortiGate, m365 = Microsoft 365."))],
])

# ---- Internet use at the office (FortiGate): when it is busiest, who and what uses it most
FUN = ('(fortigate.appcat : ("Video/Audio" or "Social.Media" or "Game" or "P2P") or '
       'fortigate.category : ("Streaming Media and Download" or "Social Networking" or "Games" or "Entertainment" or "Peer-to-peer File Sharing"))')
BOARDS["sentora-internet"] = build_src("sentora-internet", "Internet use at the office", [
    [(12, 5, on(FGT, metric("Data moved", "network.bytes : *", "", {"operation": "sum", "field": "network.bytes", "format": {"type": "bytes"}}))),
     (12, 5, on(FGT, metric("Streaming, social, games", FUN + " and network.bytes : *", "", {"operation": "sum", "field": "network.bytes", "format": {"type": "bytes"}}))),
     (12, 5, on(FGT, metric("People online", "user.name : * and network.bytes : *", "", {"operation": "unique_count", "field": "user.name"}))),
     (12, 5, on(FGT, metric("Sites visited", "url.domain : *", "", {"operation": "unique_count", "field": "url.domain"})))],
    [(48, 13, {"type": "heatmap", "title": "When the internet is busiest (data moved, by hour and day, the FortiGate's time)", "data_source": FGT,
               "query": q("network.bytes : * and fortigate.hour_n : *"),
               "metric": {"operation": "sum", "field": "network.bytes", "label": "Data", "format": {"type": "bytes"}},
               "x": {"operation": "histogram", "field": "fortigate.hour_n", "granularity": "auto", "label": "Hour"},
               "y": {"operation": "terms", "fields": ["fortigate.weekday"], "limit": 7, "label": "Day", "rank_by": {"type": "alphabetical", "direction": "asc"}}})],
    [(24, 14, sum_table("Who uses the most", FGT, "user.name : * and network.bytes : *", ["user.name"], "network.bytes", "Data", 15, {"type": "bytes"})),
     (24, 14, sum_table("Streaming, social and games, by person", FGT, FUN + " and user.name : * and network.bytes : *", ["user.name", "network.application"], "network.bytes", "Data", 15, {"type": "bytes"}))],
    [(24, 12, sum_table("Kinds of apps", FGT, "fortigate.appcat : * and network.bytes : *", ["fortigate.appcat"], "network.bytes", "Data", 12, {"type": "bytes"})),
     (24, 12, {"type": "xy", "title": "Data over time", "query": q("network.bytes : *"), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": FGT, "x": {"operation": "date_histogram", "field": "@timestamp"},
                           "y": [{"operation": "sum", "field": "network.bytes", "label": "Data", "format": {"type": "bytes"}}]}]})],
])

# ---- Backup coverage (Veeam's API: sentora-elk-node veeam-api): every machine's newest backup, every job's state
COVER = 'event.action : "coverage" and @timestamp >= "now-2h"'
JOBS = 'event.action : "job-state" and @timestamp >= "now-2h"'
BOARDS["sentora-coverage"] = build_src("sentora-coverage", "Backup coverage", [
    [(12, 5, on(VEEAM, metric("Machines backed up", COVER, "", {"operation": "unique_count", "field": "host.name"}))),
     (12, 5, on(VEEAM, metric("Without a backup for a day", COVER + " and veeam_job.hours_since > 26", "", {"operation": "unique_count", "field": "host.name"}))),
     (12, 5, on(VEEAM, metric("Jobs", JOBS, "", {"operation": "unique_count", "field": "veeam_job.name"}))),
     (12, 5, on(VEEAM, metric("Jobs whose last run failed", JOBS + ' and veeam_job.result : "Failed"', "", {"operation": "unique_count", "field": "veeam_job.name"})))],
    [(26, 18, {"type": "data_table", "title": "Every machine: its newest backup (oldest first)", "data_source": VEEAM, "query": q(COVER),
               "metrics": [{"operation": "min", "field": "veeam_job.hours_since", "label": "Hours ago", "format": {"type": "number", "decimals": 0}},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "veeam_job.last_backup", "label": "Newest backup"}],
               "rows": [{"operation": "terms", "fields": ["host.name"], "limit": 200, "label": "Machine", "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]}),
      (22, 18, {"type": "data_table", "title": "Every job: last result and next run", "data_source": VEEAM,
               "query": q('event.action : "job-state" and @timestamp >= "now-70m"'),
               "metrics": [{"operation": "count", "label": "Readings"}],
               "rows": [{"operation": "terms", "fields": [f], "limit": lim, "label": lab} for f, lim, lab in
                        (("veeam_job.name", 100, "Job"), ("veeam_job.result", 1, "Last result"), ("veeam_job.last_run", 1, "Last run"), ("veeam_job.next_run", 1, "Next run"))]})],
    [(48, 3, note("Read from Veeam's API every hour (sentora-elk-node veeam-api). A machine without a backup for over a day is also an alert in the panel."))],
])

# ---- The walls: big coloured tiles to read from across the room (green good, yellow look, red act)
GREEN, YELLOW, RED, BLUE = "#24C28A", "#F2C94C", "#EB5757", "#4FA3F7"


def bands(*steps):
    """Colour bands: bands((None, 50, RED), (50, 80, YELLOW), (80, None, GREEN))."""
    out = []
    for lo, hi, c in steps:
        st = {"color": c}
        if lo is not None:
            st["gte"] = lo
        if hi is not None:
            st["lt"] = hi
        out.append(st)
    return {"type": "dynamic", "range": "absolute", "steps": out}


def tile(title, dv, kql, m, color=None, label="", fmt=None, secondary=None, by=None, limit=24, columns=4, trend=False):
    p = dict(m, type="primary", label=label)
    if fmt:
        p["format"] = fmt
    if color:
        p["color"] = color
    if trend:
        p["background_chart"] = {"type": "trend"}
    cfg = {"type": "metric", "title": title, "data_source": dv, "query": q(kql), "metrics": [p]}
    if secondary:
        cfg["metrics"].append(dict(secondary, type="secondary"))
    if by:
        cfg["breakdown_by"] = {"operation": "terms", "fields": [by], "limit": limit, "columns": columns, "label": NAMES.get(by, by),
                               "rank_by": {"type": "alphabetical", "direction": "asc"}}
    return cfg


def dial(title, dv, kql, m, color, label="%", top=100):
    mm = dict(m, color=color, label=label, max={"operation": "static_value", "value": top})
    return {"type": "gauge", "title": title, "data_source": dv, "query": q(kql), "metric": mm, "styling": {"shape": {"type": "semi_circle"}}}


def last(field):
    return {"operation": "last_value", "time_field": "@timestamp", "field": field}


PCT = {"type": "percent", "decimals": 0}
HEALTHY = bands((None, 0.5, RED), (0.5, 1, YELLOW), (1, None, GREEN))
BUSY = bands((None, 70, GREEN), (70, 90, YELLOW), (90, None, RED))
JOBQ = 'event.action : ("job-state" or "job-finished") and veeam_job.health : *'
REPOQ = 'event.action : "repo-state"'
BOARDS["sentora-backupwall"] = build_src("sentora-backupwall", "Backup wall", [
    [(12, 6, tile("Jobs OK", VEEAM, JOBQ + ' and veeam_job.health >= 1', {"operation": "unique_count", "field": "veeam_job.name"}, {"type": "static", "color": GREEN})),
     (12, 6, tile("Jobs with warnings", VEEAM, JOBQ + ' and veeam_job.health >= 0.5 and veeam_job.health < 1', {"operation": "unique_count", "field": "veeam_job.name"}, {"type": "static", "color": YELLOW})),
     (12, 6, tile("Jobs failed", VEEAM, JOBQ + ' and veeam_job.health < 0.5', {"operation": "unique_count", "field": "veeam_job.name"}, {"type": "static", "color": RED})),
     (12, 6, tile("Machines protected", VEEAM, COVER, {"operation": "unique_count", "field": "host.name"}, {"type": "static", "color": BLUE}))],
    [(48, 24, tile("Backup jobs (each one's last result)", VEEAM, JOBQ, last("veeam_job.health"), HEALTHY, "health", PCT,
                   secondary=dict(last("veeam_job.result"), label="last result"), by="veeam_job.name", limit=15, columns=5))],
    [(48, 26, tile("Machines (hours since their newest backup)", VEEAM, COVER, {"operation": "min", "field": "veeam_job.hours_since"},
                   bands((None, 26, GREEN), (26, 48, YELLOW), (48, None, RED)), "hours ago", {"type": "number", "decimals": 0},
                   secondary=dict(last("veeam_job.platform"), label=""), by="host.name", limit=24, columns=6))],
    [(24, 12, tile("Repositories (how full)", VEEAM, REPOQ, last("veeam_repo.used_pct"), BUSY, "used", {"type": "number", "decimals": 0, "suffix": "%"},
                   secondary=dict(last("veeam_repo.free_gb"), label="GB free", format={"type": "number", "decimals": 0}), by="veeam_repo.name", limit=6, columns=3)),
     (24, 12, {"type": "xy", "title": "Job results over the last days", "query": q('event.action : "job-finished"'), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": VEEAM, "x": {"operation": "date_histogram", "field": "@timestamp"},
                           "y": [{"operation": "count", "label": "Jobs"}],
                           "breakdown_by": {"operation": "terms", "fields": ["veeam_job.result"], "limit": 3, "label": "Result",
                                            "rank_by": {"type": "alphabetical", "direction": "asc"}}}]})],
])
BOARDS["sentora-backupwall"]["time_range"] = {"from": "now-2d", "to": "now"}

SERVER = 'sentora.log : "health"'
SITEUP = "count(kql='monitor.status : \"up\"') / count()"
BOARDS["sentora-serverwall"] = build_src("sentora-serverwall", "Server wall", [
    [(12, 10, dial("CPU busy", DV, SERVER, last("sentora.health.cpu_pct"), BUSY)),
     (12, 10, dial("Memory used", DV, SERVER, last("sentora.health.mem_used_pct"), BUSY)),
     (12, 10, dial("System disk", DV, SERVER, last("sentora.health.disk_root_used_pct"), BUSY)),
     (12, 10, dial("Websites' disk", DV, SERVER, last("sentora.health.disk_sites_used_pct"), BUSY))],
    [(8, 6, tile("Load (1 min)", DV, SERVER, {"operation": "average", "field": "sentora.health.load1"}, {"type": "static", "color": BLUE}, fmt={"type": "number", "decimals": 2}, trend=True)),
     (8, 6, tile("Requests a second", DV, SERVER, {"operation": "average", "field": "sentora.health.apache_rps"}, {"type": "static", "color": BLUE}, fmt={"type": "number", "decimals": 1}, trend=True)),
     (8, 6, tile("MySQL queries a second", DV, SERVER, {"operation": "average", "field": "sentora.health.mysql_qps"}, {"type": "static", "color": BLUE}, fmt={"type": "number", "decimals": 1}, trend=True)),
     (8, 6, tile("Mail waiting", DV, SERVER, last("sentora.health.mail_queue"), bands((None, 50, GREEN), (50, 200, YELLOW), (200, None, RED)), fmt={"type": "number", "decimals": 0}, trend=True)),
     (8, 6, tile("Network in", DV, SERVER, {"operation": "average", "field": "sentora.health.net_rx_bps"}, {"type": "static", "color": BLUE}, fmt={"type": "bytes", "suffix": "/s"}, trend=True)),
     (8, 6, tile("Network out", DV, SERVER, {"operation": "average", "field": "sentora.health.net_tx_bps"}, {"type": "static", "color": BLUE}, fmt={"type": "bytes", "suffix": "/s"}, trend=True))],
    [(24, 22, up(tile("Websites (checks answered, last 15 minutes)", UPDV, SITE + ' and @timestamp >= "now-15m"', {"operation": "formula", "formula": SITEUP},
                      bands((None, 0.9, RED), (0.9, 1, YELLOW), (1, None, GREEN)), "up", PCT, by="monitor.name", limit=12, columns=3))),
     (24, 22, up(tile("Certificates (days left)", UPDV, SITE + " and tls.server.x509.not_after : *" + ' and @timestamp >= "now-15m"',
                      {"operation": "min", "field": "sentora.cert_days"}, bands((None, 7, RED), (7, 21, YELLOW), (21, None, GREEN)), "days left",
                      {"type": "number", "decimals": 0}, by="monitor.name", limit=12, columns=3)))],
    [(12, 6, tile("Visits, last hour (people)", DV, WEB + ' and sentora.visitor : "person" and @timestamp >= "now-1h"', {"operation": "count"}, {"type": "static", "color": BLUE}, trend=True)),
     (12, 6, tile("Orders today", SHOP, ORD + ' and @timestamp >= "now/d"', {"operation": "count"}, {"type": "static", "color": GREEN})),
     (12, 6, tile("Failed logins, last hour", DV, FAILED_LOGINS + ' and @timestamp >= "now-1h"', {"operation": "count"}, bands((None, 20, GREEN), (20, 100, YELLOW), (100, None, RED)))),
     (12, 6, tile("Web firewall stopped, last hour", DV, 'event.action : "waf-blocked" and @timestamp >= "now-1h"', {"operation": "count"}, bands((None, 50, GREEN), (50, 200, YELLOW), (200, None, RED))))],
])
BOARDS["sentora-serverwall"]["time_range"] = {"from": "now-6h", "to": "now"}
BOARDS["sentora-serverwall"]["refresh_interval"] = {"pause": False, "value": 30000}
BOARDS["sentora-backupwall"]["refresh_interval"] = {"pause": False, "value": 60000}

# ---- more walls: the network (FortiGate), Microsoft 365, security, the store's sales, the week against the one before
def ring(title, dv, kql, field, m=None, limit=6):
    """A ring (donut) chart: the share of each value."""
    return {"type": "pie", "title": title, "data_source": dv, "query": q(kql), "metrics": [m or {"operation": "count", "label": "Lines"}],
            "group_by": [{"operation": "terms", "fields": [field], "limit": limit, "label": NAMES.get(field, field)}], "styling": {"donut_hole": "m"}}


def blocks(title, dv, kql, fields, m=None, limit=8):
    """A treemap: blocks as big as their share."""
    return {"type": "treemap", "title": title, "data_source": dv, "query": q(kql), "metrics": [m or {"operation": "count", "label": "Lines"}],
            "group_by": [{"operation": "terms", "fields": [f], "limit": limit, "label": NAMES.get(f, f)} for f in fields]}


def area(title, dv, kql, y, by=None, limit=6):
    layer = {"type": "area_stacked", "data_source": dv, "x": {"operation": "date_histogram", "field": "@timestamp"}, "y": y}
    if by:
        layer["breakdown_by"] = {"operation": "terms", "fields": [by], "limit": limit, "label": NAMES.get(by, by)}
    return {"type": "xy", "title": title, "query": q(kql), "layers": [layer], "legend": {"visibility": "visible", "position": "right"}}


def vs_last_week(title, dv, kql, m, color=BLUE, fmt=None, fewer_is_better=False):
    """A tile: this period, and how it compares with the same time a week before (arrow up or down; red when it went the wrong way)."""
    p = dict(m, type="primary", label="", color={"type": "static", "color": color})
    s = dict(m, type="secondary", label="a week before", time_shift="1w",
             compare={"to": "primary", "palette": "compare_to_reversed" if fewer_is_better else "compare_to"})
    if fmt:
        p["format"] = s["format"] = fmt
    return {"type": "metric", "title": title, "data_source": dv, "query": q(kql), "metrics": [p, s]}


BYTES_S = {"type": "bytes", "suffix": "/s"}
NOW15 = ' and @timestamp >= "now-15m"'
HOUR = ' and @timestamp >= "now-1h"'


def LOW(a, b):
    """Green below a, yellow up to b, red from b on (for counts where fewer is better)."""
    return bands((None, a, GREEN), (a, b, YELLOW), (b, None, RED))


BOARDS["sentora-netwall"] = build_src("sentora-netwall", "Network wall", [
    [(8, 6, tile("Internet now", FGT, "network.bytes : *" + ' and @timestamp >= "now-5m"', {"operation": "formula", "formula": "sum(network.bytes) / 300"},
                 {"type": "static", "color": BLUE}, "in and out", BYTES_S)),
     (8, 6, tile("People on the VPN now", FGT, VPNQ + ' and event.action : ("tunnel-up" or "tunnel-stats")' + NOW15, {"operation": "unique_count", "field": "user.name"},
                 {"type": "static", "color": GREEN})),
     (8, 6, tile("Online now (inside)", FGT, "user.name : * and network.bytes : *" + NOW15, {"operation": "unique_count", "field": "user.name"}, {"type": "static", "color": BLUE})),
     (8, 6, tile("Failed VPN logins, last hour", FGT, VPNQ + ' and event.outcome : "failure"' + HOUR, {"operation": "count"}, LOW(5, 20))),
     (8, 6, tile("Attacks stopped, last hour", FGT, 'fortigate.subtype : "ips"' + HOUR, {"operation": "count"}, LOW(20, 100))),
     (8, 6, tile("Sites blocked, last hour", FGT, 'fortigate.subtype : "webfilter" and event.outcome : "failure"' + HOUR, {"operation": "count"}, LOW(20, 100)))],
    [(24, 16, tile("Tunnels to the other sites (up or down)", FGT, SITEQ + " and fortigate.vpn.up : *", last("fortigate.vpn.up"),
                   bands((None, 1, RED), (1, None, GREEN)), "up", PCT, secondary=dict(last("source.ip"), label="other end"),
                   by="fortigate.vpn.name", limit=8, columns=2)),
     (24, 16, tile("On the VPN now (data in the last 15 minutes)", FGT, VPNQ + ' and event.action : ("tunnel-up" or "tunnel-stats")' + NOW15,
                   {"operation": "sum", "field": "network.bytes"}, {"type": "static", "color": GREEN}, "", {"type": "bytes"},
                   secondary=dict(last("source.geo.country_iso_code"), label="from"), by="user.name", limit=8, columns=4))],
    [(16, 14, ring("Who uses the most now (15 minutes)", FGT, "user.name : * and network.bytes : *" + NOW15, "user.name",
                   {"operation": "sum", "field": "network.bytes", "label": "Data", "format": {"type": "bytes"}}, 8)),
     (16, 14, blocks("Apps now (15 minutes)", FGT, "network.application : * and network.bytes : *" + NOW15, ["fortigate.appcat", "network.application"],
                     {"operation": "sum", "field": "network.bytes", "label": "Data", "format": {"type": "bytes"}}, 6)),
     (16, 14, ring("Kinds of sites now (15 minutes)", FGT, "fortigate.category : *" + NOW15, "fortigate.category", None, 8))],
    [(48, 11, area("Internet use, by kind of app", FGT, "fortigate.appcat : * and network.bytes : *",
                   [{"operation": "sum", "field": "network.bytes", "label": "Data", "format": {"type": "bytes"}}], "fortigate.appcat", 6))],
])
BOARDS["sentora-netwall"]["time_range"] = {"from": "now-6h", "to": "now"}
BOARDS["sentora-netwall"]["refresh_interval"] = {"pause": False, "value": 30000}

SVC = 'm365.kind : "service-health" and @timestamp >= "now-30m"'
NAMES.update({"m365.service": "Service", "m365.risk": "Risk", "m365.status": "State"})
BOARDS["sentora-m365wall"] = build_src("sentora-m365wall", "Microsoft 365 wall", [
    [(48, 15, tile("Microsoft's services (from Microsoft, every 5 minutes)", M365, SVC, last("m365.health"), HEALTHY, "health", PCT,
                   secondary=dict(last("m365.state"), label=""), by="m365.service", limit=12, columns=6))],
    [(10, 6, tile("Sign-ins", M365, SIGN, {"operation": "count"}, {"type": "static", "color": BLUE}, trend=True)),
     (10, 6, tile("People signing in", M365, SIGN, {"operation": "unique_count", "field": "user.name"}, {"type": "static", "color": BLUE})),
     (10, 6, tile("Failed sign-ins", M365, SIGN + ' and event.outcome : "failure"', {"operation": "count"}, LOW(50, 200), trend=True)),
     (9, 6, tile("Risky sign-ins", M365, SIGN + ' and m365.risk : ("medium" or "high")', {"operation": "count"}, LOW(5, 20))),
     (9, 6, tile("Signed in with MFA", M365, SIGN + " and m365.interactive : true", {"operation": "formula", "formula": "count(kql='m365.mfa : true') / count()"},
                 bands((None, 0.8, RED), (0.8, 0.95, YELLOW), (0.95, None, GREEN)), "of sign-ins", PCT))],
    [(16, 14, ring("Apps signed in to", M365, SIGN, "m365.app", None, 6)),
     (16, 14, ring("Where from", M365, SIGN + " and source.geo.country_iso_code : *", "source.geo.country_iso_code", None, 6)),
     (16, 14, on(M365, table("Failed and risky sign-ins", SIGN + ' and (event.outcome : "failure" or m365.risk : ("medium" or "high"))',
                             ["user.name", "m365.reason"], 12, "Times")))],
    [(24, 11, {"type": "xy", "title": "Teams per day", "query": q('m365.kind : "teams-day"'), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": M365, "x": {"operation": "date_histogram", "field": "@timestamp", "suggested_interval": "1d"},
                           "y": [{"operation": "sum", "field": "m365.teams.private_chat", "label": "Chats"},
                                 {"operation": "sum", "field": "m365.teams.team_chat", "label": "Channels"},
                                 {"operation": "sum", "field": "m365.teams.calls", "label": "Calls"},
                                 {"operation": "sum", "field": "m365.teams.meetings", "label": "Meetings"}]}]}),
     (24, 11, {"type": "xy", "title": "Mail per day", "query": q('m365.kind : "mail-day"'), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar_stacked", "data_source": M365, "x": {"operation": "date_histogram", "field": "@timestamp", "suggested_interval": "1d"},
                           "y": [{"operation": "sum", "field": "m365.mail.sent", "label": "Sent"},
                                 {"operation": "sum", "field": "m365.mail.received", "label": "Received"}]}]})],
])
BOARDS["sentora-m365wall"]["time_range"] = {"from": "now-7d", "to": "now"}

BOARDS["sentora-secwall"] = build_src("sentora-secwall", "Security wall", [
    [(8, 6, tile("Attacking addresses, last hour", ALL, BAD + HOUR, {"operation": "unique_count", "field": "source.ip"}, LOW(10, 40), trend=False)),
     (8, 6, tile("Tries, last hour", ALL, BAD + HOUR, {"operation": "count"}, LOW(100, 500))),
     (8, 6, tile("Failed logins, last hour", ALL, 'event.outcome : "failure" and not event.action : waf-* and not fortigate.subtype : "ips"' + HOUR, {"operation": "count"}, LOW(30, 150))),
     (8, 6, tile("Web firewall stopped, last hour", DV, 'event.action : "waf-blocked"' + HOUR, {"operation": "count"}, LOW(50, 200))),
     (8, 6, tile("FortiGate attacks stopped, last hour", FGT, 'fortigate.subtype : "ips"' + HOUR, {"operation": "count"}, LOW(20, 100))),
     (8, 6, tile("Banned today", DV, 'event.action : "ban" and @timestamp >= "now/d"', {"operation": "count"}, {"type": "static", "color": BLUE}))],
    [(24, 15, tile("The busiest attackers (tries)", ALL, BAD, {"operation": "count"}, LOW(20, 100), "tries",
                   secondary=dict(last("source.geo.country_iso_code"), label=""), by="source.ip", limit=12, columns=4) | {"breakdown_by": {
                       "operation": "terms", "fields": ["source.ip"], "limit": 12, "columns": 4, "label": "Address",
                       "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}}),
     (24, 15, on(ALL, world("Where they come from", BAD, "Tries")))],
    [(16, 14, blocks("What they tried, and where", ALL, BAD, ["data_stream.dataset", "event.action"], None, 6)),
     (16, 14, ring("Countries", ALL, BAD + " and source.geo.country_iso_code : *", "source.geo.country_iso_code", None, 8)),
     (16, 14, ring("Where they were stopped", ALL, BAD, "data_stream.dataset", None, 4))],
    [(48, 10, area("Tries over time, by where they were seen", ALL, BAD, [{"operation": "count", "label": "Tries"}], "data_stream.dataset", 4))],
])
BOARDS["sentora-secwall"]["refresh_interval"] = {"pause": False, "value": 30000}

ITEMS = 'sentora.shop.kind : "item" and not sentora.shop.status : ("canceled" or "closed")'
TOTAL = {"operation": "sum", "field": "sentora.shop.base_total"}
BOARDS["sentora-saleswall"] = build_src("sentora-saleswall", "Sales wall", [
    [(12, 7, vs_last_week("Sales", SHOP, ORD, TOTAL, GREEN, MONEY)),
     (12, 7, vs_last_week("Orders", SHOP, ORD, {"operation": "count"}, GREEN)),
     (12, 7, vs_last_week("Average order", SHOP, ORD, {"operation": "average", "field": "sentora.shop.base_total"}, BLUE, MONEY)),
     (12, 7, vs_last_week("Items sold", SHOP, ITEMS, {"operation": "sum", "field": "sentora.shop.qty"}, BLUE, {"type": "number", "decimals": 0}))],
    [(32, 13, {"type": "xy", "title": "Sales by hour: today and the same day last week", "query": q(ORD), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar", "data_source": SHOP, "x": {"operation": "date_histogram", "field": "@timestamp", "suggested_interval": "1h"},
                           "y": [dict(TOTAL, label="Today", format=MONEY)]},
                          {"type": "line", "data_source": SHOP, "x": {"operation": "date_histogram", "field": "@timestamp", "suggested_interval": "1h"},
                           "y": [dict(TOTAL, label="A week before", format=MONEY, time_shift="1w")]}]}),
     (16, 13, ring("Best sellers (items)", SHOP, ITEMS, "sentora.shop.product", {"operation": "sum", "field": "sentora.shop.qty", "label": "Sold"}, 6))],
    [(24, 13, {"type": "data_table", "title": "Latest orders", "data_source": SHOP, "query": q('sentora.shop.kind : "order"'),
               "metrics": [{"operation": "max", "field": "@timestamp", "label": "When"}, dict(TOTAL, label="Total", format=MONEY),
                           {"operation": "last_value", "time_field": "@timestamp", "field": "sentora.shop.status", "label": "Status"},
                           {"operation": "last_value", "time_field": "@timestamp", "field": "sentora.shop.country", "label": "Country"}],
               "rows": [{"operation": "terms", "fields": ["sentora.shop.number"], "limit": 10, "label": "Order",
                         "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]}),
     (12, 13, ring("Where orders go", SHOP, ORD + " and sentora.shop.country : *", "sentora.shop.country", dict(TOTAL, label="Sales", format=MONEY), 6)),
     (12, 13, {"type": "data_table", "title": "Searches that found nothing", "data_source": SHOP, "query": q('sentora.shop.kind : "search" and sentora.shop.results : 0'),
               "metrics": [{"operation": "max", "field": "sentora.shop.popularity", "label": "Times"}],
               "rows": [{"operation": "terms", "fields": ["sentora.shop.term"], "limit": 10, "label": "Searched for",
                         "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}]})],
])
BOARDS["sentora-saleswall"]["time_range"] = {"from": "now/d", "to": "now"}

BOARDS["sentora-week"] = build_src("sentora-week", "Week in review", [
    [(12, 7, vs_last_week("Sales", SHOP, ORD, TOTAL, GREEN, MONEY)),
     (12, 7, vs_last_week("Orders", SHOP, ORD, {"operation": "count"}, GREEN)),
     (12, 7, vs_last_week("Visits (people)", DV, WEB + ' and sentora.visitor : "person"', {"operation": "count"})),
     (12, 7, vs_last_week("Internet used", FGT, "network.bytes : *", {"operation": "sum", "field": "network.bytes"}, BLUE, {"type": "bytes"}))],
    [(12, 7, vs_last_week("Attack tries", ALL, BAD, {"operation": "count"}, RED, fewer_is_better=True)),
     (12, 7, vs_last_week("Failed Microsoft sign-ins", M365, SIGN + ' and event.outcome : "failure"', {"operation": "count"}, RED, fewer_is_better=True)),
     (12, 7, vs_last_week("Backup jobs failed", VEEAM, FIN + ' and veeam_job.result : "Failed"', {"operation": "count"}, RED, fewer_is_better=True)),
     (12, 7, vs_last_week("Teams calls", M365, 'm365.kind : "teams-day"', {"operation": "sum", "field": "m365.teams.calls"}, BLUE, {"type": "number", "decimals": 0}))],
    [(24, 12, {"type": "xy", "title": "Visits: this week and the week before", "query": q(WEB + ' and sentora.visitor : "person"'), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "area", "data_source": DV, "x": {"operation": "date_histogram", "field": "@timestamp"}, "y": [{"operation": "count", "label": "This week"}]},
                          {"type": "line", "data_source": DV, "x": {"operation": "date_histogram", "field": "@timestamp"}, "y": [{"operation": "count", "label": "The week before", "time_shift": "1w"}]}]}),
     (24, 12, {"type": "xy", "title": "Sales: this week and the week before", "query": q(ORD), "legend": {"visibility": "visible", "position": "right"},
               "layers": [{"type": "bar", "data_source": SHOP, "x": {"operation": "date_histogram", "field": "@timestamp", "suggested_interval": "1d"}, "y": [dict(TOTAL, label="This week", format=MONEY)]},
                          {"type": "line", "data_source": SHOP, "x": {"operation": "date_histogram", "field": "@timestamp", "suggested_interval": "1d"}, "y": [dict(TOTAL, label="The week before", format=MONEY, time_shift="1w")]}]})],
    [(16, 13, ring("What the internet was used for", FGT, "fortigate.appcat : * and network.bytes : *", "fortigate.appcat",
                   {"operation": "sum", "field": "network.bytes", "label": "Data", "format": {"type": "bytes"}}, 6)),
     (16, 13, ring("Best sellers", SHOP, ITEMS, "sentora.shop.product", {"operation": "sum", "field": "sentora.shop.qty", "label": "Sold"}, 6)),
     (16, 13, ring("Where attacks came from", ALL, BAD + " and source.geo.country_iso_code : *", "source.geo.country_iso_code", None, 6))],
])
BOARDS["sentora-week"]["time_range"] = {"from": "now-7d", "to": "now"}


# ---- Active Directory (the domain controllers' security events, sentora-elk-node windows)
WIN = {"type": "data_view_reference", "ref_id": "sentora-windows"}
NAMES.update({"windows.reason": "Why", "windows.from": "From", "windows.from_machine": "From computer", "windows.target": "Account",
              "windows.member": "Member", "group.name": "Group", "windows.logon_kind": "How", "windows.object": "Object", "windows.weekday": "Day"})
AD_FAIL = 'event.outcome : "failure" and not event.action : "locked-out"'
AD_LOGON = 'event.action : ("logon" or "kerberos-logon") and event.outcome : "success"'
AD_ADMIN = 'event.action : ("group-member-added" or "group-member-removed") and windows.admin_group : true'
AD_ACCOUNTS = 'event.action : ("account-created" or "account-deleted" or "account-enabled" or "account-disabled" or "account-renamed" or "password-reset")'
TODAY = ' and @timestamp >= "now/d"'
BOARDS["sentora-adwall"] = build_src("sentora-adwall", "AD wall", [
    [(48, 7, tile("Domain controllers (events in the last hour)", WIN, "host.name : *", {"operation": "formula", "formula": "count(kql='@timestamp >= \"now-1h\"')"},
                  bands((None, 1, YELLOW), (1, None, GREEN)), "events", {"type": "number", "decimals": 0}, by="host.name", limit=3, columns=3))],
    [(8, 6, tile("Locked out, last 24 hours", WIN, 'event.action : "locked-out"', {"operation": "unique_count", "field": "user.name"}, LOW(1, 5))),
     (8, 6, tile("Failed logons, last hour", WIN, AD_FAIL + HOUR, {"operation": "count"}, LOW(20, 100), trend=False)),
     (8, 6, tile("Admin group changes today", WIN, AD_ADMIN + TODAY, {"operation": "count"}, LOW(1, 1))),
     (8, 6, tile("Accounts created today", WIN, 'event.action : "account-created"' + TODAY, {"operation": "count"}, {"type": "static", "color": BLUE})),
     (8, 6, tile("Passwords reset today", WIN, 'event.action : "password-reset"' + TODAY, {"operation": "count"}, {"type": "static", "color": BLUE})),
     (8, 6, tile("Security log cleared", WIN, 'event.action : "log-cleared"', {"operation": "count"}, LOW(1, 1)))],
    [(24, 15, tile("Locked out (last 24 hours)", WIN, 'event.action : "locked-out"', {"operation": "count"}, {"type": "static", "color": RED}, "times",
                   secondary=dict(last("windows.from"), label="from"), by="user.name", limit=8, columns=4)),
     (24, 15, tile("Failed logons by account, last hour", WIN, AD_FAIL + HOUR, {"operation": "count"}, LOW(5, 15), "tries",
                   secondary=dict(last("windows.reason"), label=""), by="user.name", limit=8, columns=4) | {"breakdown_by": {
                       "operation": "terms", "fields": ["user.name"], "limit": 8, "columns": 4, "label": "Account",
                       "rank_by": {"type": "metric", "metric_index": 0, "direction": "desc"}}})],
    [(32, 11, area("Logons and failures over time", WIN, 'event.action : ("logon" or "kerberos-logon" or "logon-failed" or "kerberos-failed" or "ntlm-failed" or "locked-out")',
                   [{"operation": "count", "label": "Events"}], "event.action", 6)),
     (16, 11, ring("Why logons failed", WIN, AD_FAIL + " and windows.reason : *", "windows.reason", None, 6))],
])
BOARDS["sentora-adwall"]["refresh_interval"] = {"pause": False, "value": 30000}

BOARDS["sentora-ad"] = build_src("sentora-ad", "Active Directory", [
    [(8, 5, on(WIN, metric("People who logged on", AD_LOGON, "", {"operation": "unique_count", "field": "user.name"}))),
     (8, 5, on(WIN, metric("Failed logons", AD_FAIL, ""))),
     (8, 5, on(WIN, metric("Lockouts", 'event.action : "locked-out"', ""))),
     (8, 5, on(WIN, metric("Admin group changes", AD_ADMIN, ""))),
     (8, 5, on(WIN, metric("Account changes", AD_ACCOUNTS, ""))),
     (8, 5, on(WIN, metric("Group Policy changes", 'event.action : "gpo-changed"', "")))],
    [(20, 14, on(WIN, table("Failed logons: account and why", AD_FAIL, ["user.name", "windows.reason"], 15, "Times"))),
     (14, 14, on(WIN, table("Lockouts: account and from where", 'event.action : "locked-out"', ["user.name", "windows.from"], 15, "Times"))),
     (14, 14, ring("Why logons failed", WIN, AD_FAIL + " and windows.reason : *", "windows.reason", None, 6))],
    [(48, 8, {"type": "data_table", "title": "Admin groups: who was added or removed, and by whom", "data_source": WIN, "query": q(AD_ADMIN),
               "metrics": [{"operation": "max", "field": "@timestamp", "label": "When"}],
               "rows": [{"operation": "terms", "fields": [f], "limit": lim, "label": lab} for f, lim, lab in
                        (("group.name", 20, "Group"), ("windows.member", 20, "Member"), ("event.action", 2, "What"), ("user.name", 5, "By"))]})],
    [(24, 13, {"type": "data_table", "title": "Accounts: created, deleted, switched on or off, passwords reset", "data_source": WIN, "query": q(AD_ACCOUNTS),
               "metrics": [{"operation": "max", "field": "@timestamp", "label": "When"}],
               "rows": [{"operation": "terms", "fields": [f], "limit": lim, "label": lab} for f, lim, lab in
                        (("event.action", 6, "What"), ("windows.target", 30, "Account"), ("user.name", 5, "By"))]}),
     (24, 13, on(WIN, table("Logons outside working hours (weekends, before 6, after 20)", AD_LOGON + " and windows.off_hours : true",
                            ["user.name", "windows.logon_kind", "host.name"], 15, "Logons")))],
    [(48, 12, {"type": "heatmap", "title": "When people log on (by hour and day, the office's time)", "data_source": WIN,
               "query": q(AD_LOGON + " and windows.hour_n : *"),
               "metric": {"operation": "count", "label": "Logons"},
               "x": {"operation": "histogram", "field": "windows.hour_n", "granularity": "auto", "label": "Hour"},
               "y": {"operation": "terms", "fields": ["windows.weekday"], "limit": 7, "label": "Day", "rank_by": {"type": "alphabetical", "direction": "asc"}}})],
    [(24, 12, on(WIN, table("Remote desktop and keyboard logons on the domain controllers", 'event.action : "logon" and windows.logon_kind : *',
                            ["user.name", "windows.logon_kind", "host.name"], 15, "Logons"))),
     (24, 12, on(WIN, table("Group Policy and audit changes", 'event.action : ("gpo-changed" or "audit-policy-changed" or "log-cleared")',
                            ["event.action", "user.name", "host.name"], 15, "Times")))],
    [(48, 3, note("From the domain controllers' security logs (Winlogbeat on each; sentora-elk-node windows DC-ADDRESS). Network logons and computer "
                  "accounts are left out. Alerts in the panel: a lockout, someone added to an admin group, a cleared security log, many failed logons "
                  "for one account, a domain controller gone quiet."))],
])
BOARDS["sentora-ad"]["time_range"] = {"from": "now-7d", "to": "now"}

# ---- Flows (Sankey): where things come from, where they go, how they end. Vega (Kibana's custom charts), saved as
# visualizations and put on the Flows board by addvis.py (the dashboards API takes no Vega panel).
def sankey(title, index, must, fields, sizes=(8, 6, 6), metric=None, unit=""):
    """Three columns (fields[0] -> fields[1] -> fields[2]); bands as wide as their count (or the sum of metric)."""
    leaf = {"terms": {"field": fields[2], "size": sizes[2]}}
    if metric:
        leaf["aggs"] = {"v": {"sum": {"field": metric}}}
    body = {"size": 0, "query": {"bool": {
        "must": ["%dashboard_context-must_clause%", {"range": {"@timestamp": {"%timefilter%": True}}}] + must,
        "must_not": ["%dashboard_context-must_not_clause%"], "filter": ["%dashboard_context-filter_clause%"]}},
        "aggs": {"a": {"terms": {"field": fields[0], "size": sizes[0]},
                       "aggs": {"b": {"terms": {"field": fields[1], "size": sizes[1]}, "aggs": {"c": leaf}}}}}}
    val = "datum.cc.v.value" if metric else "datum.cc.doc_count"
    stage = lambda name, a, b, ca, cb: {"name": name, "source": "rows", "transform": [
        {"type": "aggregate", "groupby": [a, b], "fields": ["n"], "ops": ["sum"], "as": ["v"]},
        {"type": "formula", "as": "skey", "expr": "'%d|' + datum.%s" % (ca, a)},
        {"type": "formula", "as": "tkey", "expr": "'%d|' + datum.%s" % (cb, b)},
        {"type": "formula", "as": "sname", "expr": "datum.%s" % a},
        {"type": "formula", "as": "tname", "expr": "datum.%s" % b}]}
    column = lambda name, k, c: {"name": name, "source": "rows", "transform": [
        {"type": "aggregate", "groupby": [k], "fields": ["n"], "ops": ["sum"], "as": ["v"]},
        {"type": "formula", "as": "col", "expr": str(c)}, {"type": "formula", "as": "name", "expr": "datum.%s" % k},
        {"type": "formula", "as": "key", "expr": "'%d|' + datum.%s" % (c, k)}]}
    fmt = "format(%s, ',.3~s') + '%s'" if unit else "format(%s, ',')"
    show = lambda v: (fmt % (v, unit)) if unit else (fmt % v)
    spec = {
        "$schema": "https://vega.github.io/schema/vega/v5.json",
        "title": {"text": "", "orient": "none"},
        "autosize": {"type": "fit", "contains": "padding"}, "padding": 8,
        "signals": [{"name": "nodeW", "value": 14}, {"name": "gap", "value": 8},
                    {"name": "tot", "update": "data('total').length ? data('total')[0].v : 1"},
                    {"name": "most", "update": "data('counts').length ? data('counts')[0].m : 1"},
                    {"name": "unit", "update": "max(1, height - gap * (most - 1)) / max(tot, 1)"},
                    {"name": "labelW", "value": 150},
                    {"name": "hover", "value": "", "on": [{"events": "@band:mouseover", "update": "datum.skey + '>' + datum.tkey"},
                                                         {"events": "@band:mouseout", "update": "''"}]}],
        "data": [
            {"name": "raw", "url": {"index": index, "body": body}, "format": {"property": "aggregations.a.buckets"}},
            {"name": "rows", "source": "raw", "transform": [
                {"type": "flatten", "fields": ["b.buckets"], "as": ["bb"]},
                {"type": "flatten", "fields": ["bb.c.buckets"], "as": ["cc"]},
                {"type": "formula", "as": "k0", "expr": "'' + datum.key"},
                {"type": "formula", "as": "k1", "expr": "'' + datum.bb.key"},
                {"type": "formula", "as": "k2", "expr": "'' + datum.cc.key"},
                {"type": "formula", "as": "n", "expr": val},
                {"type": "filter", "expr": "datum.n > 0"}]},
            {"name": "total", "source": "rows", "transform": [{"type": "aggregate", "fields": ["n"], "ops": ["sum"], "as": ["v"]}]},
            column("c0", "k0", 0), column("c1", "k1", 1), column("c2", "k2", 2),
            {"name": "stacked", "source": ["c0", "c1", "c2"], "transform": [
                {"type": "stack", "groupby": ["col"], "field": "v", "sort": {"field": "v", "order": "descending"}, "as": ["y0", "y1"]},
                {"type": "window", "groupby": ["col"], "sort": {"field": "v", "order": "descending"}, "ops": ["row_number"], "as": ["rank"]}]},
            {"name": "counts", "source": "stacked", "transform": [
                {"type": "aggregate", "groupby": ["col"], "ops": ["count"], "as": ["c"]},
                {"type": "aggregate", "fields": ["c"], "ops": ["max"], "as": ["m"]}]},
            {"name": "nodes", "source": "stacked", "transform": [
                {"type": "formula", "as": "py0", "expr": "datum.y0 * unit + (datum.rank - 1) * gap"},
                {"type": "formula", "as": "py1", "expr": "datum.y1 * unit + (datum.rank - 1) * gap"},
                {"type": "formula", "as": "px", "expr": "labelW + datum.col * (width - nodeW - 2 * labelW) / 2"}]},
            stage("sA", "k0", "k1", 0, 1), stage("sB", "k1", "k2", 1, 2),
            {"name": "bands", "source": ["sA", "sB"], "transform": [
                {"type": "lookup", "from": "nodes", "key": "key", "fields": ["skey", "tkey"], "as": ["sn", "tn"]},
                {"type": "formula", "as": "trank", "expr": "datum.tn.rank"}, {"type": "formula", "as": "srank", "expr": "datum.sn.rank"},
                {"type": "stack", "groupby": ["skey"], "field": "v", "sort": {"field": "trank"}, "as": ["so0", "so1"]},
                {"type": "stack", "groupby": ["tkey"], "field": "v", "sort": {"field": "srank"}, "as": ["to0", "to1"]},
                {"type": "formula", "as": "x1", "expr": "datum.sn.px + nodeW"},
                {"type": "formula", "as": "x2", "expr": "datum.tn.px"},
                {"type": "formula", "as": "xm", "expr": "(datum.x1 + datum.x2) / 2"},
                {"type": "formula", "as": "a0", "expr": "datum.sn.py0 + datum.so0 * unit"},
                {"type": "formula", "as": "a1", "expr": "datum.sn.py0 + datum.so1 * unit"},
                {"type": "formula", "as": "b0", "expr": "datum.tn.py0 + datum.to0 * unit"},
                {"type": "formula", "as": "b1", "expr": "datum.tn.py0 + datum.to1 * unit"},
                {"type": "formula", "as": "colorkey", "expr": "datum.sn.col == 0 ? datum.sname : datum.tname"},
                {"type": "formula", "as": "path", "expr":
                    "'M' + datum.x1 + ',' + datum.a0 + 'C' + datum.xm + ',' + datum.a0 + ' ' + datum.xm + ',' + datum.b0 + ' ' + datum.x2 + ',' + datum.b0"
                    " + 'L' + datum.x2 + ',' + datum.b1 + 'C' + datum.xm + ',' + datum.b1 + ' ' + datum.xm + ',' + datum.a1 + ' ' + datum.x1 + ',' + datum.a1 + 'Z'"}]}],
        "scales": [{"name": "color", "type": "ordinal", "range": {"scheme": "tableau10"},
                    "domain": {"fields": [{"data": "c0", "field": "name"}, {"data": "c2", "field": "name"}], "sort": True}}],
        "marks": [
            {"type": "path", "name": "band", "from": {"data": "bands"}, "encode": {
                "enter": {"path": {"field": "path"}, "fill": {"scale": "color", "field": "colorkey"},
                          "tooltip": {"signal": "datum.sname + ' \u2192 ' + datum.tname + ': ' + " + show("datum.v")}},
                "update": {"path": {"field": "path"}, "fillOpacity": {"signal": "hover == datum.skey + '>' + datum.tkey ? 0.85 : 0.4"}}}},
            {"type": "rect", "from": {"data": "nodes"}, "encode": {"update": {
                "x": {"field": "px"}, "width": {"signal": "nodeW"}, "y": {"field": "py0"}, "y2": {"field": "py1"},
                "fill": {"signal": "datum.col == 1 ? '#4FA3F7' : scale('color', datum.name)"}, "cornerRadius": {"value": 3},
                "tooltip": {"signal": "datum.name + ': ' + " + show("datum.v")}}}},
            {"type": "text", "from": {"data": "nodes"}, "encode": {"update": {
                "x": {"signal": "datum.col == 0 ? datum.px - 6 : datum.col == 2 ? datum.px + nodeW + 6 : datum.px + nodeW + 6"},
                "y": {"signal": "(datum.py0 + datum.py1) / 2"}, "baseline": {"value": "middle"},
                "align": {"signal": "datum.col == 0 ? 'right' : 'left'"}, "fontSize": {"value": 12}, "fontWeight": {"value": "bold"},
                "fill": {"signal": "datum.col == 1 ? '#4FA3F7' : scale('color', datum.name)"},
                "limit": {"signal": "labelW - 10"},
                "text": {"signal": "datum.py1 - datum.py0 > 9 ? datum.name + '  ' + " + show("datum.v") + " : ''"}}}}]}
    vis = {"title": title, "type": "vega", "aggs": [], "params": {"spec": json.dumps(spec, indent=1)}}
    return {"attributes": {"title": title, "description": "Made by the Sentora Elk node installer (an update replaces it).",
                           "visState": json.dumps(vis), "uiStateJSON": "{}",
                           "kibanaSavedObjectMeta": {"searchSourceJSON": json.dumps({"query": {"query": "", "language": "kuery"}, "filter": []})}}}


PRIVATE = [{"term": {"source.ip": c}} for c in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.0/8")]
ATTACK_Q = [{"exists": {"field": "source.ip"}}, {"bool": {"must_not": PRIVATE, "should": [
    {"term": {"event.outcome": "failure"}}, {"prefix": {"event.action": "waf-"}}, {"term": {"fortigate.subtype": "ips"}}, {"term": {"event.action": "ban"}}],
    "minimum_should_match": 1}}]
FLOWS = {
    "sentora-flow-attacks": sankey("Attacks: country \u2192 where seen \u2192 what they tried", "logs-sentora-*,logs-fortigate-*,logs-m365-*", ATTACK_Q,
                                   ["source.geo.country_iso_code", "data_stream.dataset", "event.action"], (10, 4, 8)),
    "sentora-flow-visits": sankey("Visits: country \u2192 website \u2192 answer", "logs-sentora-*",
                                  [{"term": {"sentora.log": "web-access"}}, {"exists": {"field": "http.response.status_code"}}],
                                  ["source.geo.country_iso_code", "sentora.domain", "http.response.status_code"], (10, 8, 6)),
    "sentora-flow-internet": sankey("Internet: person \u2192 kind of app \u2192 app (data)", "logs-fortigate-*",
                                    [{"exists": {"field": "network.bytes"}}, {"exists": {"field": "user.name"}}],
                                    ["user.name", "fortigate.appcat", "network.application"], (10, 6, 4), "network.bytes", "B"),
}
FLOWS["sentora-flow-ad"] = sankey("Failed logons: from where \u2192 account \u2192 why", "logs-windows-*",
                                  [{"term": {"event.outcome": "failure"}}, {"exists": {"field": "windows.reason"}}],
                                  ["windows.from", "user.name", "windows.reason"], (10, 10, 6))
FLOW_PLACES = [("sentora-flow-attacks", 0, 2, 48, 22), ("sentora-flow-visits", 0, 24, 24, 22), ("sentora-flow-internet", 24, 24, 24, 22), ("sentora-flow-ad", 0, 46, 48, 22)]
BOARDS["sentora-flows"] = build_src("sentora-flows", "Flows", [
    [(48, 1, note(" "))]])
BOARDS["sentora-flows"]["panels"] = BOARDS["sentora-flows"]["panels"][:1] + [
    {"type": "markdown", "grid": {"x": 0, "y": 68, "w": 48, "h": 3}, "config": {"content":
        "Each band runs from where something comes from to where it went: the wider, the more. Point at a band for its number. "
        "Attacks: failed logins, the web firewall, FortiGate attacks and bans (inside addresses left out). Internet: data moved through the FortiGate."}}]


if __name__ == "__main__":
    here = os.path.dirname(os.path.abspath(__file__))
    out = os.path.join(here, "dashboards")
    os.makedirs(out, exist_ok=True)
    for did, body in BOARDS.items():
        with open(os.path.join(out, did + ".json"), "w") as f:
            json.dump(body, f, indent=1, sort_keys=True)
            f.write("\n")
    with open(os.path.join(out, "sentora-world-map.map.json"), "w") as f:
        json.dump(WORLD_MAP, f, indent=1, sort_keys=True)
        f.write("\n")
    for vid, body in FLOWS.items():
        with open(os.path.join(out, vid + ".vis.json"), "w") as f:
            json.dump(body, f, indent=1, sort_keys=True)
            f.write("\n")
    print("wrote", len(BOARDS), "dashboards, the world map and", len(FLOWS), "flow charts")
