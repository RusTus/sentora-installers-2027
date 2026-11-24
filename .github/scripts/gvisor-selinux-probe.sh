#!/bin/bash
# Probe (2026-11-24): gVisor (runsc) under rootless Podman on a real AlmaLinux 10 with SELinux ENFORCING, in
# Sentora's own SELinux domain for it (docker-node/selinux/sentora_runsc.te), started by docker-node/runsc-sentora
# at the app's own MCS level. Boots the cloud image under KVM (as the Docker node test's alma10-vm), runs the
# inside part as root there, and keeps what it found in the results folder.
#   .github/scripts/gvisor-selinux-probe.sh <results folder>
set -uo pipefail
OUT=${1:?results folder}; mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
REPO=$(cd "$(dirname "$0")/../.." && pwd)
IMAGE=${PROBE_IMAGE:-https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/AlmaLinux-10-GenericCloud-latest.x86_64.qcow2}
W=$(mktemp -d); KEY=$W/key
SSHO=(-i "$KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10 -o ServerAliveInterval=20 -o ServerAliveCountMax=30)
in_box() { ssh "${SSHO[@]}" -p 2222 root@127.0.0.1 "cd / && { $1
}"; }
box_cp() { scp "${SSHO[@]}" -P 2222 -q -r "$1" "root@127.0.0.1:$2"; }
trap '[ -f "$W/qemu.pid" ] && kill "$(cat "$W/qemu.pid")" 2> /dev/null' EXIT
for try in 1 2 3; do curl -fsSL --retry 3 -o "$W/base.img" "$IMAGE" && break; sleep $(( try * 15 )); done
qemu-img create -q -f qcow2 -F qcow2 -b "$W/base.img" "$W/disk.qcow2" 30G
ssh-keygen -q -t ed25519 -N '' -f "$KEY"
printf '#cloud-config\nhostname: node1\ndisable_root: false\nssh_pwauth: false\nusers:\n  - name: root\n    ssh_authorized_keys: ["%s"]\ngrowpart: {mode: auto, devices: [/]}\n' "$(cat "$KEY.pub")" > "$W/user-data"
printf 'instance-id: gvisor-selinux-probe\nlocal-hostname: node1\n' > "$W/meta-data"
cloud-localds "$W/seed.iso" "$W/user-data" "$W/meta-data"
MEM=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 - 3072 )); [ $MEM -gt 8192 ] && MEM=8192
qemu-system-x86_64 -enable-kvm -cpu host -machine q35 -m "$MEM" -smp "$(nproc)" \
    -drive file="$W/disk.qcow2",if=virtio,cache=unsafe -drive file="$W/seed.iso",if=virtio,format=raw,readonly=on \
    -netdev user,id=n0,hostfwd=tcp:127.0.0.1:2222-:22 -device virtio-net-pci,netdev=n0 -device virtio-rng-pci \
    -display none -serial file:"$OUT/console.log" -daemonize -pidfile "$W/qemu.pid" || { echo "::error::QEMU did not start"; exit 1; }
for _ in $(seq 1 150); do in_box true 2> /dev/null && break; sleep 2; done
in_box 'cloud-init status --wait > /dev/null 2>&1; grep PRETTY /etc/os-release; getenforce' || { tail -30 "$OUT/console.log"; exit 1; }
in_box 'mkdir -p /root/probe'
box_cp "$REPO/docker-node/selinux" /root/probe/
box_cp "$REPO/docker-node/runsc-sentora" /root/probe/runsc-sentora
box_cp "$REPO/.github/scripts/gvisor-selinux-probe-inside.sh" /root/probe/inside.sh
in_box 'bash /root/probe/inside.sh' 2>&1 | tee "$OUT/probe.txt"
in_box "grep -E '^type=(AVC|USER_AVC|SELINUX_ERR)' /var/log/audit/audit.log" > "$OUT/selinux-all.txt" 2>&1
in_box 'cat /root/probe/out/* 2> /dev/null' > /dev/null
scp "${SSHO[@]}" -P 2222 -q -r root@127.0.0.1:/root/probe/out "$OUT/inside" 2> /dev/null
exit 0
