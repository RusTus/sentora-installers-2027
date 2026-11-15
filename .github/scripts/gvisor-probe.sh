#!/bin/bash
# Probe (2026-11-15): does gVisor (runsc) under ROOTLESS Podman keep an app's limits (memory, CPU, processes),
# and does its network (pasta, a published port on 127.0.0.1) work? Run as root on a disposable machine.
set -u
say() { printf '\n=== %s\n' "$*"; }
U=gvprobe
say "system"; . /etc/os-release; echo "$PRETTY_NAME, kernel $(uname -r)"; podman --version
say "runsc"
ARCH=$(uname -m); URL=https://storage.googleapis.com/gvisor/releases/release/latest/$ARCH
cd /tmp && curl -fsSLO "$URL/gvisor.tar.zstd" && curl -fsSLO "$URL/gvisor.tar.zstd.sha512" && sha512sum -c gvisor.tar.zstd.sha512 && tar --zstd -xf gvisor.tar.zstd -C /usr/local/bin && ls -la /usr/local/bin | grep -i -E "runsc|gvisor"
runsc --version | head -2
say "a rootless user"
useradd -m -s /bin/bash $U 2>/dev/null; grep "^$U:" /etc/subuid /etc/subgid
loginctl enable-linger $U; uid=$(id -u $U); for i in $(seq 1 20); do [ -d /run/user/$uid ] && break; sleep 1; done
asu() { ( cd /home/$U && runuser -u $U -- env -i HOME=/home/$U PATH=/usr/local/bin:/usr/bin:/bin XDG_RUNTIME_DIR=/run/user/$uid DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$uid/bus "$@" ); }
asu podman pull -q docker.io/library/busybox:latest > /dev/null && echo pulled
# (round 1: with systemd's cgroups runsc is refused; with --ignore-cgroups it runs without any limit and its
# published port does not answer. Round 2: cgroups through cgroupfs, and gVisor's host network inside the
# app's own namespace)
cat /sys/fs/cgroup/user.slice/user-$uid.slice/user@$uid.service/cgroup.controllers 2>&1; cat /sys/fs/cgroup/cgroup.subtree_control
# Round 3: gVisor does not manage cgroups itself (--ignore-cgroups); the limits are put around it from the
# outside: a systemd slice of the user's own per app (MemoryMax, CPUQuota, TasksMax), the container's
# processes (conmon and the whole sandbox) inside it through --cgroup-parent.
asu systemctl --user set-property --runtime app-test.slice MemoryMax=96M MemorySwapMax=0 CPUQuota=20% TasksMax=200 2>&1 | tail -1
asu systemctl --user show app-test.slice -p MemoryMax -p CPUQuotaPerSecUSec -p TasksMax 2>&1
for mode in "slice" "slice + host network"; do
    flags=(--runtime-flag ignore-cgroups); [ "$mode" = "slice + host network" ] && flags+=(--runtime-flag network=host)
    R=(podman --runtime /usr/local/bin/runsc "${flags[@]}")
    say "$mode: does it start, inside the slice?"
    asu "${R[@]}" run -d --name t1 --cgroup-parent app-test.slice busybox sleep 60 > /dev/null 2>&1; sleep 2
    asu systemctl --user status app-test.slice --no-pager 2>&1 | grep -E "Tasks|Memory|CPU|runsc|conmon" | head -6; asu podman rm -f t1 > /dev/null 2>&1
    say "$mode: memory 96 MB for the whole sandbox, the app takes 300 MB"
    asu "${R[@]}" run --rm --cgroup-parent app-test.slice busybox sh -c 'x=$(head -c 300000000 /dev/zero | tr "\0" a); echo "STILL ALIVE ${#x}"' 2>&1 | tail -2; echo "exit=${PIPESTATUS[0]}"
    say "$mode: a fork bomb (bounded by memory and tasks?)"
    asu timeout 40 "${R[@]}" run --rm --cgroup-parent app-test.slice busybox sh -c 'i=0; while [ $i -lt 3000 ]; do sleep 30 & i=$((i+1)); done 2>/dev/null; echo "made $(ps | wc -l) processes inside"' 2>&1 | tail -2; echo "exit=${PIPESTATUS[0]}"
    asu systemctl --user show app-test.slice -p TasksCurrent -p MemoryCurrent
    say "$mode: 20% CPU, a busy loop for 5 s"
    asu "${R[@]}" run -d --name cpu --cgroup-parent app-test.slice busybox sh -c 'while :; do :; done' > /dev/null 2>&1; sleep 5
    top -bn1 -o %CPU | sed -n '8,11p'; asu systemctl --user show app-test.slice -p CPUUsageNSec
    asu podman rm -f cpu > /dev/null 2>&1
    say "$mode: a published port on 127.0.0.1"
    asu "${R[@]}" run -d --name web --cgroup-parent app-test.slice -p 127.0.0.1:18080:8080 busybox sh -c 'echo hello-from-gvisor > /tmp/i; httpd -f -p 8080 -h /tmp' > /dev/null 2>&1; sleep 4
    curl -s --max-time 5 http://127.0.0.1:18080/i || echo "no answer"
    asu podman logs web 2>&1 | tail -2; asu podman rm -f web > /dev/null 2>&1
    say "$mode: the internet"
    asu "${R[@]}" run --rm --cgroup-parent app-test.slice busybox wget -qO- -T 5 http://example.com 2>&1 | head -c 60; echo
    asu "${R[@]}" run --rm --cgroup-parent app-test.slice busybox uname -r
done
say "for comparison, the standard runtime"
asu podman run --rm --memory 64m busybox sh -c 'x=$(head -c 200000000 /dev/zero | tr "\0" a); echo "STILL ALIVE ${#x}"' 2>&1 | tail -2; echo "memory: exit=${PIPESTATUS[0]}"
asu podman run --rm --pids-limit 20 busybox sh -c 'for i in $(seq 1 60); do sleep 5 & done 2>/dev/null; sleep 1; echo "made $(ps | wc -l) processes"' 2>&1 | tail -1
asu podman run -d --name web -p 127.0.0.1:18080:8080 busybox sh -c 'echo hello-standard > /tmp/i; httpd -f -p 8080 -h /tmp' > /dev/null 2>&1; sleep 3; curl -s --max-time 5 http://127.0.0.1:18080/i || echo "no answer"; asu podman rm -f web > /dev/null 2>&1
