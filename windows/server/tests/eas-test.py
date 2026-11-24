"""ActiveSync check for Sentora-2027 for Windows: signs in like a phone (Provision, FolderSync, Sync) and prints what it sees.
    python eas-test.py URL USER PASSWORD [expect-mail-subject] [expect-event-subject] [expect-contact-name]
Exits 1 when a sign-in, a sync or an expected item fails. Uses only Python's standard library (WBXML written by hand)."""
import base64, ssl, sys, urllib.request, urllib.error

# code pages and tags (MS-ASWBXML): (page, tag) by name
T = {
    'Sync': (0, 0x05), 'Responses': (0, 0x06), 'Add': (0, 0x07), 'Change': (0, 0x08), 'SyncKey': (0, 0x0B),
    'ServerId': (0, 0x0D), 'Status': (0, 0x0E), 'Collection': (0, 0x0F), 'Class': (0, 0x10), 'CollectionId': (0, 0x12),
    'GetChanges': (0, 0x13), 'MoreAvailable': (0, 0x14), 'WindowSize': (0, 0x15), 'Commands': (0, 0x16),
    'Collections': (0, 0x1C), 'ApplicationData': (0, 0x1D),
    'FileAs': (1, 0x1E), 'FirstName': (1, 0x1F), 'LastName': (1, 0x2A), 'Email1Address': (1, 0x1B),
    'Subject': (2, 0x14), 'From': (2, 0x18), 'To': (2, 0x16), 'DateReceived': (2, 0x0F),
    'CalSubject': (4, 0x26), 'StartTime': (4, 0x27), 'EndTime': (4, 0x12),
    'DisplayName': (7, 0x07), 'FServerId': (7, 0x08), 'ParentId': (7, 0x09), 'Type': (7, 0x0A), 'FStatus': (7, 0x0C),
    'Changes': (7, 0x0E), 'FAdd': (7, 0x0F), 'FSyncKey': (7, 0x12), 'FolderSync': (7, 0x16), 'Count': (7, 0x17),
    'Provision': (14, 0x05), 'Policies': (14, 0x06), 'Policy': (14, 0x07), 'PolicyType': (14, 0x08),
    'PolicyKey': (14, 0x09), 'PStatus': (14, 0x0B),
    'Body': (17, 0x0A), 'Data': (17, 0x0B), 'BodyType': (17, 0x06),
}
NAME = {v: k for k, v in T.items()}


def enc(node):
    """node = (name, children-list | text | None) -> WBXML bytes (version 1.3, public id 1, UTF-8)."""
    out, page = bytearray(b'\x03\x01\x6a\x00'), [0]

    def walk(n):
        name, kids = n
        p, t = T[name]
        if p != page[0]:
            out.extend((0x00, p)); page[0] = p
        if kids is None:
            out.append(t)
            return
        out.append(t | 0x40)
        if isinstance(kids, str):
            out.append(0x03); out.extend(kids.encode()); out.append(0x00)
        else:
            for k in kids:
                walk(k)
        out.append(0x01)
    walk(node)
    return bytes(out)


def dec(b):
    """WBXML bytes -> list of (path, text) for every element with text."""
    i, page, stack, res = 4, 0, [], []
    def mb():
        nonlocal i
        v = 0
        while True:
            c = b[i]; i += 1; v = (v << 7) | (c & 0x7F)
            if not c & 0x80:
                return v
    i = 3; mb(); i += 0  # skip strtbl length
    while i < len(b):
        c = b[i]; i += 1
        if c == 0x00:
            page = b[i]; i += 1
        elif c == 0x01:
            if stack: stack.pop()
        elif c == 0x03:
            j = b.index(0, i); res.append(('/'.join(stack), b[i:j].decode('utf-8', 'replace'))); i = j + 1
        elif c == 0xC3:
            n = mb(); res.append(('/'.join(stack), b[i:i + n].decode('utf-8', 'replace'))); i += n
        else:
            name = NAME.get((page, c & 0x3F), '%d:%02x' % (page, c & 0x3F))
            if c & 0x40:
                stack.append(name)
            else:
                res.append(('/'.join(stack + [name]), ''))
    return res


