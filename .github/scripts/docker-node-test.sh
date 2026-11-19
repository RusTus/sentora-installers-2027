#!/bin/bash
# Docker Hosting node test: a real AlmaLinux 10 (or Rocky Linux 10) in a container with systemd is made
# a node with docker-node/sentora2027-docker-node, linked to a stand-in panel (tests/docker_hosting) running
# the add-on, and must then really run an app: rootless Podman as the customer's own user, its limits
# held by the kernel, Caddy in front (HTTPS, setup password first, then public), restarted with the
# "server", deleted with its data.
#   .github/scripts/docker-node-test.sh <module folder> <results folder> [alma10|rocky10|fedora45|debian13|ubuntu26.04]
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
    # (a real machine under KVM, with SELinux enforcing as on a server: the containers above share the runner's
    # Ubuntu kernel, where SELinux is not there at all; every SELinux refusal fails the test)
    alma10-vm) IMAGE=https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/AlmaLinux-10-GenericCloud-latest.x86_64.qcow2; PKGS=dnf; VMBOX=1 ;;
    *) echo "unknown system $SYS"; exit 2 ;;
esac
VMBOX=${VMBOX:-}
NAME=sentora-docker-node-test
FAILS=0
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
ok() { echo "  ok   $1"; }
bad() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); echo "::error::$1"; }
if [ -n "$VMBOX" ]; then
    KEY=$(mktemp -u)
    SSHO=(-i "$KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10 -o ServerAliveInterval=20 -o ServerAliveCountMax=30)
    # (in /, as docker exec is: the customers' users may not enter root's home)
    in_box() { ssh "${SSHO[@]}" -p 2222 root@127.0.0.1 "cd / && { $1
}"; }
    box_cp() { scp "${SSHO[@]}" -P 2222 -q "$1" "root@127.0.0.1:$2"; }
    wait_ssh() { for _ in $(seq 1 "${1:-150}"); do in_box true 2> /dev/null && return 0; sleep 2; done; return 1; }
    box_restart() { in_box 'systemctl reboot' > /dev/null 2>&1; sleep 15; wait_ssh 150; }
else
    in_box() { docker exec "$NAME" bash -c "$1"; }
    box_cp() { docker cp "$1" "$NAME:$2"; }
    box_restart() { docker restart "$NAME" > /dev/null; }
fi
panel() { php "$T/node_real.php" "$@"; }
wait_for() { # wait_for <seconds> <command...>: until the command prints something other than ""/0
    local end=$(( $(date +%s) + $1 )); shift
    while [ "$(date +%s)" -lt "$end" ]; do out=$("$@" 2>/dev/null); case "$out" in ''|0) sleep 3 ;; *) echo "$out"; return 0 ;; esac; done
    return 1
}
diag() { panel log | tail -8; in_box 'journalctl -u sentora-docker-node --no-pager | tail -15; journalctl -u caddy --no-pager | tail -6'; }
state_is() { local s; s=$(panel state "$1"); case "$s" in "$2"*) echo "$s" ;; esac; }

say "Stand-in panel with the add-on"
W=$(mktemp -d)
rm -rf "$T/root/modules"; mkdir -p "$T/root/modules"; cp -r "$MOD" "$T/root/modules/docker_hosting"
export DH_DB="$W/panel.sqlite"
# (app backups need a Sentora backup node for the off-server backup: the stand-in says it has one)
export DH_BACKUP_DIR="$W/dhbackups" DH_OFFSITE_STATE="$W/offsite.json"; echo '{"gate":1}' > "$W/offsite.json"
# (the panel's address as the node sees it: the containers' bridge, or QEMU's address for this machine)
if [ -n "$VMBOX" ]; then LGW=127.0.0.1; GW=10.0.2.2; else GW=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}'); LGW=$GW; fi
php -d display_errors=stderr -d error_reporting=-1 -S "$LGW:18092" -t "$T/root" > "$OUT/panel-web.log" 2>&1 &
WEB=$!
cleanup() {
    kill $WEB 2> /dev/null
    panel log > "$OUT/panel-log.txt" 2>&1
    in_box 'journalctl -u sentora-docker-node --no-pager' > "$OUT/agent.log" 2>&1
    in_box 'journalctl -u caddy --no-pager' > "$OUT/caddy.log" 2>&1
    in_box 'cat /var/log/sentora2027-docker-node.log' > "$OUT/node-install.log" 2>&1
    in_box 'cat /etc/caddy/sentora/sites.caddy' > "$OUT/sites.caddy" 2>&1
    in_box 'journalctl -u crowdsec -u crowdsec-firewall-bouncer --no-pager; cat /var/log/crowdsec.log /var/log/crowdsec-firewall-bouncer.log 2> /dev/null | tail -80' > "$OUT/crowdsec.log" 2>&1
    [ -n "$VMBOX" ] && in_box 'ausearch -m AVC,USER_AVC,SELINUX_ERR -i 2> /dev/null' > "$OUT/selinux-refusals.txt" 2>&1
    if [ -n "$VMBOX" ]; then [ -f "$W/qemu.pid" ] && kill "$(cat "$W/qemu.pid")" 2> /dev/null; [ -n "${TEST_KEEP:-}" ] || rm -rf "$W" "$T/root/modules"
    else [ -n "${TEST_KEEP:-}" ] || { docker exec "$NAME" sh -c 'rm -rf /home/*' > /dev/null 2>&1; docker rm -f "$NAME" > /dev/null 2>&1; rm -rf "$W" "$T/root/modules"; }; fi
}
trap cleanup EXIT
sleep 1
FP=$(panel init)
[[ "$FP" =~ ^[A-Z2-9]{4}-[A-Z2-9]{4}$ ]] || { echo "::error::the stand-in panel did not start ($FP)"; exit 1; }
ok "panel ready (its code: $FP)"

