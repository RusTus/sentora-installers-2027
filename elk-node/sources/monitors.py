"""Sentora Elk node: the uptime checks from the panel's list of websites (sentora-elk-node monitors, a timer, every 5 minutes).

Writes Heartbeat's checks (/etc/heartbeat/monitors.d/sentora-sites.yml) and the two files only Heartbeat sees (its unit binds
them over /etc/resolv.conf and /etc/hosts, sentora-net.conf):
- its DNS: the servers chosen with 'sentora-elk-node dns' (SERVER.., or "panel": the panel's own DNS), else the real ones
  behind systemd-resolved (its 127.0.0.53 does not pass .local names on to them), else the system's;
- its hosts: with 'sites-at' auto (the default) the websites are checked at the panel's own address when that is an inside one
  (an office network seldom reaches its own public address from inside); panel: always there; dns: where DNS points; or one
  address.
A website no DNS knows (not the office's or the system's, nor the panel's own when the checks go to the panel) and that is not
in this server's /etc/hosts is not in use yet: it is left out of the checks,
so it makes no "down" alert, and 'sentora-elk-node status' lists it. A DNS that does not answer leaves every website in.
Written by RusTus, 2026-11-22. GPL-3.0.
"""
import ipaddress
import json
import os
import random
import re
import socket
import struct
import subprocess

C, H = "/etc/sentora-elk", "/etc/sentora-elk/heartbeat"
SRC, DST = "/var/lib/logstash/sentora/sites.json", "/etc/heartbeat/monitors.d/sentora-sites.yml"


def rd(p):
    try:
        with open(p) as f:
            return f.read()
    except OSError:
        return ""


def ip(a):
    try:
        return ipaddress.ip_address(a)
    except ValueError:
        return None


def put(p, text, mode=0o644):
    """Write only when it changed, in place (Heartbeat holds a bind of this very file)."""
    if rd(p) != text:
        with open(p, "w") as f:
            f.write(text)
        os.chmod(p, mode)
        return True
    return False


INSIDE = [ipaddress.ip_network(n) for n in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "fc00::/7")]


def inside(a):
    """An address of an inside (private) network: RFC 1918, carrier-grade NAT, IPv6 unique local."""
    return any(a.version == n.version and a in n for n in INSIDE)


def panel_addr():
    """The panel's address, as the node reached it when it was installed."""
    host = re.sub(r"^[a-z]+://", "", rd(C + "/panel-url").strip()).split("/")[0]
    if host.startswith("["):
        host = host[1:host.find("]")]
    elif host.count(":") == 1:
        host = host.split(":")[0]
    a = ip(host)
    if a is None and host:
        try:
            a = ip(socket.getaddrinfo(host, 443, proto=socket.IPPROTO_TCP)[0][4][0])
        except OSError:
            a = None
    return a


def ask(server, name, qtype):
    """One DNS question over UDP: 'yes' (an answer), 'no' (the name or the record is not there), 'fail' (an error answer,
    such as systemd-resolved's for a .local name) or None (no answer at all)."""
    qid = random.randint(0, 65535)
    q = struct.pack(">HHHHHH", qid, 0x0100, 1, 0, 0, 0)
    for label in name.rstrip(".").split("."):
        q += bytes([len(label)]) + label.encode("ascii")
    q += b"\0" + struct.pack(">HH", qtype, 1)
    fam = socket.AF_INET6 if ":" in server else socket.AF_INET
    s = socket.socket(fam, socket.SOCK_DGRAM)
    s.settimeout(2)
    try:
        s.sendto(q, (server, 53))
        r, _ = s.recvfrom(4096)
    except OSError:
        return None
    finally:
        s.close()
    if len(r) < 12 or struct.unpack(">H", r[:2])[0] != qid:
        return None
    rcode, ancount = r[3] & 15, struct.unpack(">H", r[6:8])[0]
    if rcode == 3:
        return "no"
    if rcode != 0:
        return "fail"
    return "yes" if ancount > 0 else "no"


