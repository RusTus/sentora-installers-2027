#!/usr/bin/python3 -I
"""Sentora Elk node: alerts for the panel (the service sentora-elk-alerts, its own user).

Every 5 minutes it asks Elasticsearch (user sentora_alerts: read only) what needs a look: a website down, a
certificate running out, a full disk, mail piling up, a burst of failed logins or of requests the web firewall
stopped, many bounces, a busy CPU, and the panel's logs gone quiet. The answer is kept in /var/lib/sentora-elk-alerts/
alerts.json and served on port 6515 to the panel's certificate only (mutual TLS, both sides pinned, as on 6514): the
panel's daemon fetches it, shows each alert as a notice to the administrators and mails them.

    sentora-elk-alerts.py serve     the service (checks every 5 minutes, answers on 6515)
    sentora-elk-alerts.py check     one check now; prints the alerts

Settings (each optional) in /etc/sentora-elk-alerts/limits.json, e.g. {"disk_pct": 85}: see LIMITS.
Written by RusTus, 2026-11-20. GPL-3.0.
"""
import base64
import datetime
import json
import os
import socket
import socketserver
import ssl
import sys
import threading
import time
import urllib.request

CONF = "/etc/sentora-elk-alerts"
STATE = "/var/lib/sentora-elk-alerts"
ES = "https://127.0.0.1:9200"
PORT = 6515
EVERY = 300
LIMITS = {
    "down_checks": 3,        # a website: failed checks in a row (one a minute) before it counts as down
    "cert_days": 14,         # a certificate: days left before a warning (an outage under 3)
    "disk_pct": 90,          # disk used, % (system or websites)
    "mail_queue": 200,       # mails waiting to go
    "cpu_pct": 90,           # CPU busy, % on average over the last 15 minutes
    "failed_logins": 100,    # failed logins (SSH, panel, mail, FTP) in 15 minutes
    "waf_blocked": 200,      # requests the web firewall stopped in 15 minutes
    "bounces": 50,           # mails bounced in an hour
    "quiet_minutes": 30,     # no log line from the panel for this long (while there were lines the day before)
    "fgt_admin_fail": 5,     # failed admin logins on a FortiGate in 15 minutes
    "fgt_vpn_fail": 20,      # failed VPN logins on a FortiGate in 15 minutes
    "attacker_events": 10,   # an address that tried this many times in an hour, in two places or more
    "travel_hours": 3,       # Microsoft 365: two countries within this many hours is "impossible travel"
    "backup_hours": 26,      # Veeam: a machine whose newest backup is older than this
    "repo_pct": 90,          # Veeam: a repository this full
    "ad_fail_user": 15,      # Active Directory: failed logons of one account in 15 minutes (someone guessing its password)
    "ad_quiet_hours": 6,
    "vs_ds_pct": 90,         # vSphere: a datastore this full (an outage at 97)
    "vs_host_pct": 90,       # vSphere: a host's CPU or memory this busy, on average over 15 minutes
    "vs_snapshot_days": 7,   # vSphere: a VM snapshot older than this (snapshots grow and slow the VM)     # Active Directory: a domain controller that sent nothing for this long (while it did the day before)
}


def slug(v):
    """An alert id part: lower case letters, digits, dots and dashes only."""
    out = "".join(c if c.isalnum() or c in ".-" else "-" for c in str(v).lower())[:120].strip("-.")
    return out or "x"


def limits():
    out = dict(LIMITS)
    try:
        with open(os.path.join(CONF, "limits.json")) as f:
            mine = json.load(f)
        for k, v in mine.items() if isinstance(mine, dict) else ():
            if k in out and isinstance(v, (int, float)) and v > 0:
                out[k] = v
    except (OSError, ValueError):
        pass
    return out


def es(path, body):
    with open(os.path.join(CONF, "es.pw")) as f:
        pw = f.read().strip()
    ctx = ssl.create_default_context(cafile=os.path.join(CONF, "http_ca.crt"))
    req = urllib.request.Request(ES + path, data=json.dumps(body).encode(), method="POST",
                                 headers={"Content-Type": "application/json",
                                          "Authorization": "Basic " + base64.b64encode(("sentora_alerts:" + pw).encode()).decode()})
    with urllib.request.urlopen(req, context=ctx, timeout=30) as r:
        return json.load(r)