if [ -n "$VMBOX" ]; then
say "A fresh machine: $IMAGE (SELinux enforcing)"
for try in 1 2 3; do curl -fsSL --retry 3 -o "$W/base.img" "$IMAGE" && break; sleep $(( try * 15 )); done
[ -s "$W/base.img" ] || { echo "::error::the cloud image did not download"; exit 1; }
qemu-img create -q -f qcow2 -F qcow2 -b "$W/base.img" "$W/disk.qcow2" 40G
ssh-keygen -q -t ed25519 -N '' -f "$KEY"
printf '#cloud-config\nhostname: node1\nfqdn: node1.example.test\nprefer_fqdn_over_hostname: true\nmanage_etc_hosts: true\ndisable_root: false\nssh_pwauth: false\nusers:\n  - name: root\n    ssh_authorized_keys: ["%s"]\ngrowpart: {mode: auto, devices: [/]}\n' "$(cat "$KEY.pub")" > "$W/user-data"
printf 'instance-id: sentora-node-vm-test\nlocal-hostname: node1\n' > "$W/meta-data"
if command -v cloud-localds > /dev/null; then cloud-localds "$W/seed.iso" "$W/user-data" "$W/meta-data"
else genisoimage -quiet -output "$W/seed.iso" -volid cidata -joliet -rock "$W/user-data" "$W/meta-data"; fi
MEM=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 - 3072 )); [ $MEM -gt 10240 ] && MEM=10240
qemu-system-x86_64 -enable-kvm -cpu host -machine q35 -m "$MEM" -smp "$(nproc)" \
    -drive file="$W/disk.qcow2",if=virtio,cache=unsafe,discard=unmap -drive file="$W/seed.iso",if=virtio,format=raw,readonly=on \
    -netdev user,id=n0,hostfwd=tcp:127.0.0.1:2222-:22 -device virtio-net-pci,netdev=n0 -device virtio-rng-pci \
    -display none -serial file:"$OUT/console.log" -daemonize -pidfile "$W/qemu.pid" || { echo "::error::QEMU did not start"; exit 1; }
wait_ssh 150 || { tail -40 "$OUT/console.log"; echo "::error::the machine did not answer on SSH"; exit 1; }
in_box 'cloud-init status --wait > /dev/null 2>&1; hostname -f; grep PRETTY /etc/os-release; getenforce'
[ "$(in_box getenforce)" = Enforcing ] || { echo "::error::SELinux is not enforcing on this machine"; exit 1; }
else
say "A fresh $IMAGE with systemd"
# (container images may mask systemd-logind; a server never does)
if [ "$PKGS" = apt ]; then
    RUNLINE='apt-get update && DEBIAN_FRONTEND=noninteractive apt-get -y install systemd systemd-sysv dbus procps hostname iproute2 tzdata curl ca-certificates && rm -rf /var/lib/apt/lists/*'
else
    RUNLINE='dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all'
fi
printf 'FROM %s\nRUN %s && (systemctl unmask systemd-logind.service || true)\nSTOPSIGNAL SIGRTMIN+3\nCMD ["/sbin/init"]\n' "$IMAGE" "$RUNLINE" > "$W/Dockerfile"
docker build -q -t sentora-docker-node-test "$W" > "$OUT/image-build.log" 2>&1 || { tail -20 "$OUT/image-build.log"; echo "::error::image did not build"; exit 1; }
mkdir -p "$W/home"
# (/home on the runner's disk: rootless Podman's storage can not be overlay on the container's overlay)
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw -v "$W/home":/home \
    --tmpfs /run --tmpfs /run/lock -h node1.example.test sentora-docker-node-test > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done

fi

say "Installing the node"
box_cp "$REPO/docker-node/sentora2027-docker-node" /root/sentora2027-docker-node
box_cp "$REPO/docker-node/sentora-docker-node-agent.php" /root/sentora-docker-node-agent.php
START=$(date +%s)
# (the node makes its own key; the panel's code is given as the person installing would confirm it)
in_box "cd /root && SNODE_TEST_LOCAL_CERTS=1 bash sentora2027-docker-node --panel http://$GW:18092 --panel-code $FP --agent /root/sentora-docker-node-agent.php" 2>&1 | tee "$OUT/node-install-screen.txt"
echo "installed in $(( $(date +%s) - START )) seconds"
grep -q "^Done: this server is ready to be a node" "$OUT/node-install-screen.txt" && ok "node installer finished" || { bad "node installer did not finish"; in_box 'tail -40 /var/log/sentora2027-docker-node.log'; exit 1; }
NODEKEY=$(grep -oE 'SN1(-[A-Z2-9]{4}){6}' "$OUT/node-install-screen.txt" | head -1)
[ -n "$NODEKEY" ] && ok "the installer showed the node's key" || bad "no node key shown"
in_box 'stat -c %a /root/sentora-docker-node-key.txt' | grep -qx 600 && ok "the key is also kept for root only" || bad "no key file (0600)"
sleep 12
panel online | grep -qx 0 && ok "not linked before it is added in the panel" || bad "linked before it was added"
panel addkey node1 "$NODEKEY" | grep -qx ok && ok "added in the panel with its key" || bad "the panel refused the key"
wait_for 60 panel online > /dev/null && ok "node online in the panel" || bad "node not online"
in_box '[ ! -e /root/sentora-docker-node-key.txt ]' && ok "the key file is gone once linked" || bad "the key file is still there"
in_box 'stat -c %a /etc/sentora-docker-node/node.json' | grep -qx 600 && ok "node keys readable by root only" || bad "node keys not 0600"
# (the agent may be reloading it just now)
wait_for 30 in_box 'systemctl is-active caddy | grep -x active' > /dev/null && ok "Caddy runs" || bad "Caddy does not run"
in_box 'systemctl is-active --quiet firewalld && echo firewalld; ufw status 2> /dev/null | grep -q "^Status: active" && echo ufw' > "$OUT/firewall.txt"
[ -s "$OUT/firewall.txt" ] && ok "the firewall is on ($(head -1 "$OUT/firewall.txt"))" || { bad "no firewall"; in_box 'journalctl -u firewalld --no-pager | tail -8; ufw status verbose'; }
PF=$(in_box 'cat /etc/sentora-docker-node/platform.conf' 2>&1)
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
    in_box 'getcap /usr/bin/newuidmap /usr/bin/newgidmap; grep sdh /etc/subuid /etc/subgid; loginctl list-users; ls -la /run/user; journalctl -u sentora-docker-node --no-pager | tail -20'
    exit 1
