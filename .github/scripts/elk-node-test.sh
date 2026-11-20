#!/bin/bash
# Elk node test, the whole way: a real Sentora panel (the Docker image, Debian 13) gets the Elk add-on
# (addons/elk); a fresh Ubuntu 26.04 in a container with systemd is made its Elk node with
# elk-node/sentora2027-elk-node as a person does (the panel's address: its certificate fetched from the panel,
# the panel's code checked; the node key and the Kibana login at the end); the panel pairs with that key, its
# own rsyslog then sends its real logs, and they are read back from Elasticsearch. Also: real log lines of
# every kind from the runner with the panel's certificate, and what the node must refuse.
#   .github/scripts/elk-node-test.sh <results folder>
set -uo pipefail
OUT=${1:?results folder}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
REPO=$(cd "$(dirname "$0")/../.." && pwd)
IMAGE=docker.io/library/ubuntu:26.04
NAME=sentora-elk-test
PNAME=sentora-elk-panel
PANEL_IMAGE=${PANEL_IMAGE:-ghcr.io/rustus/sentora2027:latest}
FAILS=0
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
ok() { echo "  ok   $1"; }
bad() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); echo "::error::$1"; }
in_box() { docker exec "$NAME" bash -c "$1"; }
in_panel() { docker exec "$PNAME" bash -c "$1"; }
pphp() { docker exec "$PNAME" php -r "chdir('/etc/sentora/panel'); require 'dryden/loader.inc.php'; require 'cnf/db.php'; require 'inc/dbc.inc.php'; require 'modules/elk/code/elk.php'; $1"; }
es() { in_box "curl -sS --cacert /etc/sentora-elk/http_ca.crt -u elastic:\$(cat /etc/sentora-elk/elastic.pw) -H 'Content-Type: application/json' $*"; }
W=$(mktemp -d)
cleanup() {
    docker exec "$NAME" cat /var/log/sentora2027-elk-node.log > "$OUT/install.log" 2>&1
    for s in elasticsearch logstash kibana; do docker exec "$NAME" journalctl -u $s --no-pager > "$OUT/$s.log" 2>&1; done
    docker exec "$NAME" sh -c 'cat /var/log/logstash/logstash-plain.log' > "$OUT/logstash-plain.log" 2>&1
    docker logs "$PNAME" > "$OUT/panel-first-start.log" 2>&1
    docker exec "$PNAME" sh -c 'cat /etc/rsyslog.d/61-sentora-elk.conf; journalctl -u rsyslog --no-pager | tail -40' > "$OUT/panel-rsyslog.txt" 2>&1
    [ -n "${TEST_KEEP:-}" ] || { docker rm -f "$NAME" "$PNAME" > /dev/null 2>&1; rm -rf "$W"; }
}
trap cleanup EXIT

say "A Sentora panel ($PANEL_IMAGE), installing itself on its first start"
docker run -d --name "$PNAME" --cgroupns=private --security-opt writable-cgroups=true --tmpfs /run --tmpfs /run/lock --cap-add NET_ADMIN \
    -h panel.elktest.example -e SENTORA_EMAIL=admin@elktest.example "$PANEL_IMAGE" > /dev/null || { echo "::error::the panel image did not start"; exit 1; }

say "A fresh $IMAGE with systemd"
# (Elasticsearch needs more memory maps than a default kernel allows; a container takes the host's)
sudo sysctl -w vm.max_map_count=262144 > /dev/null 2>&1 || sysctl -w vm.max_map_count=262144 > /dev/null 2>&1
RUNLINE='apt-get update && DEBIAN_FRONTEND=noninteractive apt-get -y install systemd systemd-sysv dbus procps hostname iproute2 tzdata curl ca-certificates openssh-server && rm -rf /var/lib/apt/lists/*'
printf 'FROM %s\nRUN %s\nSTOPSIGNAL SIGRTMIN+3\nCMD ["/sbin/init"]\n' "$IMAGE" "$RUNLINE" > "$W/Dockerfile"
docker build -q -t sentora-elk-test "$W" > "$OUT/image-build.log" 2>&1 || { tail -20 "$OUT/image-build.log"; echo "::error::image did not build"; exit 1; }
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock -h elk1.example.test sentora-elk-test > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$NAME")
GW=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')

