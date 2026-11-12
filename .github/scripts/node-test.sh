#!/bin/bash
# Docker Hosting node test: a real AlmaLinux 10 (or Rocky Linux 10) in a container with systemd is made
# a node with node/sentora2027-node, linked to a stand-in panel (tests/docker_hosting) running
# the add-on, and must then really run an app: rootless Podman as the customer's own user, its limits
# held by the kernel, Caddy in front (HTTPS, setup password first, then public), restarted with the
# "server", deleted with its data.
#   .github/scripts/node-test.sh <module folder> <results folder> [alma10|rocky10|fedora45|debian13|ubuntu26.04]
# Needs docker that can run privileged containers with cgroup v2, php (pdo_sqlite, curl, sodium).
# The container has no SELinux (the runner's kernel has none): the installer's SELinux step is not tested here.
set -uo pipefail
MOD=$(cd "${1:?module folder}" && pwd); OUT=${2:?results folder}; SYS=${3:-alma10}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
REPO=$(cd "$(dirname "$0")/../.." && pwd)
T=$REPO/tests/docker_hosting
case "$SYS" in
    alma10) IMAGE=quay.io/almalinuxorg/almalinux:10; PKGS=dnf ;;
    rocky10) IMAGE=quay.io/rockylinux/rockylinux:10; PKGS=dnf ;;
    fedora45) IMAGE=quay.io/fedora/fedora:45; PKGS=dnf ;;
    debian13) IMAGE=docker.io/library/debian:13; PKGS=apt ;;
    ubuntu26.04) IMAGE=docker.io/library/ubuntu:26.04; PKGS=apt ;;
    *) echo "unknown system $SYS"; exit 2 ;;
esac
NAME=sentora-node-test
FAILS=0
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
ok() { echo "  ok   $1"; }
bad() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); echo "::error::$1"; }
in_box() { docker exec "$NAME" bash -c "$1"; }
panel() { php "$T/node_real.php" "$@"; }
wait_for() { # wait_for <seconds> <command...>: until the command prints something other than ""/0
    local end=$(( $(date +%s) + $1 )); shift
    while [ "$(date +%s)" -lt "$end" ]; do out=$("$@" 2>/dev/null); case "$out" in ''|0) sleep 3 ;; *) echo "$out"; return 0 ;; esac; done
    return 1
}
diag() { panel log | tail -8; in_box 'journalctl -u sentora-node --no-pager | tail -15'; }
state_is() { local s; s=$(panel state "$1"); case "$s" in "$2"*) echo "$s" ;; esac; }

say "Stand-in panel with the add-on"
W=$(mktemp -d)
rm -rf "$T/root/modules"; mkdir -p "$T/root/modules"; cp -r "$MOD" "$T/root/modules/docker_hosting"
export DH_DB="$W/panel.sqlite"
GW=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')
php -d display_errors=stderr -d error_reporting=-1 -S "$GW:18092" -t "$T/root" > "$OUT/panel-web.log" 2>&1 &
WEB=$!
cleanup() {
    kill $WEB 2> /dev/null
    panel log > "$OUT/panel-log.txt" 2>&1
    docker exec "$NAME" journalctl -u sentora-node --no-pager > "$OUT/agent.log" 2>&1
    docker exec "$NAME" journalctl -u caddy --no-pager > "$OUT/caddy.log" 2>&1
    docker exec "$NAME" cat /var/log/sentora2027-node.log > "$OUT/node-install.log" 2>&1
    docker exec "$NAME" cat /etc/caddy/sentora/sites.caddy > "$OUT/sites.caddy" 2>&1
    [ -n "${TEST_KEEP:-}" ] || { docker exec "$NAME" sh -c 'rm -rf /home/*' > /dev/null 2>&1; docker rm -f "$NAME" > /dev/null 2>&1; rm -rf "$W" "$T/root/modules"; }
}
trap cleanup EXIT
sleep 1
FP=$(panel init)
[[ "$FP" =~ ^[A-Z2-9]{4}-[A-Z2-9]{4}$ ]] || { echo "::error::the stand-in panel did not start ($FP)"; exit 1; }
ok "panel ready (its code: $FP)"