fi
in_box 'id sdh3' && ok "customer user sdh3" || bad "no user sdh3"
in_box 'getent passwd sdh3 | cut -d: -f7' | grep -qE '^/[a-z/]*/nologin$' && ok "sdh3 can not log in" || bad "sdh3 has a login shell"
in_box 'grep -q "^sdh3:" /etc/subuid && grep -q "^sdh3:" /etc/subgid' && ok "sdh3 has its own subordinate IDs" || bad "no subuid/subgid for sdh3"
in_box 'ls /var/lib/systemd/linger/sdh3' > /dev/null && ok "sdh3 lingers" || bad "sdh3 does not linger"
PS=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps --format '{{.Names}} {{.Ports}}'")
echo "$PS"
echo "$PS" | grep -q "sdh-$CID 127.0.0.1:20000->3001/tcp" && ok "runs rootless as sdh3, on 127.0.0.1:20000 only" || bad "not running as sdh3 on 127.0.0.1:20000"
in_box 'podman ps -q 2> /dev/null | wc -l' | grep -qx 0 && ok "root runs no containers" || bad "root has containers"
# an app's limit as the kernel holds it: read in the node's cgroup tree, at the container's own cgroup (Fedora 45's
# crun refuses "podman exec" in this test's container: setrlimit RLIMIT_NOFILE); an app in gVisor has its limits on
# its own slice (sentora-app-<id>.slice: runsc runs with --ignore-cgroups inside it), with 64 MB for gVisor itself
RT=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman inspect --format '{{.OCIRuntime}}' sdh-$CID" | tr -d '\r')
ENF=$(in_box 'getenforce 2> /dev/null' | tr -d '\r')
case "$RT" in
    *runsc*) GV=1; [ "$ENF" = Enforcing ] && bad "in gVisor although SELinux is enforcing ($RT)" || ok "runs in gVisor ($RT)" ;;
    *) GV=0; [ "$ENF" = Enforcing ] && ok "SELinux enforcing: the standard runtime ($RT), confined by SELinux (gVisor is not used here: runsc takes no SELinux label)" || bad "not in gVisor: '$RT' (new apps are)" ;;
esac
# (an app in gVisor: its processes inside its own slice, where its limits are; GV=0: nothing to check)
inslice() { [ "$GV" = 1 ] || return 0; INS=$(in_box "e='env XDG_RUNTIME_DIR=/run/user/'\$(id -u sdh3); n=0; for p in \$(runuser -u sdh3 -- \$e podman inspect --format '{{.State.ConmonPid}} {{.State.Pid}}' sdh-$CID); do grep -q '/sentora-app-$CID.slice/' /proc/\$p/cgroup || n=\$((n+1)); done; echo \$n" | tr -d '\r'); [ "$INS" = 0 ]; }
if [ "$GV" = 1 ]; then
    inslice && ok "the app's processes are inside its slice (its limits hold)" || bad "the app's processes are outside its slice: no limits hold ($INS)"
fi
[ "$GV" = 1 ] && in_box "u=\$(id -u sdh3); e='env XDG_RUNTIME_DIR=/run/user/'\$u' DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/'\$u'/bus'; echo '  where the app is:'; find /sys/fs/cgroup -type d -name 'sentora-app-*' | sed 's/^/    /'; c=\$(runuser -u sdh3 -- \$e podman inspect --format '{{.State.ConmonPid}} {{.State.Pid}}' sdh-$CID); for p in \$c; do echo \"    pid \$p: \$(cat /proc/\$p/cgroup 2> /dev/null)\"; done; runuser -u sdh3 -- \$e systemctl --user show sentora-app-$CID.slice -p ActiveState -p ControlGroup -p MemoryMax -p FragmentPath -p DropInPaths | sed 's/^/    /'" 2>&1 | tr -d '\r'
cg() { if [ "$GV" = 1 ]; then
    in_box "d=\$(find /sys/fs/cgroup -type d -name sentora-app-$CID.slice 2> /dev/null | head -1); [ -n \"\$d\" ] && cat \"\$d/$1\"" | tr -d '\r'
  else
    in_box "u=\$(id -u sdh3); p=\$(runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$u podman inspect --format '{{.State.CgroupPath}}' sdh-$CID 2> /dev/null); cat /sys/fs/cgroup\$p/$1 2> /dev/null || runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$u podman exec sdh-$CID cat /sys/fs/cgroup/$1" | tr -d '\r'
  fi; }