def main():
    try:
        d = json.load(open(SRC))
    except (OSError, ValueError):
        return
    sites = set()
    for x in ((d.get("sites") or [])[:2000] if isinstance(d, dict) else []):
        h = str(x.get("host", "")).lower() if isinstance(x, dict) else ""
        if re.fullmatch(r"[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?", h) and "." in h:
            sites.add((h, x.get("https") is True))
    os.makedirs(H, exist_ok=True)
    os.chmod(H, 0o755)
    fresh = not os.path.exists(H + "/hosts")
    pa = panel_addr()

    # Heartbeat's DNS
    chosen = rd(C + "/dns").split()
    want = [str(pa)] if chosen == ["panel"] and pa else [a for a in chosen if ip(a)][:3]
    base = rd("/run/systemd/resolve/resolv.conf") or rd("/etc/resolv.conf")
    keep = [l for l in base.splitlines() if l.strip() and not l.startswith(("#", ";")) and (not want or not l.startswith("nameserver"))]
    put(H + "/resolv.conf", "# Made by sentora-elk-node monitors: the DNS the uptime checks ask (sentora-elk-node dns)\n"
        + "".join("nameserver %s\n" % a for a in want) + "\n".join(keep) + "\n")

    # where the websites are checked (Heartbeat's hosts, below)
    at, addr = (rd(C + "/sites-at").strip() or "auto"), ""
    if at in ("auto", "panel"):
        if pa is not None and (at == "panel" or inside(pa)):
            addr = str(pa)
    elif ip(at):
        addr = at
    # which websites some DNS knows: Heartbeat's servers, and the panel's own DNS when the checks go to the panel
    servers, stub = [], []
    for l in rd(H + "/resolv.conf").splitlines():
        p = l.split()
        if len(p) >= 2 and p[0] == "nameserver" and ip(p[1]):
            (stub if p[1] in ("127.0.0.53", "127.0.0.54") else servers).append(p[1])
    servers = servers or stub
    if addr and pa and str(pa) not in servers:
        servers.append(str(pa))
    servers = servers[:4]
    dead, unknown = set(), []
    named = set()
    for l in rd("/etc/hosts").splitlines():
        named.update(w.lower() for w in l.split("#")[0].split()[1:])
    for h, tls in sorted(sites):
        if h in named:
            continue
        said = []
        for sv in servers:
            if sv in dead:
                continue
            a = ask(sv, h, 1)
            if a is None:
                dead.add(sv)
                continue
            if a == "no":
                a = ask(sv, h, 28) or "fail"
            said.append(a)
            if a == "yes":
                break
        # (left out only when every DNS that answered said the name is not there: an error or no answer proves nothing)
        if said and all(x == "no" for x in said):
            unknown.append(h)
    checked = sorted((h, tls) for h, tls in sites if h not in unknown)
    put(H + "/not-in-dns", "".join(h + "\n" for h in sorted(set(unknown))))

    # the checks
    mon = [{"type": "http", "id": "site-" + h, "name": h, "urls": [("https://" if tls else "http://") + h + "/"],
            "schedule": "@every 1m", "timeout": "15s", "tags": ["sentora-site"],
            "check.response.status": [200, 201, 204, 301, 302, 303, 307, 308, 401, 403, 404]} for h, tls in checked]
    new = "# Made by sentora-elk-node monitors from the panel list of websites; replaced when it changes.\n" + json.dumps(mon, indent=1) + "\n"
    if rd(DST) != new:
        tmp = DST + ".tmp"
        with open(tmp, "w") as f:
            f.write(new)
        os.chmod(tmp, 0o640)
        os.replace(tmp, DST)
        print("%d website(s) checked every minute%s" % (len(mon), ", %d not in any DNS left out" % len(unknown) if unknown else ""))

    hosts = rd("/etc/hosts").rstrip("\n") + "\n"
    if addr:
        hosts += "# the panel websites, checked at %s (sentora-elk-node sites-at)\n" % addr + "".join("%s %s\n" % (addr, h) for h, _ in checked)
    put(H + "/hosts", hosts)
    put(H + "/at", (addr + (" (the panel)" if pa is not None and addr == str(pa) else "") if addr else "where DNS points") + "\n")
    if fresh:
        subprocess.call(["systemctl", "try-restart", "heartbeat-elastic"])


if __name__ == "__main__":
    main()