url, user, pw = sys.argv[1], sys.argv[2], sys.argv[3]
want = sys.argv[4:7] + [None] * (3 - len(sys.argv[4:7]))
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE  # the server's own certificate
auth = 'Basic ' + base64.b64encode(('%s:%s' % (user, pw)).encode()).decode()
policy = '0'
fails = []


def call(cmd, body):
    q = '%s?Cmd=%s&User=%s&DeviceId=SENTORATEST1&DeviceType=SmartPhone' % (url, cmd, urllib.request.quote(user))
    r = urllib.request.Request(q, data=enc(body), method='POST', headers={
        'Authorization': auth, 'MS-ASProtocolVersion': '14.0', 'Content-Type': 'application/vnd.ms-sync.wbxml',
        'X-MS-PolicyKey': policy, 'User-Agent': 'Sentora-EAS-Test/1.0'})
    try:
        with urllib.request.urlopen(r, context=ctx, timeout=60) as h:
            data = h.read()
            return h.status, (dec(data) if data else [])
    except urllib.error.HTTPError as e:
        return e.code, []


def val(res, path):
    return [t for p, t in res if p == path or p.endswith('/' + path)]


# 1. Provision (the phone agrees to the server's rules, gets a policy key)
pt = ('PolicyType', 'MS-EAS-Provisioning-WBXML')
st, res = call('Provision', ('Provision', [('Policies', [('Policy', [pt])])]))
k = val(res, 'Policy/PolicyKey')
if st == 200 and k:
    st, res = call('Provision', ('Provision', [('Policies', [('Policy', [pt, ('PolicyKey', k[0]), ('PStatus', '1')])])]))
    k = val(res, 'Policy/PolicyKey') or k
    policy = k[0]
    print('ok   Provision: policy key', policy)
else:
    print('FAIL Provision: HTTP', st); fails.append('provision')

# 2. FolderSync: the folders the phone sees
st, res = call('FolderSync', ('FolderSync', [('FSyncKey', '0')]))
folders, cur = [], {}
for p, t in res:
    if p.endswith('FAdd/FServerId'): cur = {'id': t}
    elif p.endswith('FAdd/DisplayName'): cur['name'] = t
    elif p.endswith('FAdd/Type'): cur['type'] = int(t); folders.append(cur)
if st == 200 and folders:
    print('ok   FolderSync: %d folders: %s' % (len(folders), ', '.join('%s (%d)' % (f['name'], f['type']) for f in folders)))
else:
    print('FAIL FolderSync: HTTP', st, res[:5]); fails.append('foldersync')


def sync(ftype, cls, field):
    f = next((f for f in folders if f.get('type') == ftype), None)
    if not f:
        print('FAIL no folder of type', ftype); fails.append('folder %d' % ftype); return []
    st, res = call('Sync', ('Sync', [('Collections', [('Collection', [('SyncKey', '0'), ('CollectionId', f['id'])])])]))
    key = val(res, 'Collection/SyncKey')
    if st != 200 or not key:
        print('FAIL Sync %s (first): HTTP %s %s' % (f['name'], st, res[:4])); fails.append('sync ' + f['name']); return []
    st, res = call('Sync', ('Sync', [('Collections', [('Collection', [('SyncKey', key[0]), ('CollectionId', f['id']),
                                                                       ('GetChanges', None), ('WindowSize', '50')])])]))
    items = val(res, 'ApplicationData/' + field)
    status = val(res, 'Collection/Status')
    print('%s Sync %s: status %s, %d item(s)%s' % ('ok  ' if st == 200 and status[:1] == ['1'] else 'FAIL', f['name'],
          status[:1], len(items), (': ' + '; '.join(items[:5])) if items else ''))
    if st != 200 or status[:1] != ['1']:
        fails.append('sync ' + f['name'])
    return items


mail = sync(2, 'Email', 'Subject')
cal = sync(8, 'Calendar', 'CalSubject')
con = sync(9, 'Contacts', 'FileAs')
for w, got, what in ((want[0], mail, 'mail'), (want[1], cal, 'event'), (want[2], con, 'contact')):
    if w:
        if any(w in g for g in got):
            print('ok   the %s "%s" came through' % (what, w))
        else:
            print('FAIL the %s "%s" is missing' % (what, w)); fails.append(what)
sys.exit(1 if fails else 0)