# the image check (Trivy 137): installed by the node's installer (pinned, checksum checked), run as its own user;
# the app's image checked when it was made, its answer at the panel (Warn: it runs whatever it found)
in_box '/usr/local/bin/trivy --version 2> /dev/null | head -1; id -u sentora-scan; stat -c "%U:%G %a" /var/lib/sentora-scan/in; ls /var/lib/sentora-scan/in | wc -l' | tr -d '\r' > "$OUT/trivy.txt"
in_box 'grep -q "Image check ready" /var/log/sentora2027-docker-node.log' && ok "Trivy's database downloaded by the installer" || bad "the installer could not download Trivy's database"
grep -q "^Version: 0.76.2" "$OUT/trivy.txt" && ok "Trivy 0.76.2 installed (the pinned release, checksum checked)" || bad "no Trivy: $(cat "$OUT/trivy.txt")"
grep -qx "root:sentora-scan 750" "$OUT/trivy.txt" && ok "images are checked in root's folder that only the check's user may also read" || bad "the check's folder: $(cat "$OUT/trivy.txt")"
[ "$(tail -1 "$OUT/trivy.txt")" = 0 ] && ok "no image copy left after the check" || bad "image copies left: $(tail -1 "$OUT/trivy.txt")"
SCAN=$(wait_for 120 bash -c "php -r 'require \"$T/root/dryden/loader.inc.php\"; echo \$zdbh->query(\"SELECT dc_scan_tx FROM x_dh_containers WHERE dc_id_pk = $CID\")->fetchColumn();' 2> /dev/null | grep '\"cf\"'")
echo "  image check: $SCAN"
[ -n "$SCAN" ] && ok "the app's image was checked, the panel has the answer" || { bad "no image check answer at the panel"; in_box 'journalctl -u sentora-docker-node --no-pager | grep -i "image check\|trivy" | tail -5'; }
# what the kernel should hold: the app's memory (+64 MB for gVisor's own), its CPU, its processes
want_mem() { echo $(( ($1 + 64 * GV) * 1048576 )); }
WANT_PIDS=1024; [ "$GV" = 1 ] && WANT_PIDS=4096
MEMMAX=$(cg memory.max)
[ "$MEMMAX" = "$(want_mem 256)" ] && ok "memory limit held by the kernel (256 MB)" || bad "memory.max: '$MEMMAX' (want $(want_mem 256))"
CPUMAX=$(cg cpu.max)
[ "$CPUMAX" = "50000 100000" ] && ok "CPU limit held by the kernel (0.5)" || bad "cpu.max in the container: '$CPUMAX' (want 50000 100000)"
PIDS=$(cg pids.max)
[ "$PIDS" = "$WANT_PIDS" ] && ok "process limit held ($WANT_PIDS)" || bad "pids.max: '$PIDS' (want $WANT_PIDS)"