def count(index, since, filters, must_not=()):
    q = {"bool": {"filter": [{"range": {"@timestamp": {"gte": since}}}] + list(filters), "must_not": list(must_not)}}
    return es("/" + index + "/_count?ignore_unavailable=true&allow_no_indices=true", {"query": q}).get("count", 0)


def latest_health(field):
    """The newest value of a health figure, per panel (the last 15 minutes)."""
    r = es("/logs-sentora-*/_search?ignore_unavailable=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"sentora.log": "health"}}, {"exists": {"field": field}}, {"range": {"@timestamp": {"gte": "now-15m"}}}]}},
        "aggs": {"p": {"terms": {"field": "sentora.panel", "size": 20},
                       "aggs": {"t": {"top_hits": {"size": 1, "sort": [{"@timestamp": "desc"}], "_source": [field]}},
                                "a": {"avg": {"field": field}}}}}})
    out = {}
    for b in r.get("aggregations", {}).get("p", {}).get("buckets", []):
        src = b["t"]["hits"]["hits"][0]["_source"] if b["t"]["hits"]["hits"] else {}
        v = src
        for part in field.split("."):
            v = v.get(part) if isinstance(v, dict) else None
        out[b["key"]] = (v, b["a"]["value"])
    return out


def per_panel(index, since, filters, must_not=()):
    r = es("/" + index + "/_search?ignore_unavailable=true", {"size": 0, "query": {"bool": {
        "filter": [{"range": {"@timestamp": {"gte": since}}}] + list(filters), "must_not": list(must_not)}},
        "aggs": {"p": {"terms": {"field": "sentora.panel", "size": 20}}}})
    return {b["key"]: b["doc_count"] for b in r.get("aggregations", {}).get("p", {}).get("buckets", [])}