say "The panel: installed, with the Elk add-on"
for _ in $(seq 1 120); do docker logs "$PNAME" 2>&1 | grep -q '^sentora: Sentora is installed\|did not finish' && break; sleep 10; done
docker logs "$PNAME" 2>&1 | grep -q '^sentora: Sentora is installed' && ok "the panel installed itself" || { bad "the panel did not install"; docker logs "$PNAME" 2>&1 | tail -20; exit 1; }
PIP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$PNAME")
docker cp "$REPO/sentora-repo/elk.zpp" "$PNAME:/root/elk.zpp"; docker cp "$REPO/tests/elk/localinstall.php" "$PNAME:/root/localinstall.php"
in_panel 'php /root/localinstall.php elk /root/elk.zpp' > "$OUT/addon-install.txt" 2>&1 && grep -q installed "$OUT/addon-install.txt" && ok "the Elk add-on installed (as zppy does)" || bad "add-on install: $(tail -3 "$OUT/addon-install.txt")"
in_panel 'test "$(stat -c %a /etc/sentora/elk)" = 750 && test "$(stat -c %a /etc/sentora/elk/panel.key)" = 640 && test "$(stat -c %U /etc/sentora/elk/panel.key)" = root' && ok "the panel's key is root's" || bad "the panel's key is open: $(in_panel 'ls -la /etc/sentora/elk')"
[ "$(pphp 'echo (int) $zdbh->query("SELECT COUNT(*) FROM x_permissions p JOIN x_modules m ON m.mo_id_pk = p.pe_module_fk WHERE m.mo_folder_vc = \"elk\" AND p.pe_group_fk <> 1")->fetchColumn();')" = 0 ] && ok "the module is for administrators only" || bad "other groups may open Elk"
curl -sk "https://$PIP/modules/elk/code/node.php?cert=1" -o "$W/panel-served.crt"
openssl x509 -in "$W/panel-served.crt" -noout 2> /dev/null && ok "the panel serves its certificate at modules/elk/code/node.php?cert=1" || bad "node.php?cert=1: $(head -c 200 "$W/panel-served.crt")"
[ "$(curl -sk -o /dev/null -w '%{http_code}' "https://$PIP/modules/elk/code/node.php")" = 400 ] && ok "node.php answers nothing else" || bad "node.php without ?cert answered"
PCODE=$(pphp 'echo elk::PanelCode();')
echo "  the panel's page shows the code $PCODE"
docker cp "$PNAME:/etc/sentora/elk/panel.crt" "$W/panel.crt"; docker cp "$PNAME:/etc/sentora/elk/panel.key" "$W/panel.key"
cmp -s "$W/panel.crt" "$W/panel-served.crt" && ok "the certificate served is the panel's own" || bad "node.php serves another certificate"

say "Another certificate (a stranger's) and the codes"
for c in other; do
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$W/$c.key" -out "$W/$c.crt" -days 3650 -subj "/CN=sentora-panel" \
        -addext "basicConstraints=critical,CA:FALSE" -addext "keyUsage=critical,digitalSignature" -addext "extendedKeyUsage=clientAuth" 2> /dev/null
done
# the codes as every node computes them (the block from NODES.md, the same in the installer)
eval "$(sed -n '/^# BEGIN node codes/,/^# END node codes/p' "$REPO/elk-node/sentora2027-elk-node")"
cmp -s <(sed -n '/^# BEGIN node codes/,/^# END node codes/p' "$REPO/NODES.md") <(sed -n '/^# BEGIN node codes/,/^# END node codes/p' "$REPO/elk-node/sentora2027-elk-node") \
    && ok "the installer's node codes are NODES.md's" || bad "the installer's node codes differ from NODES.md's"
spki() { openssl x509 -in "$1" -noout -pubkey | openssl pkey -pubin -outform DER | base64 -w0; }
CODE=$(panel_code sentora-elk "$(spki "$W/panel.crt")")
[ "$CODE" = "$PCODE" ] && ok "the installer's code for the panel ($CODE) is the code on the panel's page" || bad "the installer computes $CODE, the panel shows $PCODE"

say "Installing the Elk node"
docker cp "$REPO/elk-node" "$NAME:/root/elk-node"
START=$(date +%s)
WRONG=$(panel_code sentora-elk "$(spki "$W/other.crt")")
if in_box "bash /root/elk-node/sentora2027-elk-node --panel https://$PIP --panel-code $WRONG --files /root/elk-node" > "$OUT/wrong-code.txt" 2>&1; then
    bad "the installer went on with the wrong panel code"
else
    grep -q "not the panel you meant" "$OUT/wrong-code.txt" && ! in_box 'test -e /etc/sentora-elk/ca.crt' && ok "a wrong panel code stops it before anything is changed" || bad "wrong code: $(tail -2 "$OUT/wrong-code.txt")"
fi
in_box "bash /root/elk-node/sentora2027-elk-node --panel https://$PIP --panel-code $CODE --files /root/elk-node" > "$OUT/install-screen.txt" 2>&1
RC=$?
echo "  install: rc $RC in $(( $(date +%s) - START )) s"; tail -16 "$OUT/install-screen.txt" | sed 's/^/  | /'
[ $RC -eq 0 ] && ok "the installer finished" || { bad "the installer stopped (rc $RC)"; tail -40 "$OUT/install-screen.txt"; exit 1; }
grep -q "WARNING" "$OUT/install-screen.txt" && bad "the installer warned: $(grep WARNING "$OUT/install-screen.txt" | head -3 | tr '\n' ' ')" || ok "no warnings"
NODEKEY=$(grep -o 'SE1-[A-Z2-9]\{4\}-[A-Z2-9]\{4\}-[A-Z2-9]\{4\}-[A-Z2-9]\{4\}' "$OUT/install-screen.txt" | head -1)
ADMINPW=$(sed -n 's/.*password: *\([A-Za-z0-9]\{20,\}\).*/\1/p' "$OUT/install-screen.txt" | head -1)
docker cp "$NAME:/etc/sentora-elk/ca.crt" "$W/node-ca.crt"
c=$(b32hex "$(openssl x509 -in "$W/node-ca.crt" -noout -pubkey | openssl pkey -pubin -outform DER | sha256sum | cut -c1-20)" 16)
[ "$NODEKEY" = "SE1-${c:0:4}-${c:4:4}-${c:8:4}-${c:12:4}" ] && ok "the node key shown ($NODEKEY) is its certificate authority's" || bad "node key $NODEKEY is not the authority's (SE1-$c)"
[ "$(in_box 'sentora-elk-node key' | head -1)" = "$NODEKEY" ] && ok "sentora-elk-node key shows it again" || bad "sentora-elk-node key: $(in_box 'sentora-elk-node key')"
[ -n "$ADMINPW" ] && ok "Kibana's login was shown" || bad "no Kibana password on the screen"
for s in elasticsearch logstash kibana; do in_box "systemctl is-active --quiet $s" && ok "$s runs" || bad "$s does not run"; done
in_box 'ss -ltn' > "$OUT/listening.txt"
grep -qE '127\.0\.0\.1:9200|\[::ffff:127\.0\.0\.1\]:9200' "$OUT/listening.txt" && ! grep -qE '(0\.0\.0\.0|\*|\[::\]):9200' "$OUT/listening.txt" && ok "Elasticsearch listens on the machine itself only" || bad "Elasticsearch listens elsewhere: $(grep 9200 "$OUT/listening.txt")"
in_box 'test "$(stat -c %a /etc/sentora-elk)" = 700 && test "$(stat -c %a /etc/sentora-elk/elastic.pw)" = 600 && test "$(stat -c %a /etc/sentora-elk/ca.key)" = 600' && ok "the node's keys and passwords are root's alone" || bad "/etc/sentora-elk is open: $(in_box 'ls -la /etc/sentora-elk')"
in_box 'grep -q "^xpack.security.enabled: true" /etc/elasticsearch/elasticsearch.yml' && ok "Elasticsearch has its logins on" || bad "Elasticsearch's security is not on"

say "Kibana"
for _ in $(seq 1 40); do curl -sk -o /dev/null -w '%{http_code}' "https://$IP/login" | grep -q 200 && break; sleep 3; done
[ "$(curl -sk -o /dev/null -w '%{http_code}' "https://$IP/login")" = 200 ] && ok "Kibana's login page answers on https://$IP/" || bad "Kibana does not answer on 443"
echo | openssl s_client -connect "$IP:443" -CAfile "$W/node-ca.crt" -verify_return_error > /dev/null 2>&1 && ok "Kibana's certificate is signed by the node's own authority" || bad "Kibana's certificate is not the node's"
KB() { local p=$1; shift; curl -sk -u "sentora-admin:$ADMINPW" -H 'elastic-api-version: 2023-10-31' "$@" "https://$IP$p"; }
D=$(KB '/api/dashboards?per_page=50')
for d in sentora-overview sentora-websites sentora-mail sentora-security sentora-panel; do echo "$D" | grep -q "\"id\":\"$d\"" && ok "dashboard $d" || bad "dashboard $d missing"; done
KB /api/kibana/settings | grep -q 'sentora-overview' && ok "Kibana opens on the overview" || bad "Kibana's start page was not set"
[ "$(curl -sk -u "sentora-admin:wrong-password" -o /dev/null -w '%{http_code}' "https://$IP/api/security/me")" = 401 ] && ok "a wrong password is refused" || bad "a wrong Kibana password was not refused"

say "The panel pairs with the node, and its own logs arrive"
grep -q "6514 from $PIP" "$OUT/install-screen.txt" && ok "the firewall lets only the panel ($PIP) reach 6514" || bad "the firewall's panel address: $(grep 6514 "$OUT/install-screen.txt")"
pphp 'echo elk::AddNode("'"$IP"'", "SE1-AAAA-BBBB-CCCC-DDDD"), "\n"; foreach (elk::Apply() as $l) echo $l, "\n";' > "$OUT/pair-wrong.txt" 2>&1
grep -q "does not have that key" "$OUT/pair-wrong.txt" && ! in_panel 'test -s /etc/rsyslog.d/61-sentora-elk.conf' && ok "a wrong node key: not paired, nothing sent" || bad "wrong node key: $(cat "$OUT/pair-wrong.txt")"
pphp 'echo elk::AddNode("'"$IP"'", "'"$NODEKEY"'"), "\n"; foreach (elk::Apply() as $l) echo $l, "\n"; echo json_encode(elk::State()), "\n";' > "$OUT/pair.txt" 2>&1
grep -q "Paired with the Elk node" "$OUT/pair.txt" && ok "paired with the node's key" || bad "pairing: $(cat "$OUT/pair.txt")"
grep -q "rsyslog sends with the new settings" "$OUT/pair.txt" && ok "the panel's rsyslog took Sentora's settings (installed by the add-on)" || bad "rsyslog: $(cat "$OUT/pair.txt")"
grep -q '"reachable":true' "$OUT/pair.txt" && ok "the panel reaches the node (checked against the pinned authority)" || bad "the panel does not reach the node: $(tail -1 "$OUT/pair.txt")"
# real activity on the panel: its own pages, a mail refused, a failed SSH login, a ban
in_panel 'for i in 1 2 3 4 5; do curl -sk -o /dev/null --resolve panel.elktest.example:443:127.0.0.1 https://panel.elktest.example/; done
    logger -t postfix/smtpd -i "NOQUEUE: reject: RCPT from unknown[198.51.100.77]: 554 5.7.1 <x@y.test>: Relay access denied; from=<a@b.test> to=<x@y.test> proto=ESMTP helo=<x>"
    logger -t sshd -i "Failed password for invalid user elk-e2e from 203.0.113.77 port 4242 ssh2"
    echo "$(date "+%F %T"),123 fail2ban.actions        [99]: NOTICE  [sshd] Ban 203.0.113.77" >> /var/log/fail2ban.log'
for k in panel-access mail auth fail2ban; do
    n=0
    for _ in $(seq 1 30); do
        es -X POST https://127.0.0.1:9200/logs-sentora-*/_refresh > /dev/null 2>&1
        n=$(es "'https://127.0.0.1:9200/logs-sentora-*/_count' -d '{\"query\":{\"bool\":{\"filter\":[{\"term\":{\"sentora.panel\":\"panel.elktest.example\"}},{\"term\":{\"sentora.log\":\"$k\"}}]}}}'" | sed -n 's/.*"count":\([0-9]*\).*/\1/p')
        [ "${n:-0}" -gt 0 ] && break; sleep 3
    done
    [ "${n:-0}" -gt 0 ] && ok "the panel's own $k lines arrived ($n)" || bad "no $k lines from the panel's rsyslog"
done
in_box 'sentora-elk-node panel-from '"$PIP $GW" > /dev/null 2>&1 && ok "panel-from takes two addresses (the runner sends test lines too)" || bad "panel-from with two addresses"

say "The panel's lines (every kind, over TLS with the panel's certificate)"
SENT=$(python3 -I "$REPO/tests/elk/send.py" "$IP" "$W/node-ca.crt" "$W/panel.crt" "$W/panel.key" "$REPO/tests/elk/lines.tsv" panel1.example.test)
echo "  sent $SENT"
N=0
for _ in $(seq 1 40); do
    es -X POST https://127.0.0.1:9200/logs-sentora-*/_refresh > /dev/null 2>&1
    N=$(es "https://127.0.0.1:9200/logs-sentora-*/_count" 2> /dev/null | sed -n 's/.*"count":\([0-9]*\).*/\1/p')
    [ "${N:-0}" -ge "${SENT:-1}" ] && break; sleep 3
done
[ "${N:-0}" = "$SENT" ] && ok "all $SENT lines are in Elasticsearch" || bad "$N of $SENT lines arrived"
es "'https://127.0.0.1:9200/logs-sentora-*/_search?size=0' -d '{\"aggs\":{\"k\":{\"terms\":{\"field\":\"sentora.log\",\"size\":40}},\"u\":{\"filter\":{\"term\":{\"tags\":\"sentora_unparsed\"}},\"aggs\":{\"k\":{\"terms\":{\"field\":\"sentora.log\"}}}}}}'" > "$OUT/kinds.json"
python3 -I - "$OUT/kinds.json" "$REPO/tests/elk/lines.tsv" <<'PY_EOF' > "$OUT/kinds.txt"
import json, sys, collections
d = json.load(open(sys.argv[1]))
want = collections.Counter(l.split('\t', 1)[0] for l in open(sys.argv[2]) if l.strip() and not l.startswith('#'))
got = {b['key']: b['doc_count'] for b in d['aggregations']['k']['buckets']}
unp = {b['key']: b['doc_count'] for b in d['aggregations']['u']['k']['buckets']}
for k in sorted(want):
    print('%s %s sent %d, stored %d, not understood %d' % ('ok' if got.get(k) == want[k] and not unp.get(k) else 'FAIL', k, want[k], got.get(k, 0), unp.get(k, 0)))
PY_EOF
while read -r r k rest; do [ "$r" = ok ] && ok "$k: $rest" || bad "$k: $rest"; done < "$OUT/kinds.txt"
# a few fields the dashboards count on
chk() { # what query-json
    local n; n=$(es "'https://127.0.0.1:9200/logs-sentora-*/_count' -d '{\"query\":$2}'" | sed -n 's/.*"count":\([0-9]*\).*/\1/p')
    [ "${n:-0}" -gt 0 ] && ok "$1 ($n)" || bad "$1: none"
}
chk "failed SSH logins with their address" '{"bool":{"filter":[{"term":{"event.action":"ssh-login"}},{"term":{"event.outcome":"failure"}},{"exists":{"field":"source.ip"}}]}}'
chk "fail2ban bans with jail and address" '{"bool":{"filter":[{"term":{"event.action":"ban"}},{"exists":{"field":"rule.name"}},{"exists":{"field":"source.ip"}}]}}'
chk "mail sent, with the recipient" '{"bool":{"filter":[{"term":{"sentora.mail.status":"sent"}},{"exists":{"field":"email.to.address"}}]}}'
chk "website requests with status, page and website" '{"bool":{"filter":[{"term":{"sentora.log":"web-access"}},{"exists":{"field":"http.response.status_code"}},{"exists":{"field":"url.path"}},{"term":{"sentora.domain":"vmx6a3b07.test"}}]}}'
chk "panel login failures with name and address" '{"bool":{"filter":[{"term":{"event.action":"panel-login"}},{"exists":{"field":"user.name"}},{"exists":{"field":"source.ip"}}]}}'
chk "visitors' countries" '{"exists":{"field":"source.geo.country_iso_code"}}'

say "What it must refuse"
printf '# marker\nother\t\t\tline from a stranger\n' > "$W/stranger.tsv"
python3 -I "$REPO/tests/elk/send.py" "$IP" "$W/node-ca.crt" "$W/other.crt" "$W/other.key" "$W/stranger.tsv" > "$OUT/stranger.txt" 2>&1
sleep 8; es -X POST https://127.0.0.1:9200/logs-sentora-*/_refresh > /dev/null 2>&1
N2=$(es "https://127.0.0.1:9200/logs-sentora-*/_count" | sed -n 's/.*"count":\([0-9]*\).*/\1/p')
[ "$N2" = "$N" ] && ok "another certificate's lines were turned away" || bad "a stranger's line got in ($N -> $N2)"
# (a line sent, and a moment for the node's answer: it turns the connection away once it sees there is no certificate)
( printf '{"message":"no certificate"}\n'; sleep 4 ) | timeout 15 openssl s_client -connect "$IP:6514" -CAfile "$W/node-ca.crt" -quiet > "$OUT/nocert.txt" 2>&1
grep -qiE 'alert|certificate required' "$OUT/nocert.txt" && ok "no certificate: turned away" || bad "a connection without a certificate was not turned away"
[ "$(KB '/api/security/me' -o /dev/null -w '%{http_code}')" = 200 ] && ok "the Kibana login works" || bad "the Kibana login does not work"
[ "$(KB '/api/console/proxy?path=/_data_stream/logs-sentora-default&method=DELETE' -X POST -H 'kbn-xsrf: x' -o /dev/null -w '%{http_code}')" != 200 ] && \
    [ "$(es '-o /dev/null -w %{http_code} https://127.0.0.1:9200/_data_stream/logs-sentora-default')" = 200 ] && ok "the Kibana login can not delete the logs" || bad "the Kibana login could delete the logs"
# the firewall: who may reach Kibana
in_box 'sentora-elk-node kibana-from 192.0.2.1' > "$OUT/kibana-from.txt" 2>&1
[ "$(curl -sk -m 6 -o /dev/null -w '%{http_code}' "https://$IP/login")" = 000 ] && ok "kibana-from: others can not reach Kibana" || bad "kibana-from did not close Kibana: $(cat "$OUT/kibana-from.txt")"
in_box 'sentora-elk-node kibana-from any' > /dev/null 2>&1
[ "$(curl -sk -m 10 -o /dev/null -w '%{http_code}' "https://$IP/login")" = 200 ] && ok "kibana-from any: open again" || bad "kibana-from any did not open Kibana again"
in_box 'sentora-elk-node kibana-from "1.2.3.4; rm -rf /"' > /dev/null 2>&1 && bad "kibana-from took a command" || ok "kibana-from takes addresses only"

say "Settings and update"
in_box 'sentora-elk-node retention 7' > /dev/null && es https://127.0.0.1:9200/_ilm/policy/sentora-logs | grep -q '"min_age":"7d"' && ok "retention 7: logs kept 7 days" || bad "retention did not change"
in_box 'sentora-elk-node status' > "$OUT/status.txt" 2>&1; grep -q "Logs kept: *7 days" "$OUT/status.txt" && ok "status shows it" || bad "status: $(cat "$OUT/status.txt")"
in_box 'sentora-elk-node password' > "$OUT/password.txt" 2>&1
NEWPW=$(sed -n 's/.*password: \([A-Za-z0-9]*\).*/\1/p' "$OUT/password.txt")
[ -n "$NEWPW" ] && [ "$(curl -sk -u "sentora-admin:$NEWPW" -o /dev/null -w '%{http_code}' "https://$IP/api/security/me")" = 200 ] && ok "a new Kibana password works" || bad "sentora-elk-node password: $(cat "$OUT/password.txt")"
in_box "bash /root/elk-node/sentora2027-elk-node --panel https://$PIP --panel-code $CODE --files /root/elk-node" > "$OUT/again.txt" 2>&1 && bad "a second install went on" || { grep -q "already an Elk node" "$OUT/again.txt" && ok "a second install is refused (use --update)" || bad "second install: $(tail -2 "$OUT/again.txt")"; }
in_box "bash /root/elk-node/sentora2027-elk-node --update --files /root/elk-node" > "$OUT/update.txt" 2>&1 && ok "--update finished" || bad "--update stopped: $(tail -5 "$OUT/update.txt")"
[ "$(in_box 'sentora-elk-node key' | head -1)" = "$NODEKEY" ] && ok "the node key is the same after the update" || bad "the update changed the node key"
es https://127.0.0.1:9200/_ilm/policy/sentora-logs | grep -q '"min_age":"7d"' && ok "the update kept the retention" || bad "the update reset the retention"
[ "$(curl -sk -u "sentora-admin:$NEWPW" -o /dev/null -w '%{http_code}' "https://$IP/api/security/me")" = 200 ] && ok "the update kept the Kibana login" || bad "the update broke the Kibana login"
N3=$(es "https://127.0.0.1:9200/logs-sentora-*/_count" | sed -n 's/.*"count":\([0-9]*\).*/\1/p'); [ "$N3" = "$N" ] && ok "the update kept the logs" || bad "logs after the update: $N3 (were $N)"
SENT2=$(python3 -I "$REPO/tests/elk/send.py" "$IP" "$W/node-ca.crt" "$W/panel.crt" "$W/panel.key" "$REPO/tests/elk/lines.tsv" panel1.example.test)
for _ in $(seq 1 30); do es -X POST https://127.0.0.1:9200/logs-sentora-*/_refresh > /dev/null 2>&1; N4=$(es "https://127.0.0.1:9200/logs-sentora-*/_count" | sed -n 's/.*"count":\([0-9]*\).*/\1/p'); [ "${N4:-0}" -ge $((N + SENT2)) ] && break; sleep 3; done
[ "${N4:-0}" = $((N + SENT2)) ] && ok "lines arrive after the update" || bad "after the update $N4, expected $((N + SENT2))"

echo; echo "ELK NODE TEST: $FAILS FAIL"
[ "$FAILS" -eq 0 ]