say "A fresh $IMAGE with systemd"
# (container images may mask systemd-logind; a server never does)
if [ "$PKGS" = apt ]; then
    RUNLINE='apt-get update && DEBIAN_FRONTEND=noninteractive apt-get -y install systemd systemd-sysv dbus procps hostname iproute2 tzdata curl ca-certificates && rm -rf /var/lib/apt/lists/*'
else
    RUNLINE='dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all'
fi
printf 'FROM %s\nRUN %s && (systemctl unmask systemd-logind.service || true)\nSTOPSIGNAL SIGRTMIN+3\nCMD ["/sbin/init"]\n' "$IMAGE" "$RUNLINE" > "$W/Dockerfile"
docker build -q -t sentora-node-test "$W" > "$OUT/image-build.log" 2>&1 || { tail -20 "$OUT/image-build.log"; echo "::error::image did not build"; exit 1; }
mkdir -p "$W/home"
# (/home on the runner's disk: rootless Podman's storage can not be overlay on the container's overlay)
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw -v "$W/home":/home \
    --tmpfs /run --tmpfs /run/lock -h node1.example.test sentora-node-test > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done

say "Installing the node"
docker cp "$REPO/node/sentora2027-node" "$NAME:/root/sentora2027-node"
docker cp "$REPO/node/sentora-node-agent.php" "$NAME:/root/sentora-node-agent.php"
START=$(date +%s)
# (the node makes its own key; the panel's code is given as the person installing would confirm it)
in_box "cd /root && SNODE_TEST_LOCAL_CERTS=1 bash sentora2027-node --panel http://$GW:18092 --panel-code $FP --agent /root/sentora-node-agent.php" 2>&1 | tee "$OUT/node-install-screen.txt"
echo "installed in $(( $(date +%s) - START )) seconds"
grep -q "^Done: this server is ready to be a node" "$OUT/node-install-screen.txt" && ok "node installer finished" || { bad "node installer did not finish"; in_box 'tail -40 /var/log/sentora2027-node.log'; exit 1; }
NODEKEY=$(grep -oE 'SN1(-[A-Z2-9]{4}){6}' "$OUT/node-install-screen.txt" | head -1)
[ -n "$NODEKEY" ] && ok "the installer showed the node's key" || bad "no node key shown"
in_box 'stat -c %a /root/sentora-node-key.txt' | grep -qx 600 && ok "the key is also kept for root only" || bad "no key file (0600)"
sleep 12
panel online | grep -qx 0 && ok "not linked before it is added in the panel" || bad "linked before it was added"
panel addkey node1 "$NODEKEY" | grep -qx ok && ok "added in the panel with its key" || bad "the panel refused the key"
wait_for 60 panel online > /dev/null && ok "node online in the panel" || bad "node not online"
in_box '[ ! -e /root/sentora-node-key.txt ]' && ok "the key file is gone once linked" || bad "the key file is still there"
in_box 'stat -c %a /etc/sentora-node/node.json' | grep -qx 600 && ok "node keys readable by root only" || bad "node keys not 0600"
in_box 'systemctl is-active caddy' | grep -qx active && ok "Caddy runs" || bad "Caddy does not run"
PF=$(in_box 'cat /etc/sentora-node/platform.conf' 2>&1)
echo "$PF" | grep -qE '^FAMILY=(el|fedora|deb)$' && echo "$PF" | grep -q '^PHP_BIN=/' && echo "$PF" | grep -q '^CADDY_SERVICE=caddy$' \
    && ok "this system's names kept for the agent: $(echo "$PF" | grep -E '^(FAMILY|PKG|MAC)=' | tr '\n' ' ')" || bad "platform.conf: $PF"
in_box 'cat /etc/systemd/system/user@.service.d/sentora-delegate.conf' | grep -q 'Delegate=cpu cpuset io memory pids' && ok "cgroup controllers handed to the customers' users" || bad "no Delegate drop-in"

