#!/usr/bin/env python3
"""Send log lines to an Elk node as the panel's rsyslog does: JSON lines over TLS on port 6514, with a client
certificate, checking the node's certificate against the node's authority and its name (sentora-elk-node).

    send.py HOST NODE-CA CERT KEY LINES.tsv [PANEL-NAME]    prints the number sent; exit 1 when refused
"""
import datetime
import json
import socket
import ssl
import sys
import time

host, ca, cert, key, lines = sys.argv[1:6]
panel = sys.argv[6] if len(sys.argv) > 6 else 'panel.example.test'
ctx = ssl.create_default_context(cafile=ca)
ctx.load_cert_chain(cert, key)
now = datetime.datetime.now(datetime.timezone.utc).isoformat()
n = 0
try:
    s = ctx.wrap_socket(socket.create_connection((host, 6514), timeout=20), server_hostname='sentora-elk-node')
    for line in open(lines, encoding='utf-8', errors='replace'):
        if line.startswith('#') or not line.strip():
            continue
        kind, acct, dom, msg = line.rstrip('\n').split('\t', 3)
        s.sendall((json.dumps({'@timestamp': now, 'sentora_host': panel, 'sentora_log': kind, 'sentora_account': acct,
                               'sentora_domain': dom, 'message': msg}) + '\n').encode())
        n += 1
    # (Logstash reads what was sent before the connection closes; a moment for it, then a plain close)
    time.sleep(5)
    s.sendall(b'')
    s.close()
except (ssl.SSLError, OSError) as e:
    print('refused:', e)
    sys.exit(1)
print(n)