def check():
    L = limits()
    found = []  # (id, level, title, text, board)

    # websites down: the last N checks of a website all failed
    n = int(L["down_checks"])
    r = es("/heartbeat-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"tags": "sentora-site"}}, {"range": {"@timestamp": {"gte": "now-%dm" % (n + 10)}}}]}},
        "aggs": {"m": {"terms": {"field": "monitor.name", "size": 2000},
                       "aggs": {"t": {"top_hits": {"size": n, "sort": [{"@timestamp": "desc"}],
                                                   "_source": ["monitor.status", "error.message", "error.type"]}}}}}})
    for b in r.get("aggregations", {}).get("m", {}).get("buckets", []):
        hits = [h["_source"] for h in b["t"]["hits"]["hits"]]
        if len(hits) >= n and all(h.get("monitor", {}).get("status") == "down" for h in hits):
            why = str((hits[0].get("error") or {}).get("message") or (hits[0].get("error") or {}).get("type") or "no answer")[:200]
            found.append(("site-down:" + b["key"], "outage", b["key"] + " is down",
                          "The Elk node could not reach %s in its last %d checks (one a minute): %s." % (b["key"], n, why), "sentora-uptime"))

    # certificates running out
    r = es("/heartbeat-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"tags": "sentora-site"}}, {"range": {"@timestamp": {"gte": "now-2h"}}}, {"exists": {"field": "tls.server.x509.not_after"}}]}},
        "aggs": {"m": {"terms": {"field": "monitor.name", "size": 2000}, "aggs": {"e": {"max": {"field": "tls.server.x509.not_after"}}}}}})
    now = time.time()
    for b in r.get("aggregations", {}).get("m", {}).get("buckets", []):
        exp = b["e"].get("value")
        if exp is None:
            continue
        days = (exp / 1000 - now) / 86400
        if days < L["cert_days"]:
            when = datetime.datetime.fromtimestamp(exp / 1000, datetime.timezone.utc).strftime("%Y-%m-%d")
            found.append(("cert:" + b["key"], "outage" if days < 3 else "warning", b["key"] + "'s certificate runs out",
                          "The certificate of %s expires on %s (in %d day%s). Renew it in the panel (SSL / Let's Encrypt)."
                          % (b["key"], when, max(0, int(days)), "" if int(days) == 1 else "s"), "sentora-uptime"))

    # the panel's own figures
    for panel, (v, _) in latest_health("sentora.health.disk_root_used_pct").items():
        if v is not None and v >= L["disk_pct"]:
            found.append(("disk-root:" + panel, "warning", panel + ": the system disk is %d%% full" % v,
                          "The system disk of %s is %.1f%% full (the alert starts at %d%%)." % (panel, v, L["disk_pct"]), "sentora-health"))
    for panel, (v, _) in latest_health("sentora.health.disk_sites_used_pct").items():
        if v is not None and v >= L["disk_pct"]:
            found.append(("disk-sites:" + panel, "warning", panel + ": the websites' disk is %d%% full" % v,
                          "The disk with the websites on %s is %.1f%% full (the alert starts at %d%%)." % (panel, v, L["disk_pct"]), "sentora-health"))
    for panel, (v, _) in latest_health("sentora.health.mail_queue").items():
        if v is not None and v >= L["mail_queue"]:
            found.append(("mailq:" + panel, "warning", panel + ": %d mails waiting to go" % v,
                          "%d mails are waiting in the mail queue of %s (the alert starts at %d): look at Mail delivery for why."
                          % (v, panel, L["mail_queue"]), "sentora-delivery"))
    for panel, (_, avg) in latest_health("sentora.health.cpu_pct").items():
        if avg is not None and avg >= L["cpu_pct"]:
            found.append(("cpu:" + panel, "warning", panel + ": the CPU is busy",
                          "The CPU of %s was %.0f%% busy on average over the last 15 minutes." % (panel, avg), "sentora-health"))

    # bursts: failed logins, the web firewall, bounces
    for panel, c in per_panel("logs-sentora-*", "now-15m", [{"term": {"event.outcome": "failure"}}, {"exists": {"field": "event.action"}}],
                              [{"prefix": {"event.action": "waf-"}}]).items():
        if c >= L["failed_logins"]:
            found.append(("logins:" + panel, "warning", panel + ": %d failed logins in 15 minutes" % c,
                          "%d failed logins (SSH, the panel, mail, FTP) on %s in the last 15 minutes: someone may be guessing passwords."
                          % (c, panel), "sentora-security"))
    for panel, c in per_panel("logs-sentora-*", "now-15m", [{"term": {"event.action": "waf-blocked"}}]).items():
        if c >= L["waf_blocked"]:
            found.append(("waf:" + panel, "warning", panel + ": the web firewall stopped %d requests" % c,
                          "The web firewall stopped %d requests to the websites on %s in the last 15 minutes." % (c, panel), "sentora-firewall"))
    for panel, c in per_panel("logs-sentora-*", "now-1h", [{"term": {"sentora.mail.status": "bounced"}}]).items():
        if c >= L["bounces"]:
            found.append(("bounces:" + panel, "warning", panel + ": %d mails bounced in an hour" % c,
                          "%d mails from %s bounced in the last hour: a mailbox may be sending spam, or the server may be on a block list."
                          % (c, panel), "sentora-delivery"))

    # other sources (when switched on): a Veeam job whose last run failed, bursts of failed logins on a FortiGate
    r = es("/logs-veeam-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"event.action": "job-finished"}}, {"range": {"@timestamp": {"gte": "now-26h"}}}]}},
        "aggs": {"j": {"terms": {"field": "veeam_job.name", "size": 500},
                       "aggs": {"t": {"top_hits": {"size": 1, "sort": [{"@timestamp": "desc"}], "_source": ["veeam_job.result", "message", "observer.name"]}}}}}})
    for b in r.get("aggregations", {}).get("j", {}).get("buckets", []):
        h = b["t"]["hits"]["hits"][0]["_source"] if b["t"]["hits"]["hits"] else {}
        if (h.get("veeam_job") or {}).get("result") == "Failed":
            found.append(("veeam:" + slug(b["key"]), "outage", "Veeam: " + b["key"][:80] + " failed",
                          "The last run of the Veeam job %s on %s failed: %s" % (b["key"][:120], h.get("observer", {}).get("name", "?"), str(h.get("message", ""))[:300]),
                          "sentora-veeam"))
    # Veeam's API (when read): a machine whose newest backup is older than the limit
    r = es("/logs-veeam-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"event.action": "coverage"}}, {"range": {"@timestamp": {"gte": "now-3h"}}}]}},
        "aggs": {"h": {"terms": {"field": "host.name", "size": 2000}, "aggs": {"a": {"min": {"field": "veeam_job.hours_since"}}}}}})
    for b in r.get("aggregations", {}).get("h", {}).get("buckets", []):
        a = b["a"].get("value")
        if a is not None and a > L["backup_hours"]:
            found.append(("backup-stale:" + slug(b["key"]), "warning", "%s has no backup for %d hours" % (b["key"][:80], a),
                          "Veeam's newest backup of %s is %.0f hours old (the alert starts after %d hours): it may have dropped out of its job." % (b["key"][:120], a, L["backup_hours"]),
                          "sentora-coverage"))
    # Veeam's API: a backup repository filling up
    r = es("/logs-veeam-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"event.action": "repo-state"}}, {"range": {"@timestamp": {"gte": "now-3h"}}}]}},
        "aggs": {"r": {"terms": {"field": "veeam_repo.name", "size": 200},
                       "aggs": {"t": {"top_hits": {"size": 1, "sort": [{"@timestamp": "desc"}], "_source": ["veeam_repo.used_pct", "veeam_repo.free_gb"]}}}}}})
    for b in r.get("aggregations", {}).get("r", {}).get("buckets", []):
        h = (b["t"]["hits"]["hits"][0]["_source"].get("veeam_repo") or {}) if b["t"]["hits"]["hits"] else {}
        u = h.get("used_pct")
        if isinstance(u, (int, float)) and u >= L["repo_pct"]:
            found.append(("repo-full:" + slug(b["key"]), "outage" if u >= 97 else "warning", "Backup repository %s is %d%% full" % (b["key"][:80], u),
                          "The Veeam repository %s is %.0f%% full (%.0f GB free; the alert starts at %d%%): backups will fail when it is full." % (b["key"][:120], u, h.get("free_gb") or 0, L["repo_pct"]),
                          "sentora-backupwall"))
    for what, q, n_, board in (("admin logins on the FortiGate", {"term": {"event.category": "admin-login"}}, L["fgt_admin_fail"], "sentora-threats"),
                               ("VPN logins", {"term": {"event.category": "vpn"}}, L["fgt_vpn_fail"], "sentora-vpn")):
        c = count("logs-fortigate-*", "now-15m", [q, {"term": {"event.outcome": "failure"}}])
        if c >= n_:
            found.append(("fortigate:" + slug(what), "warning", "FortiGate: %d failed %s in 15 minutes" % (c, what),
                          "%d failed %s in the last 15 minutes: someone may be guessing passwords." % (c, what), board))

    # Active Directory (when switched on): lockouts, admin groups, a cleared log, guessing, a domain controller gone quiet
    r = es("/logs-windows-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 50, "sort": [{"@timestamp": "desc"}],
        "_source": ["@timestamp", "event.action", "user.name", "host.name", "group.name", "windows.member", "windows.from", "windows.admin_group"],
        "query": {"bool": {"filter": [{"range": {"@timestamp": {"gte": "now-24h"}}}, {"bool": {"should": [
            {"bool": {"filter": [{"term": {"event.action": "locked-out"}}, {"range": {"@timestamp": {"gte": "now-1h"}}}]}},
            {"bool": {"filter": [{"term": {"event.action": "group-member-added"}}, {"term": {"windows.admin_group": True}}]}},
            {"term": {"event.action": "log-cleared"}}], "minimum_should_match": 1}}]}}})
    for h in r.get("hits", {}).get("hits", []):
        x = h["_source"]
        act, who, dc = (x.get("event") or {}).get("action"), (x.get("user") or {}).get("name") or "?", (x.get("host") or {}).get("name") or "?"
        w = x.get("windows") or {}
        if act == "locked-out":
            found.append(("ad-lockout:" + slug(who), "warning", "%s is locked out" % who,
                          "Active Directory locked the account %s out (too many wrong passwords, from %s; domain controller %s). If that was not them, someone "
                          "is guessing its password." % (who, w.get("from") or "?", dc), "sentora-adwall"))
        elif act == "group-member-added":
            g = (x.get("group") or {}).get("name") or "?"
            found.append(("ad-admin:" + slug(g + "-" + str(w.get("member") or "?")), "outage", "%s was added to %s" % (str(w.get("member") or "?")[:80], g[:60]),
                          "%s added %s to the group %s (domain controller %s, %s). Admin groups rarely change: check it was meant."
                          % (who, w.get("member") or "?", g, dc, x.get("@timestamp", "")[:16].replace("T", " ")), "sentora-ad"))
        elif act == "log-cleared":
            found.append(("ad-cleared:" + slug(dc), "outage", "The security log of %s was cleared" % dc,
                          "%s cleared the security log of the domain controller %s. That hides what happened before: check who and why." % (who, dc), "sentora-ad"))
    r = es("/logs-windows-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"range": {"@timestamp": {"gte": "now-15m"}}}, {"term": {"event.outcome": "failure"}}, {"exists": {"field": "user.name"}}],
        "must_not": [{"term": {"event.action": "locked-out"}}]}},
        "aggs": {"u": {"terms": {"field": "user.name", "size": 50, "min_doc_count": int(L["ad_fail_user"])}}}})
    for b in r.get("aggregations", {}).get("u", {}).get("buckets", []):
        found.append(("ad-guess:" + slug(b["key"]), "warning", "%d failed logons for %s" % (b["doc_count"], b["key"]),
                      "%d failed logons for the account %s in the last 15 minutes: someone may be guessing its password (or a device keeps an old one)."
                      % (b["doc_count"], b["key"]), "sentora-ad"))
    q_h = int(L["ad_quiet_hours"])
    r = es("/logs-windows-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [{"range": {"@timestamp": {"gte": "now-1d"}}}]}},
        "aggs": {"d": {"terms": {"field": "host.name", "size": 50}, "aggs": {"last": {"max": {"field": "@timestamp"}}}}}})
    for b in r.get("aggregations", {}).get("d", {}).get("buckets", []):
        last = b["last"].get("value")
        if last is not None and now - last / 1000 > q_h * 3600:
            found.append(("ad-quiet:" + slug(b["key"]), "warning", "%s sent nothing for %d hours" % (b["key"], q_h),
                          "The domain controller %s has sent no security event for over %d hours. On it: Get-Service winlogbeat (and its log in C:\\ProgramData\\winlogbeat\\logs)."
                          % (b["key"], q_h), "sentora-adwall"))

    # VMware vSphere (when read): datastores, hosts, alarms, old snapshots, vCenter gone quiet
    r = es("/logs-vsphere-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [{"range": {"@timestamp": {"gte": "now-15m"}}}]}},
        "aggs": {"k": {"terms": {"field": "vmw.kind", "size": 10}, "aggs": {"n": {"terms": {"field": "vmw.name", "size": 2000},
            "aggs": {"t": {"top_hits": {"size": 1, "sort": [{"@timestamp": "desc"}], "_source": ["vmw"]}},
                     "cpu": {"avg": {"field": "vmw.cpu_pct"}}, "mem": {"avg": {"field": "vmw.mem_pct"}}}}}}}})
    for k in r.get("aggregations", {}).get("k", {}).get("buckets", []):
        for b in k["n"]["buckets"]:
            w = (b["t"]["hits"]["hits"][0]["_source"].get("vmw") or {}) if b["t"]["hits"]["hits"] else {}
            name, kind = b["key"], k["key"]
            u = w.get("used_pct")
            if kind == "datastore" and isinstance(u, (int, float)) and u * 100 >= L["vs_ds_pct"]:
                found.append(("vsphere-ds:" + slug(name), "outage" if u >= 0.97 else "warning", "Datastore %s is %d%% full" % (name[:80], u * 100),
                              "The vSphere datastore %s is %.0f%% full (%.0f GB free; the alert starts at %d%%): VMs on it stop when it is full."
                              % (name[:120], u * 100, w.get("free_gb") or 0, L["vs_ds_pct"]), "sentora-vswall"))
            if kind == "host" and w.get("status") == "red":
                found.append(("vsphere-host:" + slug(name), "outage", "vSphere host %s is red" % name[:80],
                              "vCenter shows the host %s in red: look at it in vCenter (its alarms say why)." % name[:120], "sentora-vswall"))
            if kind == "host":
                busy = max(b["cpu"].get("value") or 0, b["mem"].get("value") or 0)
                if busy * 100 >= L["vs_host_pct"]:
                    found.append(("vsphere-busy:" + slug(name), "warning", "vSphere host %s is %d%% busy" % (name[:80], busy * 100),
                                  "The host %s used %.0f%% of its CPU and %.0f%% of its memory on average over 15 minutes: its VMs may be slow."
                                  % (name[:120], (b["cpu"].get("value") or 0) * 100, (b["mem"].get("value") or 0) * 100), "sentora-vswall"))
            if w.get("alarm_status") == "red":
                found.append(("vsphere-alarm:" + slug(kind + "-" + name), "warning", "vSphere: %s on %s" % (str(w.get("alarm") or "an alarm")[:60], name[:60]),
                              "vCenter has a red alarm on the %s %s: %s." % ({"vm": "VM"}.get(kind, kind), name[:120], str(w.get("alarm") or "?")[:200]), "sentora-vsphere"))
            sd = w.get("snapshot_days")
            if kind == "vm" and isinstance(sd, (int, float)) and sd > L["vs_snapshot_days"]:
                found.append(("vsphere-snap:" + slug(name), "warning", "%s has a snapshot %d days old" % (name[:80], sd),
                              "The VM %s has a snapshot %.0f days old (the alert starts after %d days). Old snapshots keep growing and slow the VM: "
                              "delete it in vCenter once it is not needed." % (name[:120], sd, L["vs_snapshot_days"]), "sentora-vsphere"))
    if count("logs-vsphere-*", "now-1d", []) > 0 and count("logs-vsphere-*", "now-15m", []) == 0:
        found.append(("vsphere-quiet:vcenter", "warning", "Nothing from vCenter for 15 minutes",
                      "The Elk node has read nothing from vCenter for 15 minutes. On the Elk node: journalctl -u sentora-vsphere -n 30.", "sentora-vswall"))

    # an address that attacks in more than one place (the panel, the FortiGate, Microsoft 365) within an hour
    bad = {"bool": {"should": [{"term": {"event.outcome": "failure"}}, {"prefix": {"event.action": "waf-"}},
                               {"term": {"fortigate.subtype": "ips"}}, {"term": {"event.action": "ban"}}], "minimum_should_match": 1}}
    private = [{"term": {"source.ip": c}} for c in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.0/8", "fc00::/7", "fe80::/10", "::1")]
    r = es("/logs-sentora-*,logs-fortigate-*,logs-m365-*,logs-windows-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {
        "filter": [{"range": {"@timestamp": {"gte": "now-1h"}}}, {"exists": {"field": "source.ip"}}, bad], "must_not": private}},
        "aggs": {"ip": {"terms": {"field": "source.ip", "size": 200, "min_doc_count": int(L["attacker_events"])},
                        "aggs": {"src": {"terms": {"field": "data_stream.dataset", "size": 5}}}}}})
    names = {"sentora": "the panel", "fortigate": "the FortiGate", "m365": "Microsoft 365", "windows": "Active Directory"}
    for b in r.get("aggregations", {}).get("ip", {}).get("buckets", []):
        srcs = [(x["key"], x["doc_count"]) for x in b["src"]["buckets"]]
        if len(srcs) >= 2:
            where = ", ".join("%s (%d)" % (names.get(k, k), c) for k, c in srcs)
            found.append(("attacker:" + slug(b["key"]), "warning", "%s attacks in %d places" % (b["key"], len(srcs)),
                          "In the last hour the address %s tried %d times: %s. Blocking it on the firewall stops it everywhere." % (b["key"], b["doc_count"], where),
                          "sentora-attackers"))

    # Microsoft 365: one person signing in from two countries too close together, or from a country new for them
    r = es("/logs-m365-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 3000, "sort": [{"@timestamp": "asc"}],
        "_source": ["@timestamp", "user.name", "source.geo.country_iso_code", "source.ip"],
        "query": {"bool": {"filter": [{"term": {"m365.kind": "signin"}}, {"term": {"event.outcome": "success"}},
                                      {"range": {"@timestamp": {"gte": "now-%dh" % int(L["travel_hours"] * 2)}}}, {"exists": {"field": "source.geo.country_iso_code"}}]}}})
    seen = {}
    for h in r.get("hits", {}).get("hits", []):
        x = h["_source"]
        u, c = (x.get("user") or {}).get("name"), ((x.get("source") or {}).get("geo") or {}).get("country_iso_code")
        t = datetime.datetime.fromisoformat(x["@timestamp"].replace("Z", "+00:00")).timestamp()
        if not u or not c:
            continue
        prev = seen.get(u)
        if prev and prev[1] != c and t - prev[0] <= L["travel_hours"] * 3600 and now - t <= L["travel_hours"] * 3600:
            found.append(("office-travel:" + slug(u), "warning", "%s signed in from %s and %s" % (u, prev[1], c),
                          "Microsoft 365: %s signed in from %s and then from %s within %.1f hours. If that was not them (a VPN or a phone abroad can explain it), "
                          "reset their password and sign them out in Entra." % (u, prev[1], c, (t - prev[0]) / 3600), "sentora-m365"))
        seen[u] = (t, c)
    r = es("/logs-m365-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"m365.kind": "signin"}}, {"term": {"event.outcome": "success"}}, {"range": {"@timestamp": {"gte": "now-30d", "lt": "now-1h"}}}]}},
        "aggs": {"u": {"terms": {"field": "user.name", "size": 2000}, "aggs": {"c": {"terms": {"field": "source.geo.country_iso_code", "size": 50}}}}}})
    known = {b["key"]: {x["key"] for x in b["c"]["buckets"]} for b in r.get("aggregations", {}).get("u", {}).get("buckets", [])}
    r = es("/logs-m365-*/_search?ignore_unavailable=true&allow_no_indices=true", {"size": 0, "query": {"bool": {"filter": [
        {"term": {"m365.kind": "signin"}}, {"term": {"event.outcome": "success"}}, {"range": {"@timestamp": {"gte": "now-1h"}}}]}},
        "aggs": {"u": {"terms": {"field": "user.name", "size": 2000}, "aggs": {"c": {"terms": {"field": "source.geo.country_iso_code", "size": 50}}}}}})
    for b in r.get("aggregations", {}).get("u", {}).get("buckets", []):
        if b["key"] not in known:
            continue  # (no history for them yet: nothing is new)
        for x in b["c"]["buckets"]:
            if x["key"] not in known[b["key"]]:
                found.append(("office-new:" + slug(b["key"] + "-" + x["key"]), "warning", "%s signed in from a new country (%s)" % (b["key"], x["key"]),
                              "Microsoft 365: %s signed in from %s, where they have not signed in for at least 30 days." % (b["key"], x["key"]), "sentora-m365"))

    # the panel's logs gone quiet (rsyslog stopped forwarding); its health lines come another way and do not count
    m = int(L["quiet_minutes"])
    before = per_panel("logs-sentora-*", "now-1d", [], [{"term": {"sentora.log": "health"}}])
    recent = per_panel("logs-sentora-*", "now-%dm" % m, [], [{"term": {"sentora.log": "health"}}])
    for panel, c in before.items():
        if c > 0 and recent.get(panel, 0) == 0:
            found.append(("quiet:" + panel, "warning", panel + ": no logs for %d minutes" % m,
                          "The Elk node has had no log line from %s for %d minutes. On the panel: systemctl status rsyslog." % (panel, m),
                          "sentora-overview"))
    return found