say "Starting Uptime Kuma for client1"
CID=$(panel deploy uptime-kuma kuma)
[[ "$CID" =~ ^[0-9]+$ ]] || { bad "deploy refused: $CID"; exit 1; }
ok "panel queued container $CID"
running_or_error() { local s; s=$(panel state "$1"); case "$s" in running*|error*) echo "$s" ;; esac; }
S=$(wait_for 900 running_or_error "$CID")
if [[ "$S" == running* ]]; then ok "running (panel): $S"; else
    bad "not running: $(panel state "$CID")"
    panel log | tail -5
    in_box 'getcap /usr/bin/newuidmap /usr/bin/newgidmap; grep sdh /etc/subuid /etc/subgid; loginctl list-users; ls -la /run/user; journalctl -u sentora-node --no-pager | tail -20'
    exit 1
fi
in_box 'id sdh3' && ok "customer user sdh3" || bad "no user sdh3"
in_box 'getent passwd sdh3 | cut -d: -f7' | grep -qxE '(/usr)?/sbin/nologin' && ok "sdh3 can not log in" || bad "sdh3 has a login shell"
in_box 'grep -q "^sdh3:" /etc/subuid && grep -q "^sdh3:" /etc/subgid' && ok "sdh3 has its own subordinate IDs" || bad "no subuid/subgid for sdh3"
in_box 'ls /var/lib/systemd/linger/sdh3' > /dev/null && ok "sdh3 lingers" || bad "sdh3 does not linger"
PS=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps --format '{{.Names}} {{.Ports}}'")
echo "$PS"
echo "$PS" | grep -q "sdh-$CID 127.0.0.1:20000->3001/tcp" && ok "runs rootless as sdh3, on 127.0.0.1:20000 only" || bad "not running as sdh3 on 127.0.0.1:20000"
in_box 'podman ps -q 2> /dev/null | wc -l' | grep -qx 0 && ok "root runs no containers" || bad "root has containers"
MEMMAX=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman exec sdh-$CID cat /sys/fs/cgroup/memory.max" | tr -d '\r')
[ "$MEMMAX" = 268435456 ] && ok "memory limit held by the kernel (256 MB)" || bad "memory.max in the container: '$MEMMAX' (want 268435456)"
CPUMAX=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman exec sdh-$CID cat /sys/fs/cgroup/cpu.max" | tr -d '\r')
[ "$CPUMAX" = "50000 100000" ] && ok "CPU limit held by the kernel (0.5)" || bad "cpu.max in the container: '$CPUMAX' (want 50000 100000)"
PIDS=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman exec sdh-$CID cat /sys/fs/cgroup/pids.max" | tr -d '\r')
[ "$PIDS" = 1024 ] && ok "process limit held (1024)" || bad "pids.max: '$PIDS'"