say "Caddy in front"
code() { in_box "curl -sk -o /dev/null -w '%{http_code}' --resolve kuma.example.test:443:127.0.0.1 $1 https://kuma.example.test/"; }
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "HTTPS asks for the setup password (401)" || { bad "without the setup password: HTTP $C (want 401)"; in_box "caddy version; caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile 2>&1 | tail -5; sed -n 1,20p /etc/caddy/sentora/sites.caddy; journalctl -u caddy --no-pager | tail -8; journalctl -u sentora-docker-node --no-pager | grep -iE \"caddy|proxy|sites\" | tail -5"; }
PW=$(panel setup "$CID")
for _ in $(seq 1 40); do C=$(code "-u setup:$PW"); case "$C" in 200|302) break ;; esac; sleep 3; done   # (502 while the app still starts)
case "$C" in 200|302) ok "with the setup password: the app answers ($C)" ;; *) bad "with the setup password: HTTP $C" ;; esac
C=$(code "-u setup:wrong-password")
[ "$C" = 401 ] && ok "a wrong password is refused" || bad "wrong password: HTTP $C"
# the web firewall (Caddy with Coraza, 138): the caddy service runs the installer's build, as confined as the
# system's own Caddy; a new app is behind it (On): attacks refused, normal requests through
WF=$(in_box 'systemctl show -p ExecStart caddy | grep -o "/usr/local/bin/caddy-sentora[^ ]*" | head -1; pgrep -fa "^/usr/local/bin/caddy-sentora run" | head -1 | cut -c1-60; /usr/local/bin/caddy-sentora list-modules 2> /dev/null | grep -x http.handlers.waf; [ "$(getenforce 2> /dev/null)" = Enforcing ] && ps -eZ | grep caddy-sentora | grep -o "httpd_t" | head -1; [ "$(getenforce 2> /dev/null)" = Enforcing ] && echo enforcing' | tr -d '\r')
echo "$WF" | grep -q "^/usr/local/bin/caddy-sentora$" && echo "$WF" | grep -q "http.handlers.waf" && ok "the caddy service runs Caddy with Coraza (the installer's build, checksum checked)" || bad "Caddy with Coraza: $WF"
# (SELinux is only enforced where the kernel has it: in the VM test; these nodes run in containers on Ubuntu)
if echo "$WF" | grep -qx enforcing; then echo "$WF" | grep -qx httpd_t && ok "it runs confined as the web server (SELinux httpd_t), as the system's Caddy did" || bad "not confined as httpd_t: $WF"; fi
in_box 'grep -q "^ExecStartPre=/usr/local/bin/caddy-sentora validate" /etc/systemd/system/caddy.service.d/sentora-waf.conf' && ok "the service's own check before starting uses it too" || bad "the check before starting would use the system's Caddy"
grep -q "import sentora_waf_on" <(in_box 'cat /etc/caddy/sentora/sites.caddy') && ok "the new app is behind the web firewall (On, the default)" || bad "the app's site has no firewall: $(in_box 'cat /etc/caddy/sentora/sites.caddy')"
atk() { in_box "curl -sk -o /dev/null -w '%{http_code}' --resolve kuma.example.test:443:127.0.0.1 -u setup:$PW $1"; }
C=$(atk "'https://kuma.example.test/?id=1%27%20OR%20%271%27%3D%271'")
[ "$C" = 403 ] && ok "an SQL injection is refused before the app (403)" || bad "SQL injection: HTTP $C (want 403)"
C=$(atk "-A 'sqlmap/1.8' https://kuma.example.test/")
[ "$C" = 403 ] && ok "an attack tool is refused (403)" || bad "attack tool: HTTP $C (want 403)"
C=$(atk "'https://kuma.example.test/?q=hello%20world'")
case "$C" in 200|302) ok "a normal request goes through ($C)" ;; *) bad "normal request: HTTP $C" ;; esac
# repeat attackers (CrowdSec, 139): from CrowdSec's own signed repository, local only (nothing shared, no
# registration with its servers), reading the apps' access log and SSH; a block reaches the node's firewall
# and the panel, and Unblock in the panel takes it away
CS=$(in_box 'systemctl is-active crowdsec crowdsec-firewall-bouncer | tr "\n" " "; echo; cscli version 2> /dev/null | sed -n "s/^version: //p"; [ -e /etc/crowdsec/online_api_credentials.yaml ] && echo registered || echo not-registered; grep -c "online_client: null" /etc/crowdsec/config.yaml.local; cscli collections list -o raw 2> /dev/null | cut -d, -f1 | grep -cE "^crowdsecurity/(caddy|sshd|linux)$"' | tr -d '\r')
echo "$CS" | sed 's/^/  crowdsec: /'
echo "$CS" | head -1 | grep -qx "active active " && ok "CrowdSec and its firewall blocker run ($(echo "$CS" | sed -n 2p))" || { bad "CrowdSec: $(echo "$CS" | head -1)"; in_box 'systemctl status crowdsec --no-pager | head -12; tail -25 /var/log/crowdsec.log 2> /dev/null; journalctl -u crowdsec --no-pager | tail -15'; }
echo "$CS" | grep -qx not-registered && [ "$(echo "$CS" | sed -n 4p)" = 1 ] && ok "local only: not registered with CrowdSec's servers, its central API off" || bad "not local only: $CS"
# (what CrowdSec's log says about its central API: since the installer's local-only setting, nothing at all)
CAPI=$(in_box 'journalctl -u crowdsec --no-pager -o cat 2> /dev/null | grep -iE "central api|community-blocklist|capi" | tail -5')
[ -z "$CAPI" ] && ok "CrowdSec never contacted its servers (not even at install)" || bad "CrowdSec contacted its servers: $CAPI"
[ "$(echo "$CS" | sed -n 5p)" = 3 ] && ok "it reads the system, SSH and the apps' access log (Caddy)" || bad "collections: $CS"
wait_for 60 in_box "grep -c '\"$GW\"' /etc/crowdsec/parsers/s02-enrich/sentora-safe-list.yaml" > /dev/null && ok "the panel's address ($GW) is on its safe list (never blocked)" || bad "the panel's address is not on the safe list: $(in_box 'cat /etc/crowdsec/parsers/s02-enrich/sentora-safe-list.yaml')"
in_box 'test -s /var/log/caddy/sentora-access.log' && ok "the apps' access log is written (CrowdSec reads it)" || bad "no access log: $(in_box 'ls -laZ /var/log/caddy/ 2>&1; journalctl -u caddy --no-pager | grep -i "log" | tail -3')"
in_box 'cscli decisions add --ip 192.0.2.50 --duration 10m --reason sentora-test' > /dev/null 2>&1
wait_for 60 in_box 'nft list ruleset 2> /dev/null | grep -c 192.0.2.50' > /dev/null && ok "a block reaches the node's firewall (nftables)" || bad "the block is not in the firewall: $(in_box 'nft list tables; journalctl -u crowdsec-firewall-bouncer --no-pager | tail -5')"
wait_for 120 bash -c "php '$T/node_real.php' info cs_blocked | grep -o 192.0.2.50" > /dev/null && ok "the panel sees it" || bad "the panel does not see the block: $(panel info cs_blocked)"
[ "$(panel unblock 192.0.2.50)" = ok ] || bad "Unblock refused"
for _ in $(seq 1 40); do [ "$(in_box 'nft list ruleset 2> /dev/null | grep -c 192.0.2.50')" = 0 ] && break; sleep 3; done
[ "$(in_box 'nft list ruleset 2> /dev/null | grep -c 192.0.2.50')" = 0 ] && ok "Unblock in the panel takes it out of the node's firewall" || bad "still blocked after Unblock"
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
UIDS=$(in_box "nft list set inet sentora_docker_node apps" 2>&1); SDH3=$(in_box "id -u sdh3")
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
for _ in $(seq 1 20); do MEMMAX=$(cg memory.max); [ "$MEMMAX" = "$(want_mem 384)" ] && break; sleep 3; done
[ "$MEMMAX" = "$(want_mem 384)" ] && ok "new limits applied to the running container (384 MB)" || { bad "memory.max after the change: '$MEMMAX'"; diag; }
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
inslice && ok "inside its slice after the start" || bad "outside its slice after the start"
panel act "$CID" restart > /dev/null; sleep 5
wait_for 120 state_is "$CID" running > /dev/null && ok "restarted" || bad "not running after a restart: $(panel state "$CID")"
inslice && ok "inside its slice after the restart" || bad "outside its slice after the restart"
for _ in $(seq 1 30); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it after the restart" || bad "after the restart: HTTP $C"
U=$(wait_for 400 bash -c "panel() { php '$T/node_real.php' \"\$@\"; }; s=\$(panel state $CID); u=\${s#* }; u=\${u%%MB*}; [ \"\${u:-0}\" -gt 0 ] && echo \$u")
[ -n "$U" ] && ok "disk use measured: $U MB" || bad "no disk use measured"