def save(found):
    os.makedirs(STATE, exist_ok=True)
    path = os.path.join(STATE, "alerts.json")
    try:
        with open(path) as f:
            old = {a["id"]: a for a in json.load(f).get("alerts", [])}
    except (OSError, ValueError, KeyError, TypeError, AttributeError):
        old = {}
    now = int(time.time())
    alerts = [{"id": i, "level": lv, "title": t, "text": x, "board": bd, "since": int(old.get(i, {}).get("since", now))}
              for i, lv, t, x, bd in found[:200]]
    tmp = path + ".tmp"
    try:
        with open(os.path.join(CONF, "sources.json")) as f:
            src = {k: v for k, v in json.load(f).items() if k in ("fortigate", "veeam", "m365") and v in ("on", "off")}
    except (OSError, ValueError, AttributeError):
        src = {}
    with open(tmp, "w") as f:
        json.dump({"time": now, "ok": True, "alerts": alerts, "sources": src}, f)
    os.replace(tmp, path)
    return alerts


def failed(why):
    path = os.path.join(STATE, "alerts.json")
    try:
        with open(path) as f:
            d = json.load(f)
    except (OSError, ValueError):
        d = {"alerts": []}
    d.update({"time": int(time.time()), "ok": False, "error": str(why)[:300]})
    tmp = path + ".tmp"
    with open(tmp, "w") as f:
        json.dump(d, f)
    os.replace(tmp, path)