say "Caddy in front"
code() { in_box "curl -sk -o /dev/null -w '%{http_code}' --resolve kuma.example.test:443:127.0.0.1 $1 https://kuma.example.test/"; }
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "HTTPS asks for the setup password (401)" || bad "without the setup password: HTTP $C (want 401)"
PW=$(panel setup "$CID")
for _ in $(seq 1 40); do C=$(code "-u setup:$PW"); case "$C" in 200|302) break ;; esac; sleep 3; done   # (502 while the app still starts)
case "$C" in 200|302) ok "with the setup password: the app answers ($C)" ;; *) bad "with the setup password: HTTP $C" ;; esac
C=$(code "-u setup:wrong-password")
[ "$C" = 401 ] && ok "a wrong password is refused" || bad "wrong password: HTTP $C"
C=$(in_box "curl -s -o /dev/null -w '%{http_code}' --connect-timeout 5 http://\$(hostname -I | awk '{print \$1}'):20000/" 2> /dev/null)
[ "$C" = 000 ] && ok "the container's port is not reachable from outside 127.0.0.1" || bad "port 20000 answers on the public address ($C)"
# another container (any customer's) must not reach the node's 127.0.0.1: the app behind the setup
# password, or Caddy's admin API
PROBE=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman run --rm docker.io/library/busybox:1 sh -c '
    gw=\$(ip route | awk \"/default/ {print \\\$3}\"); for h in host.containers.internal 169.254.1.2 \$gw 10.0.2.2; do for p in 20000 2019; do
    wget -q -T 3 -O /dev/null http://\$h:\$p/ 2> /dev/null && echo \"reached \$h:\$p\"; done; done; echo probed'" 2>&1)
echo "$PROBE" | tail -5
if echo "$PROBE" | grep -q '^probed'; then
    echo "$PROBE" | grep -q '^reached' && bad "a container reached the node's 127.0.0.1 ($(echo "$PROBE" | grep '^reached' | tr '\n' ' '))" || ok "a container can not reach the node's 127.0.0.1 (apps, Caddy's admin)"
else bad "the probe container did not run"; fi
# the cloud's metadata address (it can hand out the server's own keys) is refused to the apps
UIDS=$(in_box "nft list set inet sentora_node apps" 2>&1); SDH3=$(in_box "id -u sdh3")
echo "$UIDS" | grep -qw "$SDH3" && ok "the customer's user is in the node's fence (nftables set)" || { bad "sdh3 ($SDH3) is not in the fence"; echo "$UIDS"; }
META=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman run --rm docker.io/library/busybox:1 sh -c 'wget -q -T 5 -O /dev/null http://169.254.169.254/ 2>&1; echo exit=\$?'" 2>&1)
echo "$META" | tail -2
echo "$META" | grep -q '^exit=0' && bad "an app reached 169.254.169.254 (the cloud's metadata)" || ok "an app can not reach 169.254.169.254 (the cloud's metadata)"
ROOTMETA=$(in_box "curl -s -o /dev/null -w '%{http_code}' --connect-timeout 3 -H Metadata:true 'http://169.254.169.254/metadata/instance?api-version=2021-02-01'" 2>&1)
echo "  (for comparison, root on the node gets: HTTP $ROOTMETA)"
# the local network: closed to the apps by default (here: the stand-in panel on the runner's private
# address $GW), open only for the customers an administrator lets through
lanprobe() { in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman run --rm docker.io/library/busybox:1 sh -c 'wget -q -T 5 -O - http://$GW:18092/modules/docker_hosting/code/node.php?key=1 2>&1 | head -c 120; echo; nslookup -timeout=5 example.com > /dev/null 2>&1 && echo dns-ok'" 2>&1; }
L1=$(lanprobe); echo "$L1" | tail -3
echo "$L1" | grep -q '^v1\.' && bad "an app reached the local network ($GW) while it is closed" || ok "the local network is closed to the apps by default ($GW refused)"
echo "$L1" | grep -q dns-ok && ok "names still resolve for the apps (the node's DNS servers stay open)" || bad "DNS does not work for the apps with the local network closed"
panel lan 3 1 | grep -qx ok || bad "lan: $(panel lan 3 1)"
L2=''; for _ in $(seq 1 20); do L2=$(lanprobe); echo "$L2" | grep -q '^v1\.' && break; sleep 3; done
echo "$L2" | grep -q '^v1\.' && ok "opened for client1 (sdh3): its app reaches the local network" || { bad "opened for client1, but its app still can not reach $GW"; echo "$L2" | tail -3; }
panel lan 3 0 > /dev/null
L3=''; for _ in $(seq 1 20); do L3=$(lanprobe); echo "$L3" | grep -q '^v1\.' || break; sleep 3; done
echo "$L3" | grep -q '^v1\.' && bad "closed again, but the app still reaches $GW" || ok "closed again: refused"
panel act "$CID" public > /dev/null
for _ in $(seq 1 20); do C=$(code ''); case "$C" in 200|302) break ;; esac; sleep 3; done
case "$C" in 200|302) ok "open to the public: the app answers without a password ($C)" ;; *) bad "public: HTTP $C" ;; esac
panel act "$CID" private > /dev/null
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "closed again (401)" || bad "closed again: HTTP $C"