say "An app the panel lost: found again, given back, moved to another customer and back (with its data)"
podu() { in_box "runuser -u $1 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u $1) podman $2"; }
# the app's data, read and written in its data volume on the node, as its user (not through "podman exec", which
# Fedora 45's crun refuses in this test's container; the data is where it must be: in the volume)
vcat() { in_box "runuser -u $1 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u $1) sh -c 'cat \"\$(podman volume inspect --format {{.Mountpoint}} sdh-$CID-0)/$2\"'" | tr -d '\r'; }
vput() { in_box "runuser -u $1 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u $1) sh -c 'echo $3 > \"\$(podman volume inspect --format {{.Mountpoint}} sdh-$CID-0)/$2\"'"; }
vput sdh3 sentora-test.txt "kept-$CID"
# the node's own list loses it too (state.json): it must find it by its label
in_box "php -r '\$f = \"/var/lib/sentora-docker-node/state.json\"; \$s = json_decode(file_get_contents(\$f), true); unset(\$s[\"containers\"][\"$CID\"], \$s[\"scanned_at\"]); file_put_contents(\$f, json_encode(\$s));'"
panel forget "$CID" > /dev/null
F=$(wait_for 120 bash -c "v=\$(php '$T/node_real.php' found $CID fd_domain_vc); [ \"\$v\" != none ] && echo \$v")
[ "$F" = kuma.example.test ] && ok "the node found it again by its label, with its address: $F" || { bad "not found: '$F'"; in_box 'journalctl -u sentora-docker-node --no-pager | tail -20'; }
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
[ "$(vcat sdh4 sentora-test.txt)" = "kept-$CID" ] && ok "with its data" || bad "its data was not moved"
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
[[ "$S" == running* ]] && [ "$(vcat sdh3 sentora-test.txt)" = "kept-$CID" ] && ok "moved back to client1, data kept" || { bad "back: $S / $(panel lastjob move)"; diag; }
in_box 'ls /home/sdh3/.sentora-move /home/sdh4/.sentora-move 2>/dev/null | grep -c tar || true' | grep -qx 0 && ok "no copies left in the work folders" || bad "copies left: $(in_box 'ls -la /home/sdh3/.sentora-move /home/sdh4/.sentora-move 2>&1')"

say "App backups: backed up to the panel, changed, put back"
vput sdh3 sentora-backup.txt before-backup
panel backup "$CID" | grep -qx ok || bad "backup refused: $(panel backup "$CID")"
B=$(wait_for 600 bash -c "php '$T/node_real.php' backupstate $CID | grep -E '^(stored|failed)'"); echo "  $B"
[[ "$B" == stored* ]] && ok "backed up to the panel: $B" || { bad "backup: $B / $(panel state "$CID")"; diag; }
wait_for 120 state_is "$CID" running > /dev/null && ok "the app runs again after its backup" || bad "not running after the backup: $(panel state "$CID")"
vput sdh3 sentora-backup.txt after-backup
panel restore "$CID" | grep -qx ok || bad "restore refused: $(panel restore "$CID")"
R=$(wait_for 900 bash -c "php '$T/node_real.php' lastjob restore | grep -E '^(done|failed)'"); echo "  $R"
wait_for 120 state_is "$CID" running > /dev/null
[ "$(vcat sdh3 sentora-backup.txt)" = before-backup ] && [[ "$R" == done* ]] && ok "put back: the app runs with the backup's data" || { bad "restore: $R / $(panel state "$CID")"; diag; }
vcat sdh3 sentora-test.txt | grep -qx "kept-$CID" && ok "(and the data it had then)" || bad "the restore lost data"
in_box 'ls /home/sdh3/.sentora-move /var/lib/sentora-docker-node/transfer 2>/dev/null | grep -c tar || true' | grep -qx 0 && ok "no copies left on the node" || bad "copies left: $(in_box 'ls -laR /home/sdh3/.sentora-move /var/lib/sentora-docker-node/transfer 2>&1')"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it after the restore" || bad "after the restore: HTTP $C"

say "A private image: downloaded with a registry login, which does not stay on the node"
# (a registry with a password on the node itself, as root, on the node's own network (no port forwarding) and with root's own
# simple storage in a folder of its own (vfs: overlay is not possible inside the test's container, and root's own storage is not touched).
# It listens on the node's own address, as a registry on the local network would: since agent 115 the apps' users may not open
# connections to the node's 127.0.0.1, and the local network is opened for this customer, as an administrator would)
NIP=$(in_box "hostname -I | awk '{print \$1}'"); REG="$NIP:5000"
in_box "REG=$REG; "'mkdir -p /root/regauth /etc/containers/registries.conf.d && php -r "echo \"bot:\", password_hash(\"pw-reg-123\", PASSWORD_BCRYPT), \"\\n\";" > /root/regauth/htpasswd
  printf "[[registry]]\nlocation = \"$REG\"\ninsecure = true\n" > /etc/containers/registries.conf.d/90-sentora-test.conf
  RP="podman --root /var/lib/sentora-test-registry --runroot /run/sentora-test-registry --storage-driver vfs"
  $RP run -d --name testreg --network host -e REGISTRY_HTTP_ADDR=$REG -v /root/regauth:/auth:Z -e REGISTRY_AUTH=htpasswd -e REGISTRY_AUTH_HTPASSWD_REALM=test -e REGISTRY_AUTH_HTPASSWD_PATH=/auth/htpasswd docker.io/library/registry:2 > /dev/null
  $RP create --name testweb docker.io/library/busybox:1 > /dev/null && $RP commit -q --change "CMD [\"busybox\", \"httpd\", \"-f\", \"-p\", \"8080\", \"-h\", \"/etc\"]" testweb $REG/private/web:1 > /dev/null
  for _ in $(seq 1 20); do curl -s -o /dev/null http://$REG/v2/ && break; sleep 1; done
  $RP push -q --tls-verify=false --creds bot:pw-reg-123 $REG/private/web:1' 2>&1 | tail -3
