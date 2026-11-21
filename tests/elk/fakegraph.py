"""A stand-in for Microsoft's login and Graph (the Elk node test): answers as they do, over HTTPS with the test's
own certificate. Usage: fakegraph.py PORT CERT KEY"""
import http.server, json, ssl, sys, datetime, urllib.parse
port, cert, key = int(sys.argv[1]), sys.argv[2], sys.argv[3]
now = datetime.datetime.now(datetime.timezone.utc)
def t(m): return (now - datetime.timedelta(minutes=m)).strftime("%Y-%m-%dT%H:%M:%SZ")
day = now.strftime("%Y-%m-%d")
SIGNINS = {"value": [
  {"id": "s1", "createdDateTime": t(30), "userPrincipalName": "jsmith@office.example", "userDisplayName": "J Smith", "appDisplayName": "Microsoft Teams",
   "ipAddress": "81.2.69.142", "clientAppUsed": "Mobile Apps and Desktop clients", "conditionalAccessStatus": "success", "isInteractive": True,
   "status": {"errorCode": 0, "additionalDetails": "MFA requirement satisfied by claim in the token"},
   "location": {"city": "London", "state": "London", "countryOrRegion": "GB", "geoCoordinates": {"latitude": 51.5, "longitude": -0.12}},
   "deviceDetail": {"operatingSystem": "Windows 11", "browser": "Edge"}},
  {"id": "s2", "createdDateTime": t(20), "userPrincipalName": "mlee@office.example", "appDisplayName": "Office 365 Exchange Online",
   "ipAddress": "185.220.101.7", "clientAppUsed": "IMAP4", "isInteractive": False,
   "status": {"errorCode": 50126, "failureReason": "Invalid username or password."},
   "location": {"countryOrRegion": "DE", "geoCoordinates": {"latitude": 50.1, "longitude": 8.6}}}],
  "@odata.nextLink": None}
REPORTS = {
  "getTeamsUserActivityCounts(period='D7')": "Report Refresh Date,Report Date,Team Chat Messages,Private Chat Messages,Calls,Meetings,Post Messages,Reply Messages,Urgent Messages,Report Period\n%s,%s,120,340,22,9,3,4,0,7\n" % (day, day),
  "getEmailActivityCounts(period='D7')": "Report Refresh Date,Send,Receive,Read,Meeting Created,Meeting Interacted,Report Date,Report Period\n%s,410,1880,1500,12,30,%s,7\n" % (day, day),
  "getTeamsUserActivityUserDetail(period='D7')": "﻿Report Refresh Date,User Id,User Principal Name,Last Activity Date,Is Deleted,Deleted Date,Assigned Products,Team Chat Message Count,Private Chat Message Count,Call Count,Meeting Count,Report Period\n%s,u1,jsmith@office.example,%s,False,,M365,50,120,10,4,7\n%s,u2,mlee@office.example,%s,False,,M365,70,220,12,5,7\n" % (day, day, day, day),
  "getEmailActivityUserDetail(period='D7')": "Report Refresh Date,User Principal Name,Display Name,Is Deleted,Deleted Date,Last Activity Date,Send Count,Receive Count,Read Count,Report Period\n%s,jsmith@office.example,J Smith,False,,%s,200,900,800,7\n%s,mlee@office.example,M Lee,False,,%s,210,980,700,7\n" % (day, day, day, day)}
ISSUES = {"value": [{"id": "EX123456", "title": "Some users can't send mail with attachments", "service": "Exchange Online", "classification": "incident",
                      "status": "serviceDegradation", "startDateTime": t(90), "impactDescription": "Users may see delays.", "isResolved": False}]}
OVERVIEWS = {"value": [{"service": "Exchange Online", "status": "serviceDegradation", "id": "Exchange"},
                       {"service": "Microsoft Teams", "status": "serviceOperational", "id": "microsoftteams"},
                       {"service": "SharePoint Online", "status": "serviceOperational", "id": "SharePoint"},
                       {"service": "Microsoft Entra", "status": "serviceInterruption", "id": "AzureActiveDirectory"}]}
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, body, ctype="application/json", extra=None):
        b = body.encode() if isinstance(body, str) else body
        self.send_response(code); self.send_header("Content-Type", ctype); self.send_header("Content-Length", str(len(b)))
        for k, v in (extra or {}).items(): self.send_header(k, v)
        self.end_headers(); self.wfile.write(b)
    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0)); f = urllib.parse.parse_qs(self.rfile.read(n).decode())
        if self.path.endswith("/oauth2/v2.0/token") and f.get("client_secret") == ["test-secret"] and f.get("grant_type") == ["client_credentials"]:
            return self.send(200, json.dumps({"access_token": "tok123", "expires_in": 3600, "token_type": "Bearer"}))
        self.send(401, json.dumps({"error": "invalid_client"}))
    def do_GET(self):
        p = urllib.parse.unquote(self.path)
        if p.startswith("/download/"):  # (pre-signed: no token, as Microsoft's report downloads)
            if self.headers.get("Authorization"): return self.send(400, "token sent to the download")
            return self.send(200, REPORTS.get(p[len("/download/"):], ""), "text/csv")
        if self.headers.get("Authorization") != "Bearer tok123": return self.send(401, "{}")
        if p.startswith("/v1.0/auditLogs/signIns"): return self.send(200, json.dumps(SIGNINS))
        if p.startswith("/v1.0/reports/"):
            name = p[len("/v1.0/reports/"):]
            if name in REPORTS: return self.send(302, "", extra={"Location": "https://%s/download/%s" % (self.headers.get("Host"), urllib.parse.quote(name))})
        if p.startswith("/v1.0/admin/serviceAnnouncement/issues"): return self.send(200, json.dumps(ISSUES))
        if p.startswith("/v1.0/admin/serviceAnnouncement/healthOverviews"): return self.send(200, json.dumps(OVERVIEWS))
        self.send(404, "{}")
srv = http.server.ThreadingHTTPServer(("0.0.0.0", port), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert, key)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