say "Logs, limits, stop and start"
panel act "$CID" logs > /dev/null
L=$(wait_for 60 panel logs "$CID")
echo "$L" | tail -3
[ -n "$L" ] && ok "logs reach the panel" || { bad "no logs"; diag; }
panel limits "$CID" 75 384 2 | grep -qx ok || bad "limits refused"
for _ in $(seq 1 20); do MEMMAX=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman exec sdh-$CID cat /sys/fs/cgroup/memory.max" | tr -d '\r'); [ "$MEMMAX" = 402653184 ] && break; sleep 3; done
[ "$MEMMAX" = 402653184 ] && ok "new limits applied to the running container (384 MB)" || { bad "memory.max after the change: '$MEMMAX'"; diag; }
say "The app's own settings (Advanced)"
panel env "$CID" "SENTORA_TEST=hello there" | grep -qx ok || bad "settings refused: $(panel env "$CID" "SENTORA_TEST=hello there")"
wait_for 180 state_is "$CID" running > /dev/null && ok "running again with its new settings" || { bad "not running after the settings: $(panel state "$CID")"; diag; }
ENVS=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman inspect --format '{{range .Config.Env}}{{println .}}{{end}}' sdh-$CID")
echo "$ENVS" | grep -qx 'SENTORA_TEST=hello there' && ok "the container has the new setting" || { bad "setting not in the container"; echo "$ENVS"; }
in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps -a --format '{{.Names}}'" | grep -qx "sdh-$CID-old" && bad "the old container was left behind" || ok "no old container left"
for _ in $(seq 1 30); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it after the settings change" || bad "after the settings change: HTTP $C"
panel act "$CID" stop > /dev/null
wait_for 120 state_is "$CID" stopped > /dev/null && ok "stopped" || bad "not stopped: $(panel state "$CID")"
panel act "$CID" start > /dev/null
wait_for 120 state_is "$CID" running > /dev/null && ok "started again" || bad "not started: $(panel state "$CID")"
U=$(wait_for 400 bash -c "panel() { php '$T/node_real.php' \"\$@\"; }; s=\$(panel state $CID); u=\${s#* }; u=\${u%%MB*}; [ \"\${u:-0}\" -gt 0 ] && echo \$u")
[ -n "$U" ] && ok "disk use measured: $U MB" || bad "no disk use measured"