if in_box "curl -s -u bot:pw-reg-123 http://$REG/v2/_catalog" | grep -q private/web; then
    ok "a registry with a login on the local network holds $REG/private/web:1"
    in_box "curl -s -o /dev/null -w '%{http_code}' http://$REG/v2/_catalog" | grep -qx 401 && ok "(it refuses a download without the login)" || bad "the test registry answers without a login"
    panel lan 3 1 | grep -qx ok || bad "lan for the registry: $(panel lan 3 1)"
    PA=$(panel private $REG/private/web:1 8080); [[ "$PA" =~ ^my- ]] || bad "own app refused: $PA"
    [ "$(panel registry $REG bot pw-reg-123)" = ok ] && ok "registry login saved in the panel" || bad "registry login refused"
    PID=$(panel deploy "$PA" priv 1024); [[ "$PID" =~ ^[0-9]+$ ]] || bad "deploy refused: $PID"
    wait_for 600 state_is "$PID" running > /dev/null && ok "the private image was downloaded with the login and runs" || { bad "private app: $(panel state "$PID") / $(panel lastjob create)"; diag; }
    UID3=$(in_box 'id -u sdh3')
    in_box "ls /run/user/$UID3/ | grep -c sentora-pull || true" | grep -qx 0 && ok "no login file left in the user's folder" || bad "login file left: $(in_box "ls -la /run/user/$UID3/")"
    in_box "grep -rl pw-reg-123 /var/lib/sentora-docker-node /etc/sentora-docker-node /home/sdh3 2>/dev/null | head -3" | grep -q . && bad "the login is kept somewhere on the node" || ok "the login is kept nowhere on the node"
    [ "$(panel registry $REG -)" = ok ] || bad "registry login not removed"
    panel act "$PID" update > /dev/null
    U=$(wait_for 600 bash -c "php '$T/node_real.php' lastjob update | grep -E '^(done|failed)'"); echo "  $U"
    [[ "$U" == failed* ]] && [[ "$U" == *pull* ]] && ok "without the login the download is refused (Update failed)" || bad "update without the login: $U"
    wait_for 120 state_is "$PID" running > /dev/null && ok "and the app kept running" || bad "the app stopped: $(panel state "$PID")"
    panel act "$PID" delete > /dev/null
    wait_for 180 bash -c "php '$T/node_real.php' state $PID | grep -x deleted" > /dev/null || bad "private app not deleted"
    panel lan 3 0 > /dev/null
else
    bad "the test registry did not start: $(in_box 'podman --root /var/lib/sentora-test-registry --runroot /run/sentora-test-registry --storage-driver vfs logs testreg 2>&1 | tail -5')"
fi
in_box 'podman --root /var/lib/sentora-test-registry --runroot /run/sentora-test-registry --storage-driver vfs rm -f testreg testweb > /dev/null 2>&1; rm -rf /var/lib/sentora-test-registry; rm -f /etc/containers/registries.conf.d/90-sentora-test.conf' || true

say "Restarting the node"
box_restart
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
sleep 10
wait_for 120 panel online > /dev/null && ok "node online again after the restart" || bad "node not online after the restart"
wait_for 180 in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps --format '{{.Names}}' | grep -x sdh-$CID" > /dev/null \
    && ok "the container started again by itself" || bad "the container did not start after the restart"
for _ in $(seq 1 20); do inslice && break; sleep 3; done
inslice && ok "inside its slice after the restart of the node" || bad "outside its slice after the restart of the node"
for _ in $(seq 1 30); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "Caddy serves it again" || bad "after the restart: HTTP $C"

say "Updating the node (sentora-docker-node-update)"
in_box 'rm -rf /root/src && mkdir /root/src'
box_cp "$REPO/docker-node/sentora2027-docker-node" /root/src/sentora2027-docker-node
box_cp "$REPO/docker-node/sentora-docker-node-agent.php" /root/src/sentora-docker-node-agent.php
# (a "newer" agent: the copy says version 999)
in_box "sed -i \"s/^    const VERSION = '[^']*';/    const VERSION = '999';/\" /root/src/sentora-docker-node-agent.php"
C1=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-docker-node-update --check' 2>&1); echo "$C1"
echo "$C1" | grep -q -- '-> 999 (new)' && ok "--check shows the new agent" || bad "--check: $C1"
U1=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-docker-node-update --yes' 2>&1); echo "$U1" | tail -4
echo "$U1" | grep -q "^Done: this node is up to date (agent 999)" && ok "updated without asking" || bad "the update did not finish"
in_box 'sentora-docker-node-agent status' 2>&1 | grep -c "agent 999" > /dev/null && ok "the new agent runs" || bad "the new agent does not run"
wait_for 60 panel online > /dev/null && ok "node online after the update" || bad "node not online after the update"
grep -q '"agent":"999"' <(php -r 'require "'"$T"'/root/dryden/loader.inc.php"; echo $zdbh->query("SELECT nd_info_tx FROM x_dh_nodes WHERE nd_id_pk = 1")->fetchColumn();' 2>/dev/null) \
    && ok "the panel sees the new agent's version" || bad "the panel does not show agent 999"
