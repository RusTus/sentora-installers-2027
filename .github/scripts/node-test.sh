#!/bin/bash
# Docker Hosting node test: a real AlmaLinux 10 (or Rocky Linux 10) in a container with systemd is made
# a node with node/sentora2027-node-alma10, linked to a stand-in panel (tests/docker_hosting) running
# the add-on, and must then really run an app: rootless Podman as the customer's own user, its limits
# held by the kernel, Caddy in front (HTTPS, setup password first, then public), restarted with the
# "server", deleted with its data.
#   .github/scripts/node-test.sh <module folder> <results folder> [alma10|rocky10]
# Needs docker that can run privileged containers with cgroup v2, php (pdo_sqlite, curl, sodium).
# The container has no SELinux (the runner's kernel has none): the installer's SELinux step is not tested here.
set -uo pipefail
MOD=$(cd "${1:?module folder}" && pwd); OUT=${2:?results folder}; SYS=${3:-alma10}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
REPO=$(cd "$(dirname "$0")/../.." && pwd)
T=$REPO/tests/docker_hosting
case "$SYS" in
    alma10) IMAGE=quay.io/almalinuxorg/almalinux:10 ;;
    rocky10) IMAGE=quay.io/rockylinux/rockylinux:10 ;;
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
read -r KEY CODE < <(panel init)
[[ "$KEY" == v1.* ]] && [[ "$CODE" =~ ^1\. ]] || { echo "::error::the stand-in panel did not start ($KEY $CODE)"; exit 1; }
ok "panel ready, node added (code $CODE)"

say "A fresh $IMAGE with systemd"
# (container images may mask systemd-logind; a server never does)
printf 'FROM %s\nRUN dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all && (systemctl unmask systemd-logind.service || true)\nSTOPSIGNAL SIGRTMIN+3\nCMD ["/sbin/init"]\n' "$IMAGE" > "$W/Dockerfile"
docker build -q -t sentora-node-test "$W" > "$OUT/image-build.log" 2>&1 || { tail -20 "$OUT/image-build.log"; echo "::error::image did not build"; exit 1; }
mkdir -p "$W/home"
# (/home on the runner's disk: rootless Podman's storage can not be overlay on the container's overlay)
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw -v "$W/home":/home \
    --tmpfs /run --tmpfs /run/lock -h node1.example.test sentora-node-test > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done

say "Installing the node"
docker cp "$REPO/node/sentora2027-node-alma10" "$NAME:/root/sentora2027-node"
docker cp "$REPO/node/sentora-node-agent.php" "$NAME:/root/sentora-node-agent.php"
START=$(date +%s)
in_box "cd /root && SNODE_TEST_LOCAL_CERTS=1 bash sentora2027-node --panel http://$GW:18092 --key $KEY --code $CODE --no-tls-check --agent /root/sentora-node-agent.php" 2>&1 | tee "$OUT/node-install-screen.txt"
echo "installed in $(( $(date +%s) - START )) seconds"
grep -q "^Done: this server is a node" "$OUT/node-install-screen.txt" && ok "node installer finished" || { bad "node installer did not finish"; in_box 'tail -40 /var/log/sentora2027-node.log'; exit 1; }
wait_for 60 panel online > /dev/null && ok "node online in the panel" || bad "node not online"
in_box 'stat -c %a /etc/sentora-node/node.json' | grep -qx 600 && ok "node keys readable by root only" || bad "node keys not 0600"
in_box 'systemctl is-active caddy' | grep -qx active && ok "Caddy runs" || bad "Caddy does not run"
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
in_box 'getent passwd sdh3 | cut -d: -f7' | grep -qx /sbin/nologin && ok "sdh3 can not log in" || bad "sdh3 has a login shell"
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
C=$(code "-u setup:$PW")
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
panel act "$CID" stop > /dev/null
wait_for 120 state_is "$CID" stopped > /dev/null && ok "stopped" || bad "not stopped: $(panel state "$CID")"
panel act "$CID" start > /dev/null
wait_for 120 state_is "$CID" running > /dev/null && ok "started again" || bad "not started: $(panel state "$CID")"
U=$(wait_for 400 bash -c "panel() { php '$T/node_real.php' \"\$@\"; }; s=\$(panel state $CID); u=\${s#* }; u=\${u%%MB*}; [ \"\${u:-0}\" -gt 0 ] && echo \$u")
[ -n "$U" ] && ok "disk use measured: $U MB" || bad "no disk use measured"

say "Restarting the node"
docker restart "$NAME" > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
sleep 10
wait_for 120 panel online > /dev/null && ok "node online again after the restart" || bad "node not online after the restart"
wait_for 180 bash -c "docker exec $NAME runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(docker exec $NAME id -u sdh3) podman ps --format '{{.Names}}' | grep -x sdh-$CID" > /dev/null \
    && ok "the container started again by itself" || bad "the container did not start after the restart"
for _ in $(seq 1 30); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it again" || bad "after the restart: HTTP $C"

say "Deleting"
panel act "$CID" delete > /dev/null
wait_for 180 bash -c "php '$T/node_real.php' state $CID | grep -x deleted" > /dev/null && ok "deleted in the panel" || bad "not deleted: $(panel state "$CID")"
N=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps -a -q | wc -l; runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman volume ls -q | wc -l" | tr '\n' ' ')
[ "$N" = "0 0 " ] && ok "container and its data gone from the node" || bad "left on the node (containers, volumes): $N"
in_box 'grep -c kuma.example.test /etc/caddy/sentora/sites.caddy || true' | grep -qx 0 && ok "its site gone from Caddy" || bad "its site is still in Caddy"
grep -q "PHP \(Warning\|Notice\|Deprecated\|Fatal\)" "$OUT/panel-web.log" && bad "the panel logged PHP problems" || ok "no PHP problems in the panel"
in_box 'journalctl -u sentora-node --no-pager | grep -i "php \(warning\|notice\|fatal\|deprecated\)"' && bad "the agent logged PHP problems" || ok "no PHP problems in the agent"

echo
[ $FAILS -eq 0 ] && echo "NODE TEST PASSED" || echo "NODE TEST: $FAILS FAILED"
exit $(( FAILS > 0 ))