say "An app the panel lost: found again, given back, moved to another customer and back (with its data)"
podu() { in_box "runuser -u $1 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u $1) podman $2"; }
podu sdh3 "exec sdh-$CID sh -c 'echo kept-$CID > /app/data/sentora-test.txt'"
# the node's own list loses it too (state.json): it must find it by its label
in_box "php -r '\$f = \"/var/lib/sentora-node/state.json\"; \$s = json_decode(file_get_contents(\$f), true); unset(\$s[\"containers\"][\"$CID\"], \$s[\"scanned_at\"]); file_put_contents(\$f, json_encode(\$s));'"
panel forget "$CID" > /dev/null
F=$(wait_for 120 bash -c "v=\$(php '$T/node_real.php' found $CID fd_domain_vc); [ \"\$v\" != none ] && echo \$v")
[ "$F" = kuma.example.test ] && ok "the node found it again by its label, with its address: $F" || { bad "not found: '$F'"; in_box 'journalctl -u sentora-node --no-pager | tail -20'; }
[ "$(panel found "$CID" fd_hostport_in)" = 20000 ] && [ "$(panel found "$CID" fd_volumes_in)" = 1 ] && ok "and its port and data folder" || bad "found: port $(panel found "$CID" fd_hostport_in), folders $(panel found "$CID" fd_volumes_in)"
podu sdh3 "ps --format '{{.Names}}'" | grep -qx "sdh-$CID" && ok "it kept running meanwhile (nothing deleted)" || bad "it was stopped or deleted"
panel adopt "$CID" 3 | grep -qx ok || bad "adopt: $(panel adopt "$CID" 3)"
wait_for 180 state_is "$CID" running > /dev/null && [ "$(panel found "$CID" fd_cid_in)" = none ] && ok "given back to client1 as it was" || { bad "not given back: $(panel state "$CID"), $(panel lastjob inspect)"; diag; }
[ "$(panel lastjob move)" = "" ] && ok "(nothing moved on the node)" || bad "a move ran: $(panel lastjob move)"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "behind a (new) setup password" || bad "given back: HTTP $C"
# to another customer: the node moves it under that customer's user, the same version and data
panel forget "$CID" > /dev/null
panel domainto 4 > /dev/null
wait_for 120 bash -c "[ \"\$(php '$T/node_real.php' found $CID fd_user_vc)\" = sdh3 ] && echo y" > /dev/null || bad "not found again"
panel adopt "$CID" 4 | grep -qx ok || bad "adopt to client2: $(panel adopt "$CID" 4)"
M=$(wait_for 900 bash -c "php '$T/node_real.php' lastjob move | grep -E '^(done moved to sdh4|failed)'"); echo "  $M"
sleep 2; S=$(panel state "$CID")
[[ "$S" == running* ]] && [[ "$M" == "done moved to sdh4"* ]] && ok "moved to client2 (sdh4)" || { bad "move: $S / $M / $(panel found "$CID" fd_note_vc)"; diag; }
podu sdh4 "ps --format '{{.Names}}'" | grep -qx "sdh-$CID" && ! podu sdh3 "ps -a --format '{{.Names}}'" | grep -qx "sdh-$CID" && ok "runs as sdh4; gone from sdh3" || bad "containers: sdh4 [$(podu sdh4 "ps -a --format '{{.Names}}'" | tr '\n' ' ')] sdh3 [$(podu sdh3 "ps -a --format '{{.Names}}'" | tr '\n' ' ')]"
[ "$(podu sdh4 "exec sdh-$CID cat /app/data/sentora-test.txt" | tr -d '\r')" = "kept-$CID" ] && ok "with its data" || bad "its data was not moved"
podu sdh4 "inspect --format '{{range .Config.Env}}{{println .}}{{end}}' sdh-$CID" | grep -qx 'SENTORA_TEST=hello there' && ok "and its own settings" || bad "its settings were lost"
podu sdh3 "volume ls -q" | grep -q "^sdh-$CID-" && bad "sdh3 still has its data folder" || ok "sdh3's copy of the data is gone"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it (setup password)" || bad "after the move: HTTP $C"
# and back to client1 (the steps below use sdh3)
panel forget "$CID" > /dev/null
panel domainto 3 > /dev/null
wait_for 120 bash -c "[ \"\$(php '$T/node_real.php' found $CID fd_user_vc)\" = sdh4 ] && echo y" > /dev/null || bad "not found again (sdh4)"
panel adopt "$CID" 3 | grep -qx ok || bad "adopt back: $(panel adopt "$CID" 3)"
M=$(wait_for 900 bash -c "php '$T/node_real.php' lastjob move | grep -E '^(done moved to sdh3|failed)'"); echo "  $M"
sleep 2; S=$(panel state "$CID")
[[ "$S" == running* ]] && [ "$(podu sdh3 "exec sdh-$CID cat /app/data/sentora-test.txt" | tr -d '\r')" = "kept-$CID" ] && ok "moved back to client1, data kept" || { bad "back: $S / $(panel lastjob move)"; diag; }
in_box 'ls /home/sdh3/.sentora-move /home/sdh4/.sentora-move 2>/dev/null | grep -c tar || true' | grep -qx 0 && ok "no copies left in the work folders" || bad "copies left: $(in_box 'ls -la /home/sdh3/.sentora-move /home/sdh4/.sentora-move 2>&1')"

