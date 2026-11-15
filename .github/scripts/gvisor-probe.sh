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
for mode in "plain runsc" "runsc --ignore-cgroups"; do
    flags=(); [ "$mode" = "runsc --ignore-cgroups" ] && flags=(--runtime-flag ignore-cgroups)
    say "$mode: does it start?"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run --rm busybox sh -c 'echo started; dmesg 2>/dev/null | head -2' 2>&1 | tail -4
    say "$mode: memory 64 MB, the app takes 200 MB"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run --rm --memory 64m busybox sh -c 'x=$(head -c 200000000 /dev/zero | tr "\0" a); echo "STILL ALIVE ${#x}"' 2>&1 | tail -2; echo "exit=$?"
    say "$mode: processes limited to 20, the app makes 60"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run --rm --pids-limit 20 busybox sh -c 'n=0; for i in $(seq 1 60); do sleep 5 & n=$((n+1)); done; sleep 1; echo "made $(ps | wc -l) processes"' 2>&1 | tail -2
    say "$mode: 0.2 CPU, a busy loop for 3 s"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run -d --name cpu --cpus 0.2 busybox sh -c 'while :; do :; done' > /dev/null 2>&1; sleep 3
    asu podman stats --no-stream --format '{{.CPUPerc}} {{.MemUsage}}' cpu 2>&1 | tail -1; ps -eo pcpu,comm --sort=-pcpu | grep -E "runsc|exe|sh" | head -3
    asu podman rm -f cpu > /dev/null 2>&1
    say "$mode: network (pasta), a published port on 127.0.0.1"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run -d --name web -p 127.0.0.1:18080:8080 busybox sh -c 'echo hello-from-gvisor > /tmp/i; httpd -f -p 8080 -h /tmp' > /dev/null 2>&1; sleep 3
    curl -s --max-time 5 http://127.0.0.1:18080/i || echo "no answer"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run --rm busybox wget -qO- -T 5 http://example.com 2>&1 | head -c 80; echo
    asu podman rm -f web > /dev/null 2>&1
    say "$mode: what the node's kernel sees (is the app inside the sandbox?)"
    asu podman --runtime /usr/local/bin/runsc "${flags[@]}" run --rm busybox uname -a 2>&1 | tail -1
done
say "for comparison, the standard runtime: memory limit"
asu podman run --rm --memory 64m busybox sh -c 'x=$(head -c 200000000 /dev/zero | tr "\0" a); echo "STILL ALIVE ${#x}"' 2>&1 | tail -2
