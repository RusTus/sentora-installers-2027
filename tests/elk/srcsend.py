"""Send sample lines to the Elk node as a FortiGate or a Veeam server would (the Elk node test).
Usage: srcsend.py HOST PORT CA-FILE CERT|- KEY|- FILE   (octet-counted syslog over TLS, as FortiOS "reliable" and Veeam send)
Prints "sent N" or "refused: ..."."""
import socket, ssl, sys, time
host, port, ca, cert, key, path = sys.argv[1:7]
ctx = ssl.create_default_context(cafile=ca); ctx.check_hostname = False
if cert != "-":
    ctx.load_cert_chain(cert, key)
n = 0
try:
    s = ctx.wrap_socket(socket.create_connection((host, int(port)), timeout=10))
    for l in open(path):
        l = l.rstrip("\n")
        if not l:
            continue
        m = (l if l.startswith("<") else "<189>" + l).encode()
        s.sendall(str(len(m)).encode() + b" " + m)
        n += 1
    time.sleep(2)  # (the node reads while the connection is open)
    s.close()
    print("sent", n)
except Exception as e:
    print("refused:", type(e).__name__, e)