say "Restarting the node"
docker restart "$NAME" > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
sleep 10
wait_for 120 panel online > /dev/null && ok "node online again after the restart" || bad "node not online after the restart"
wait_for 180 bash -c "docker exec $NAME runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(docker exec $NAME id -u sdh3) podman ps --format '{{.Names}}' | grep -x sdh-$CID" > /dev/null \
    && ok "the container started again by itself" || bad "the container did not start after the restart"
for _ in $(seq 1 30); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it again" || bad "after the restart: HTTP $C"

say "Updating the node (sentora-node-update)"
in_box 'rm -rf /root/src && mkdir /root/src'
docker cp "$REPO/node/sentora2027-node" "$NAME:/root/src/sentora2027-node"
docker cp "$REPO/node/sentora2027-node-alma10" "$NAME:/root/src/sentora2027-node-alma10"
docker cp "$REPO/node/sentora-node-agent.php" "$NAME:/root/src/sentora-node-agent.php"
# (a "newer" agent: the copy says version 999)
in_box "sed -i \"s/^    const VERSION = '[^']*';/    const VERSION = '999';/\" /root/src/sentora-node-agent.php"
C1=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-node-update --check' 2>&1); echo "$C1"
echo "$C1" | grep -q -- '-> 999 (new)' && ok "--check shows the new agent" || bad "--check: $C1"
U1=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-node-update --yes' 2>&1); echo "$U1" | tail -4
echo "$U1" | grep -q "^Done: this node is up to date (agent 999)" && ok "updated without asking" || bad "the update did not finish"
in_box 'sentora-node-agent status' 2>&1 | grep -c "agent 999" > /dev/null && ok "the new agent runs" || bad "the new agent does not run"
wait_for 60 panel online > /dev/null && ok "node online after the update" || bad "node not online after the update"
grep -q '"agent":"999"' <(php -r 'require "'"$T"'/root/dryden/loader.inc.php"; echo $zdbh->query("SELECT nd_info_tx FROM x_dh_nodes WHERE nd_id_pk = 1")->fetchColumn();' 2>/dev/null) \
    && ok "the panel sees the new agent's version" || bad "the panel does not show agent 999"
in_box "grep -q local_certs /etc/caddy/Caddyfile && grep -q skip_install_trust /etc/caddy/Caddyfile" && ok "the node's settings were kept" || bad "Caddyfile lost its settings"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && [[ "$(panel state "$CID")" == running* ]] && ok "the customer's app kept running behind its setup password" || bad "after the update: HTTP $C, $(panel state "$CID")"
# (older nodes' sentora-node-update still fetch the old installer name: it must hand over to the new one)
U3=$(in_box 'SNODE_RAW_BASE=file:///root/src bash /root/src/sentora2027-node-alma10 --update --agent /root/src/sentora-node-agent.php' 2>&1)
echo "$U3" | grep -q "^Done: this node is up to date (agent 999)" && ok "the old installer name hands over to the new one" || bad "old installer name: $(echo "$U3" | tail -5)"
C2=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-node-update --check' 2>&1)
echo "$C2" | grep -q "Agent: up to date (version 999)" && echo "$C2" | grep -q "Node setup: up to date" && ok "--check: up to date" || bad "--check after: $C2"
in_box 'echo "<?php syntax error" > /root/src/sentora-node-agent.php'
U2=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-node-update --yes' 2>&1)
echo "$U2" | grep -q "damaged: nothing changed" && in_box 'sentora-node-agent status' 2>&1 | grep -c "agent 999" > /dev/null && ok "a damaged download changes nothing" || bad "damaged download: $U2"

say "Updates and settings from the panel"
panel nodeact check-updates | grep -qx ok || bad "check-updates refused"
wait_for 300 bash -c "v=\$(php '$T/node_real.php' info sys_checked_at); [ \"\${v:-0}\" -gt 0 ] && echo \$v" > /dev/null \
    && ok "the node counted its system's updates: $(panel info sys_updates) waiting, $(panel info sys_security) security" || bad "the node did not count its updates"