in_box "grep -q local_certs /etc/caddy/Caddyfile && grep -q skip_install_trust /etc/caddy/Caddyfile" && ok "the node's settings were kept" || bad "Caddyfile lost its settings"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && [[ "$(panel state "$CID")" == running* ]] && ok "the customer's app kept running behind its setup password" || bad "after the update: HTTP $C, $(panel state "$CID")"
C2=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-docker-node-update --check' 2>&1)
echo "$C2" | grep -q "Agent: up to date (version 999)" && echo "$C2" | grep -q "Node setup: up to date" && ok "--check: up to date" || bad "--check after: $C2"
in_box 'echo "<?php syntax error" > /root/src/sentora-docker-node-agent.php'
U2=$(in_box 'SNODE_RAW_BASE=file:///root/src sentora-docker-node-update --yes' 2>&1)
echo "$U2" | grep -q "damaged: nothing changed" && in_box 'sentora-docker-node-agent status' 2>&1 | grep -c "agent 999" > /dev/null && ok "a damaged download changes nothing" || bad "damaged download: $U2"

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
wait_for 60 in_box "grep -q 'email certs@example.com' /etc/caddy/Caddyfile && echo y" > /dev/null && ok "the node took its settings from the panel (Caddy's email)" || bad "Caddyfile: $(in_box 'cat /etc/caddy/Caddyfile')"
# (install.conf is written once Caddy has reloaded: a moment later)
wait_for 60 in_box "grep -q local_certs /etc/caddy/Caddyfile && grep -q skip_install_trust /etc/caddy/Caddyfile && grep -q EMAIL=certs@example.com /etc/sentora-docker-node/install.conf && echo y" > /dev/null && ok "and kept them for its own updates" || bad "settings not kept"
for _ in $(seq 1 20); do C=$(code ''); [ "$C" = 401 ] && break; sleep 3; done
[ "$C" = 401 ] && ok "the app is still served (setup password)" || bad "after the settings: HTTP $C"
panel nodeact sys-security | grep -qx ok || bad "sys-security refused"
wait_for 900 bash -c "v=\$(php '$T/node_real.php' info sys_last_at); [ \"\${v:-0}\" -gt 0 ] && echo \$v" > /dev/null \
    && ok "the node installed its security updates (it went $([ "$(panel info sys_last_ok)" = 1 ] && echo well || echo badly))" || bad "security updates did not run"
[ "$(panel info sys_last_ok)" = 1 ] || bad "the security update run failed: $(in_box 'tail -5 /var/log/sentora-docker-node-sysupdate.log')"

say "Deleting"
panel act "$CID" delete > /dev/null
wait_for 180 bash -c "php '$T/node_real.php' state $CID | grep -x deleted" > /dev/null && ok "deleted in the panel" || bad "not deleted: $(panel state "$CID")"
N=$(in_box "runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman ps -a -q | wc -l; runuser -u sdh3 -- env XDG_RUNTIME_DIR=/run/user/\$(id -u sdh3) podman volume ls -q | wc -l" | tr '\n' ' ')
[ "$N" = "0 0 " ] && ok "container and its data gone from the node" || bad "left on the node (containers, volumes): $N"
[ "$(podu sdh3 "images -q" | grep -c .)" = 0 ] && ok "(agent 113) and the images it used" || bad "images left after the delete: $(podu sdh3 images)"
in_box 'grep -c kuma.example.test /etc/caddy/sentora/sites.caddy || true' | grep -qx 0 && ok "its site gone from Caddy" || bad "its site is still in Caddy"
say "Removing the node from the panel"
panel remove | grep -qx online && ok "the online node is asked to remove itself" || bad "remove: not asked"
wait_for 180 bash -c "php '$T/node_real.php' info agent | grep -x gone" > /dev/null && ok "the panel forgot it once it said so" || bad "the node is still in the panel"
for _ in $(seq 1 30); do in_box 'systemctl is-active sentora-docker-node' 2> /dev/null | grep -qx active || break; sleep 3; done
in_box '! systemctl is-active --quiet sentora-docker-node && [ ! -e /etc/sentora-docker-node ] && [ ! -e /usr/local/sbin/sentora-docker-node-agent ]' && ok "the node removed its agent and keys" || bad "the agent is still on the node"
grep -q "PHP \(Warning\|Notice\|Deprecated\|Fatal\)" "$OUT/panel-web.log" && bad "the panel logged PHP problems" || ok "no PHP problems in the panel"
in_box 'journalctl --no-pager | grep -i "php \(warning\|notice\|fatal\|deprecated\)"' && bad "the agent logged PHP problems" || ok "no PHP problems in the agent"

echo
if [ -n "$VMBOX" ]; then
    say "SELinux: what it refused during all of this"
    AV=$(in_box 'ausearch -m AVC,USER_AVC,SELINUX_ERR -i 2> /dev/null | grep -E "^type=(AVC|USER_AVC|SELINUX_ERR)"')
    if [ -z "$AV" ]; then ok "SELinux (enforcing) refused nothing: Trivy, Caddy with Coraza, Podman and the agent all worked confined"
    else bad "SELinux refused $(echo "$AV" | wc -l) thing(s)"; echo "$AV" | sed -E 's/.*(denied|granted) +\{ ([^}]*) \} for +pid=[0-9]+ +comm=([^ ]*).* scontext=([^ ]*) tcontext=([^ ]*) tclass=([^ ]*).*/  \1 \2 by \3: \4 -> \5 (\6)/' | sort | uniq -c | sort -rn | head -30; fi
fi
[ $FAILS -eq 0 ] && echo "NODE TEST PASSED" || echo "NODE TEST: $FAILS FAILED"
exit $(( FAILS > 0 ))
