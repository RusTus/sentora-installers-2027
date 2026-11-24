"""Mail check for Sentora-2027 for Windows, run on the server after the installer (Python's standard library only).
    python mail-test.py DOMAIN USER1 PASSWORD1 USER2 PASSWORD2
USER1 and USER2 are mailboxes made with mailuser.ps1. Checks: mail from outside on 25, mail sent with a login on 587
(STARTTLS) and 465, IMAP (993 and 143 with STARTTLS), POP3 (995), a wrong password refused, calendars and contacts
through Apache's HTTPS (/dav), plain HTTP sent on to HTTPS, and Roundcube's login. Exits 1 on the first failure list."""
import email.message, http.cookiejar, imaplib, poplib, re, smtplib, ssl, sys, time, urllib.parse, urllib.request, urllib.error, base64

dom, u1, p1, u2, p2 = sys.argv[1:6]
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE  # the certificate made at install
fails = []


def check(ok, text):
    print(('ok   ' if ok else 'FAIL ') + text)
    if not ok:
        fails.append(text)


def msg(frm, to, subject):
    m = email.message.EmailMessage(); m['From'] = frm; m['To'] = to; m['Subject'] = subject
    m['Date'] = email.utils.formatdate(localtime=True); m['Message-ID'] = email.utils.make_msgid(); m.set_content('Sent by mail-test.py')
    return m


import email.utils
# 1. from outside, port 25, no login (as another mail server would)
try:
    s = smtplib.SMTP('127.0.0.1', 25, timeout=30); s.ehlo('outside.example.net'); s.starttls(context=ctx); s.ehlo('outside.example.net')
    s.send_message(msg('carol@outside.example.net', u1, 'From outside on 25')); s.quit(); check(True, 'port 25: mail from outside accepted (STARTTLS)')
except Exception as e:
    check(False, 'port 25: %s' % e)
# 2. relaying to another domain without a login is refused
try:
    s = smtplib.SMTP('127.0.0.1', 25, timeout=30); s.ehlo('outside.example.net')
    try:
        s.send_message(msg('carol@outside.example.net', 'someone@elsewhere.example.org', 'relay?')); check(False, 'port 25 relays for strangers')
    except smtplib.SMTPRecipientsRefused:
        check(True, 'port 25: relaying for strangers refused')
    s.quit()
except Exception as e:
    check(False, 'relay check: %s' % e)
# 3. with a login: 587 (STARTTLS) and 465 (TLS)
try:
    s = smtplib.SMTP('127.0.0.1', 587, timeout=30); s.ehlo(); s.starttls(context=ctx); s.ehlo(); s.login(u2, p2)
    s.send_message(msg(u2, u1, 'Sent on 587')); s.quit(); check(True, 'port 587: login and send')
except Exception as e:
    check(False, 'port 587: %s' % e)
try:
    s = smtplib.SMTP_SSL('127.0.0.1', 465, context=ctx, timeout=30); s.login(u2, p2)
    s.send_message(msg(u2, u1, 'Sent on 465')); s.quit(); check(True, 'port 465: login and send')
except Exception as e:
    check(False, 'port 465: %s' % e)
# 4. no login over plain text on 587 before STARTTLS
try:
    s = smtplib.SMTP('127.0.0.1', 587, timeout=30); s.ehlo()
    check('auth' not in s.esmtp_features, 'port 587: no login offered before STARTTLS'); s.quit()
except Exception as e:
    check(False, 'port 587 plain: %s' % e)
time.sleep(4)
# 5. IMAP 993 and 143 (STARTTLS), POP3 995
want = ['From outside on 25', 'Sent on 587', 'Sent on 465']
try:
    m = imaplib.IMAP4_SSL('127.0.0.1', 993, ssl_context=ctx); m.login(u1, p1)
    where = {}
    for box in ('INBOX', '"Junk Mail"'):
        m.select(box, readonly=True)
        typ, data = m.search(None, 'ALL')
        for n in data[0].split():
            t, d = m.fetch(n, '(BODY.PEEK[HEADER.FIELDS (SUBJECT)])'); where[d[0][1].decode(errors='replace').strip()] = box.strip('"')
    m.logout()
    for w in want:
        box = next((b for x, b in where.items() if w in x), None)
        # (mail from a made-up domain with no DNS may go to Junk Mail: Stalwart's spam filter at work)
        check(box is not None, 'IMAP 993: "%s" delivered (%s)' % (w, box or 'missing'))
    check(where and all(b == 'INBOX' for x, b in where.items() if 'Sent on' in x), 'IMAP 993: mail sent with a login is in the inbox, not junk')
except Exception as e:
    check(False, 'IMAP 993: %s' % e)
try:
    m = imaplib.IMAP4('127.0.0.1', 143); m.starttls(ssl_context=ctx); m.login(u1, p1); m.logout(); check(True, 'IMAP 143: STARTTLS and login')
except Exception as e:
    check(False, 'IMAP 143: %s' % e)
try:
    m = imaplib.IMAP4_SSL('127.0.0.1', 993, ssl_context=ctx); m.login(u1, 'not-the-password'); check(False, 'IMAP: a wrong password was accepted')
except imaplib.IMAP4.error:
    check(True, 'IMAP: a wrong password is refused')