wait_for 60 bash -c "php '$T/node_real.php' lastjob check-updates | grep '^done'" > /dev/null && ok "the node looked for app updates" || bad "check-updates not done"
[ "$(panel field "$CID" dc_update_in)" = 0 ] && ok "Uptime Kuma is the newest version (asked the registry)" || bad "an update is shown for the newest image"
panel act "$CID" update > /dev/null
U=$(wait_for 600 bash -c "php '$T/node_real.php' lastjob update | grep -E '^(done|failed)'")
echo "  $U"
[[ "$U" == "done already the newest version"* ]] && ok "Update: nothing to do for the newest version" || bad "update: $U"
wait_for 60 state_is "$CID" running > /dev/null && ok "still running" || bad "not running after the update: $(panel state "$CID")"
panel settings certs@example.com 1 | grep -qx ok || bad "settings refused"
wait_for 60 bash -c "docker exec $NAME grep -q 'email certs@example.com' /etc/caddy/Caddyfile && echo y" > /dev/null && ok "the node took its settings from the panel (Caddy's email)" || bad "Caddyfile: $(in_box 'cat /etc/caddy/Caddyfile')"
in_box 'grep -q local_certs /etc/caddy/Caddyfile && grep -q skip_install_trust /etc/caddy/Caddyfile && grep -q EMAIL=certs@example.com /etc/sentora-node/install.conf' && ok "and kept them for its own updates" || bad "settings not kept"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "the app is still served (setup password)" || bad "after the settings: HTTP $C"
panel nodeact sys-security | grep -qx ok || bad "sys-security refused"
wait_for 900 bash -c "v=\$(php '$T/node_real.php' info sys_last_at); [ \"\${v:-0}\" -gt 0 ] && echo \$v" > /dev/null \
    && ok "the node installed its security updates (it went $([ "$(panel info sys_last_ok)" = 1 ] && echo well || echo badly))" || bad "security updates did not run"
[ "$(panel info sys_last_ok)" = 1 ] || bad "the security update run failed: $(in_box 'tail -5 /var/log/sentora-node-sysupdate.log')"

say "Deleting"
panel act "$CID" delete > /dev/null
wait_for 180 bash -c "php '$T/node_real.php' state $CID | grep -x deleted" > /dev/null && ok "deleted in the panel" || bad "not deleted: $(panel state "$CID")"
N=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps -a -q | wc -l; runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman volume ls -q | wc -l" | tr '\n' ' ')
[ "$N" = "0 0 " ] && ok "container and its data gone from the node" || bad "left on the node (containers, volumes): $N"
in_box 'grep -c kuma.example.test /etc/caddy/sentora/sites.caddy || true' | grep -qx 0 && ok "its site gone from Caddy" || bad "its site is still in Caddy"
say "Removing the node from the panel"
panel remove | grep -qx online && ok "the online node is asked to remove itself" || bad "remove: not asked"
wait_for 180 bash -c "php '$T/node_real.php' info agent | grep -x gone" > /dev/null && ok "the panel forgot it once it said so" || bad "the node is still in the panel"
for _ in $(seq 1 30); do in_box 'systemctl is-active sentora-node' 2> /dev/null | grep -qx active || break; sleep 3; done
in_box '! systemctl is-active --quiet sentora-node && [ ! -e /etc/sentora-node ] && [ ! -e /usr/local/sbin/sentora-node-agent ]' && ok "the node removed its agent and keys" || bad "the agent is still on the node"
grep -q "PHP \(Warning\|Notice\|Deprecated\|Fatal\)" "$OUT/panel-web.log" && bad "the panel logged PHP problems" || ok "no PHP problems in the panel"
in_box 'journalctl --no-pager | grep -i "php \(warning\|notice\|fatal\|deprecated\)"' && bad "the agent logged PHP problems" || ok "no PHP problems in the agent"

echo
[ $FAILS -eq 0 ] && echo "NODE TEST PASSED" || echo "NODE TEST: $FAILS FAILED"
exit $(( FAILS > 0 ))
