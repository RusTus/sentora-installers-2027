"""A stand-in for Veeam Backup & Replication's REST API (the Elk node test), on its own self-signed certificate as Veeam's is.
Usage: fakeveeam.py PORT CERT KEY"""
import http.server, json, ssl, sys, datetime, urllib.parse
port, cert, key = int(sys.argv[1]), sys.argv[2], sys.argv[3]
now = datetime.datetime.now(datetime.timezone.utc)
def ago(h): return (now - datetime.timedelta(hours=h)).strftime("%Y-%m-%dT%H:%M:%S.%f0+00:00")
POINTS = [{"id": "p%d" % i, "name": n, "platformName": "VMware", "creationTime": ago(h), "backupId": "b1"} for i, (n, h) in
          enumerate([("DC01", 7), ("DC01", 31), ("FILESRV01", 8), ("SQL01", 9), ("OLDAPP02", 120), ("OLDAPP02", 144)])]
POINTS.sort(key=lambda p: p["creationTime"], reverse=True)
JOBS = [{"id": "j1", "name": "Nightly Servers", "type": "Backup", "status": "Inactive", "lastResult": "Success", "lastRun": ago(7), "nextRun": ago(-17)},
        {"id": "j2", "name": "Offsite Copy", "type": "BackupCopy", "status": "Inactive", "lastResult": "Failed", "lastRun": ago(5), "nextRun": ago(-19)}]
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, obj):
        b = json.dumps(obj).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0)); f = urllib.parse.parse_qs(self.rfile.read(n).decode())
        if not self.headers.get("x-api-version"): return self.send(400, {"errorCode": "BadRequest", "message": "x-api-version missing"})
        if self.path == "/api/oauth2/token" and f.get("grant_type") == ["password"] and f.get("username") == ["OFFICE\\viewer"] and f.get("password") == ["veeam-test"]:
            return self.send(200, {"access_token": "vtok", "token_type": "bearer", "expires_in": 900})
        self.send(401, {"errorCode": "Unauthorized"})
    def do_GET(self):
        if self.headers.get("Authorization") != "Bearer vtok": return self.send(401, {"errorCode": "Unauthorized"})
        p = urllib.parse.urlparse(self.path); qs = urllib.parse.parse_qs(p.query); skip = int(qs.get("skip", ["0"])[0])
        if p.path == "/api/v1/objectRestorePoints": return self.send(200, {"data": POINTS[skip:skip + 1000], "pagination": {"total": len(POINTS)}})
        if p.path == "/api/v1/jobs/states": return self.send(200, {"data": JOBS, "pagination": {"total": len(JOBS)}})
        if p.path == "/api/v1/backupInfrastructure/repositories/states":
            return self.send(200, {"data": [{"id": "r1", "name": "Main Repository", "type": "WinLocal", "hostName": "VBR01", "path": "E:\\Backups", "capacityGB": 8000.0, "freeGB": 1200.0, "usedSpaceGB": 6800.0},
                                            {"id": "r2", "name": "Cloud-Repo", "type": "AmazonS3", "hostName": "", "path": "", "capacityGB": 20000.0, "freeGB": 15000.0, "usedSpaceGB": 5000.0}]})
        self.send(404, {})
srv = http.server.ThreadingHTTPServer(("0.0.0.0", port), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert, key)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