def loop():
    while True:
        try:
            save(check())
        except Exception as e:  # (the checks go on next time; the panel sees ok false)
            failed(e)
        time.sleep(EVERY)


class Answer(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(10)
        try:
            conn = self.server.tls.wrap_socket(self.request, server_side=True)
        except (OSError, ssl.SSLError):
            return  # (no certificate, or not the panel's)
        try:
            with open(os.path.join(STATE, "alerts.json"), "rb") as f:
                data = f.read(1 << 20)
        except OSError:
            data = b'{"ok": false, "error": "no check yet", "alerts": []}'
        try:
            conn.sendall(data)
            conn.unwrap()
        except (OSError, ssl.SSLError):
            pass
        finally:
            conn.close()


class Server(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True
    allow_reuse_address = True
    request_queue_size = 16


class Server6(Server):
    address_family = socket.AF_INET6

    def server_bind(self):
        self.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
        super().server_bind()


def serve():
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.minimum_version = ssl.TLSVersion.TLSv1_2
    tls.load_cert_chain(os.path.join(CONF, "node.crt"), os.path.join(CONF, "node.key"))
    tls.verify_mode = ssl.CERT_REQUIRED
    tls.load_verify_locations(os.path.join(CONF, "panel.crt"))  # the panel's own certificate, and only it
    threading.Thread(target=loop, daemon=True).start()
    try:
        srv = Server6(("::", PORT), Answer)  # IPv6 and IPv4
    except OSError:
        srv = Server(("0.0.0.0", PORT), Answer)  # (a system without IPv6)
    srv.tls = tls
    srv.serve_forever()


if __name__ == "__main__":
    what = sys.argv[1] if len(sys.argv) > 1 else ""
    if what == "serve":
        serve()
    elif what == "check":
        for a in save(check()):
            print("%-8s %s: %s" % (a["level"], a["title"], a["text"]))
        print("(no alerts)" if not json.load(open(os.path.join(STATE, "alerts.json")))["alerts"] else "")
    else:
        print(__doc__)
        sys.exit(2)