try:
    p = poplib.POP3_SSL('127.0.0.1', 995, context=ctx); p.user(u1); p.pass_(p1); n = p.stat()[0]; p.quit(); check(n >= 2, 'POP3 995: %d messages in the inbox' % n)
except Exception as e:
    check(False, 'POP3 995: %s' % e)

# 6. calendars and contacts through Apache (HTTPS, /dav passed on to Stalwart)
auth = 'Basic ' + base64.b64encode(('%s:%s' % (u1, p1)).encode()).decode()
def dav(method, path, body=None, ctype=None, extra=None):
    h = {'Authorization': auth}
    if ctype: h['Content-Type'] = ctype
    h.update(extra or {})
    r = urllib.request.Request('https://127.0.0.1' + path, data=body, method=method, headers=h)
    try:
        with urllib.request.urlopen(r, context=ctx, timeout=30) as x:
            return x.status, x.read().decode(errors='replace')
    except urllib.error.HTTPError as e:
        return e.code, ''
q = urllib.parse.quote(u1)
st, _ = dav('PUT', '/dav/cal/%s/default/sentora-test-1.ics' % q, ('BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sentora//Test//EN\r\nBEGIN:VEVENT\r\n'
            'UID:sentora-test-1\r\nDTSTAMP:20261122T120000Z\r\nDTSTART:20261125T150000Z\r\nDTEND:20261125T160000Z\r\nSUMMARY:Planning meeting\r\n'
            'END:VEVENT\r\nEND:VCALENDAR\r\n').encode(), 'text/calendar')
check(st in (201, 204), 'CalDAV over https://.../dav: an event saved (%s)' % st)
st, _ = dav('PUT', '/dav/card/%s/default/sentora-test-2.vcf' % q, ('BEGIN:VCARD\r\nVERSION:3.0\r\nUID:sentora-test-2\r\nFN:Bob Builder\r\n'
            'N:Builder;Bob;;;\r\nEMAIL:bob@outside.example.net\r\nEND:VCARD\r\n').encode(), 'text/vcard')
check(st in (201, 204), 'CardDAV over https://.../dav: a contact saved (%s)' % st)
st, _ = dav('PROPFIND', '/.well-known/caldav', extra={'Depth': '0'})
check(st in (207, 301, 302, 307, 308), 'CalDAV discovery (/.well-known/caldav): %s' % st)

# 7. plain HTTP sends these addresses on to HTTPS
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None
op = urllib.request.build_opener(NoRedirect)
for path in ('/webmail/', '/Microsoft-Server-ActiveSync', '/dav/'):
    try:
        op.open('http://127.0.0.1' + path, timeout=15); check(False, 'http://...%s answered without HTTPS' % path)
    except urllib.error.HTTPError as e:
        check(e.code in (301, 302) and e.headers.get('Location', '').startswith('https://'), 'http://...%s -> %s %s' % (path, e.code, e.headers.get('Location')))

# 8. Roundcube: the login page, then a login
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), urllib.request.HTTPSHandler(context=ctx))
try:
    page = op.open('https://127.0.0.1/webmail/', timeout=30).read().decode(errors='replace')
    tok = re.search(r'name="_token" value="([^"]+)"', page)
    check(bool(tok) and 'rcmloginuser' in page, 'webmail: the login page')
    data = urllib.parse.urlencode({'_token': tok.group(1) if tok else '', '_task': 'login', '_action': 'login', '_timezone': 'America/Chicago',
                                   '_url': '', '_user': u1.split('@')[0], '_pass': p1}).encode()
    r = op.open(urllib.request.Request('https://127.0.0.1/webmail/?_task=login', data=data), timeout=60)
    after = r.read().decode(errors='replace')
    check('_task=mail' in r.geturl() or 'rcmail.set_env' in after and '"task":"mail"' in after.replace(' ', ''),
          'webmail: %s signs in (with the bare user name; the domain is added)' % u1)
    check('Sent on 465' in after or '_action=list' in after or 'messagelist' in after, 'webmail: the inbox opens')
except Exception as e:
    check(False, 'webmail: %s' % e)

# 9. the virus check (ClamAV): the EICAR test file (the harmless standard test "virus", put together here so no file on
# disk holds it) is refused when sent, a clean attachment is not
if '--virus' in sys.argv:
    import base64
    eicar = base64.b64decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=')
    for subj, data, bad in (('A clean attachment', b'nothing to see here', False), ('The EICAR test file', eicar, True)):
        m = msg(u2, u1, subj); m.add_attachment(data, maintype='application', subtype='octet-stream', filename='file.com')
        try:
            s = smtplib.SMTP('127.0.0.1', 587, timeout=120); s.ehlo(); s.starttls(context=ctx); s.ehlo(); s.login(u2, p2)
            try:
                s.send_message(m); check(not bad, 'ClamAV: "%s" accepted' % subj)
            except smtplib.SMTPDataError as e:
                check(bad and e.smtp_code == 554, 'ClamAV: "%s" refused: %s %s' % (subj, e.smtp_code, e.smtp_error.decode(errors='replace')))
            s.quit()
        except Exception as e:
            check(False, 'ClamAV check: %s' % e)

print('%d failure(s)' % len(fails))
sys.exit(1 if fails else 0)
