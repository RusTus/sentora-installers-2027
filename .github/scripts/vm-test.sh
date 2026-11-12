#!/usr/bin/env bash
# Sentora on a real virtual machine, with the system's own security module on as on a server:
# SELinux enforcing on AlmaLinux, AppArmor on Debian and Ubuntu. (The install test's containers share
# the runner's kernel, so SELinux is not there at all and AppArmor only partly.)
#
#   vm-test.sh <system> <core checkout> <installers checkout> <results folder>
#     system: alma10 | deb13 | ub26.04
#
# Steps: the system's own cloud image boots under KVM; Sentora is installed from the two checkouts
# (served from this machine, as the install test does); the machine restarts; the post-install
# check and the smoke test run; then vm-exercise.sh uses everything Sentora offers (panel pages and
# buttons, mail in and out, IMAP, POP3, webmail, SOGo, ActiveSync, FTP, websites' PHP, cron, backups,
# fail2ban, the hourly and daily jobs, logrotate, service restarts). Any refusal by SELinux or
# AppArmor since the restart fails the test. Then the hidden refusals (rules marked "dontaudit",
# which refuse without a log line) are shown (semodule -DB), the exercises run once more, and what
# they hit is reported, not failed: most are harmless probes, but a feature that broke silently shows
# up there. The machine is thrown away afterwards.
#
# Needs: /dev/kvm, qemu-system-x86_64, qemu-img, cloud-localds (or genisoimage), ssh, python3.
set -uo pipefail
SYS=${1:?system}; CORE=$(cd "${2:?core checkout}" && pwd); INST=$(cd "${3:?installers checkout}" && pwd); OUT=${4:?results folder}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
case "$SYS" in
    alma10) IMG=https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/AlmaLinux-10-GenericCloud-latest.x86_64.qcow2
        INSTALLER=sentora2027-alma10; TAG=alma10; MAC=selinux ;;
    deb13) IMG=https://cloud.debian.org/images/cloud/trixie/latest/debian-13-generic-amd64.qcow2
        INSTALLER=sentora2027-deb13; TAG=debian13; MAC=apparmor ;;
    ub26.04) IMG=https://cloud-images.ubuntu.com/releases/26.04/release/ubuntu-26.04-server-cloudimg-amd64.img
        INSTALLER=sentora2027-ub26.04; TAG=ubuntu26.04; MAC=apparmor ;;
    *) echo "unknown system $SYS (alma10, deb13, ub26.04)"; exit 2 ;;
esac
PORT=${TEST_PORT:-8088}; SSHP=${TEST_SSH_PORT:-2222}
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
VMDIR="$OUT/vm"; mkdir -p "$VMDIR"
KEY="$VMDIR/key"
SSHO=(-i "$KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10 -o ServerAliveInterval=20 -o ServerAliveCountMax=30)
vm() { ssh "${SSHO[@]}" -p "$SSHP" root@127.0.0.1 "$@"; }
to_vm() { scp "${SSHO[@]}" -P "$SSHP" -q "$1" "root@127.0.0.1:$2"; }
wait_ssh() { for _ in $(seq 1 "${1:-150}"); do vm true 2> /dev/null && return 0; sleep 2; done; return 1; }

[ -w /dev/kvm ] || { echo "::error::no usable /dev/kvm on this machine"; exit 1; }

say "Files the installer downloads"
DL="$OUT/dl"; rm -rf "$DL"; mkdir -p "$DL"
git -C "$CORE" archive --prefix=sentora-core-2027/ -o "$DL/sentora-core-2027.zip" HEAD
git -C "$INST" archive --prefix=sentora-installers-2027/ -o "$DL/sentora-installers-2027.zip" HEAD
if command -v gh > /dev/null && gh release download current --repo RusTus/sentora-installers-2027 -p "sentora-php-*-$TAG-amd64.tar.gz" -D "$DL" 2> /dev/null; then
    echo "PHP bundles: $(cd "$DL" && ls sentora-php-* 2> /dev/null | tr '\n' ' ')"
fi
if [ "$SYS" = alma10 ] && command -v gh > /dev/null; then
    gh release download current --repo RusTus/sentora-installers-2027 -p "sentora-sogo-$TAG-amd64.tar.gz" -D "$DL" 2> /dev/null || true
fi
ls -la "$DL"
# (the machine reaches this one at 10.0.2.2, QEMU's address for the host)
( cd "$DL" && exec python3 -m http.server "$PORT" --bind 127.0.0.1 > "$OUT/http.log" 2>&1 ) &
HTTP=$!
QPID=""
cleanup() {
    kill "$HTTP" 2> /dev/null
    [ -n "$QPID" ] && kill "$QPID" 2> /dev/null
    rm -f "$VMDIR/disk.qcow2" "$VMDIR/base.img"
}
trap cleanup EXIT

say "The machine: $IMG"
for try in 1 2 3; do curl -fsSL --retry 3 -o "$VMDIR/base.img" "$IMG" && break; sleep $(( try * 15 )); done
[ -s "$VMDIR/base.img" ] || { echo "::error::the cloud image did not download"; exit 1; }
qemu-img create -q -f qcow2 -F "$(qemu-img info --output=json "$VMDIR/base.img" | python3 -c 'import json,sys;print(json.load(sys.stdin)["format"])')" -b "$VMDIR/base.img" "$VMDIR/disk.qcow2" 40G
ssh-keygen -q -t ed25519 -N '' -f "$KEY"
cat > "$VMDIR/user-data" <<EOF
#cloud-config
hostname: panel
fqdn: panel.example.test
prefer_fqdn_over_hostname: true
manage_etc_hosts: true
disable_root: false
ssh_pwauth: false
users:
  - name: root
    ssh_authorized_keys: ["$(cat "$KEY.pub")"]
growpart: {mode: auto, devices: ['/']}
EOF
printf 'instance-id: sentora-vm-test\nlocal-hostname: panel\n' > "$VMDIR/meta-data"
if command -v cloud-localds > /dev/null; then cloud-localds "$VMDIR/seed.iso" "$VMDIR/user-data" "$VMDIR/meta-data"
else genisoimage -quiet -output "$VMDIR/seed.iso" -volid cidata -joliet -rock "$VMDIR/user-data" "$VMDIR/meta-data"; fi
MEM=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 - 3072 )); [ $MEM -gt 10240 ] && MEM=10240
qemu-system-x86_64 -enable-kvm -cpu host -machine q35 -m "$MEM" -smp "$(nproc)" \
    -drive file="$VMDIR/disk.qcow2",if=virtio,cache=unsafe,discard=unmap -drive file="$VMDIR/seed.iso",if=virtio,format=raw,readonly=on \
    -netdev user,id=n0,hostfwd=tcp:127.0.0.1:"$SSHP"-:22,hostfwd=tcp:127.0.0.1:8443-:443,hostfwd=tcp:127.0.0.1:8025-:25,hostfwd=tcp:127.0.0.1:8077-:7777 \
    -device virtio-net-pci,netdev=n0 -device virtio-rng-pci \
    -display none -serial file:"$OUT/console.log" -daemonize -pidfile "$VMDIR/qemu.pid" || { echo "::error::QEMU did not start"; exit 1; }
QPID=$(cat "$VMDIR/qemu.pid")
echo "QEMU $QPID: $MEM MB, $(nproc) CPUs"
wait_ssh 150 || { tail -40 "$OUT/console.log"; echo "::error::the machine did not answer on SSH"; exit 1; }
vm 'cloud-init status --wait > /dev/null 2>&1; hostname -f; cat /etc/os-release | grep PRETTY; uname -r'
if [ "$MAC" = selinux ]; then
    vm 'getenforce' | tee "$OUT/getenforce.txt"
    grep -q Enforcing "$OUT/getenforce.txt" || { echo "::error::SELinux is not enforcing on this image"; exit 1; }
else
    vm 'aa-enabled 2>&1; aa-status 2> /dev/null | head -2'
fi

say "Installing ($INSTALLER)"
sed "s|^SENTORA_DOWNLOAD_URL=\"\"|SENTORA_DOWNLOAD_URL=\"http://10.0.2.2:$PORT\"|" "$INST/$INSTALLER" > "$VMDIR/$INSTALLER"
grep -q "^SENTORA_DOWNLOAD_URL=\"http://10.0.2.2:$PORT\"" "$VMDIR/$INSTALLER" || { echo "::error::could not point $INSTALLER at the test files"; exit 1; }
to_vm "$VMDIR/$INSTALLER" "/root/$INSTALLER"
START=$(date +%s)
# (in the background on the machine, so a dropped SSH connection does not stop it)
vm "cd /root && ADMIN_NAME='Test Admin' ADMIN_EMAIL=admin@example.com ADMIN_PHONE=555 ADMIN_ADDRESS='1 Main St' \
    ADMIN_PROVINCE=TX ADMIN_POSTALCODE=75001 ADMIN_COUNTRY=US \
    setsid nohup bash -c 'timeout 3600 bash $INSTALLER -t UTC -d panel.example.test -i local < /dev/null > /root/screen.txt 2>&1; echo \$? > /root/install-rc' > /dev/null 2>&1 &"
for _ in $(seq 1 400); do vm 'test -f /root/install-rc' 2> /dev/null && break; sleep 10; done
vm 'cat /root/screen.txt' > "$OUT/install-screen.txt" 2>&1
vm 'cat /root/*_sentora_install.log' > "$OUT/install.log" 2>&1
echo "the installer ended with $(vm 'cat /root/install-rc' 2> /dev/null) after $(( ($(date +%s) - START) / 60 )) minutes"
grep -q "Sentora is installed" "$OUT/install-screen.txt" || { tail -40 "$OUT/install-screen.txt"; echo "::error::$SYS: the installation did not finish"; exit 1; }

say "Restarting the machine"
vm 'systemctl reboot' > /dev/null 2>&1; sleep 15
wait_ssh 150 || { tail -40 "$OUT/console.log"; echo "::error::the machine did not come back after the restart"; exit 1; }
for _ in $(seq 1 90); do vm 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
vm 'systemctl is-system-running; systemctl --failed --no-legend' | tee "$OUT/failed-units.txt"
[ "$MAC" = selinux ] && vm 'getenforce; semodule -l | grep -x sentora || echo "Sentora'"'"'s SELinux rules are NOT loaded"' | tee -a "$OUT/getenforce.txt"
BOOTED=$(vm 'date +%s')   # (refusals count from here: the machine as it runs after the restart)
vm 'touch /root/.hushlogin'

say "The panel from outside the machine (through its firewall)"
curl -sk -o /dev/null -w "  https: HTTP %{http_code}\n" --resolve panel.example.test:8443:127.0.0.1 https://panel.example.test:8443/ | tee "$OUT/outside.txt"
timeout 10 bash -c 'exec 3<>/dev/tcp/127.0.0.1/8025; head -1 <&3' 2> /dev/null | sed 's/^/  smtp: /' | tee -a "$OUT/outside.txt"

say "One whole daemon run first (as the panel's Run the daemon now starts it)"
for _ in $(seq 1 60); do vm 'mysqladmin ping > /dev/null 2>&1 || mariadb-admin ping > /dev/null 2>&1' && break; sleep 2; done
vm 'f=/var/sentora/logs/daemon-panel-run.log; rm -f $f; runuser -u sentora -- /etc/sentora/panel/bin/zsudo daemon-run
    for i in $(seq 1 300); do grep -q "Daemon run complete" $f 2> /dev/null && break; sleep 2; done
    # (and none from cron still busy)
    for i in $(seq 1 150); do pgrep -f "bin/daemon.php" > /dev/null || break; sleep 2; done
    tail -1 $f'

say "sentora-post-install-check"
vm 'sentora-post-install-check' > "$OUT/post-install-check.txt" 2>&1
grep -E '^\s+(FAIL|WARN)|^Result' "$OUT/post-install-check.txt"
FAILS=$(grep -oE 'Result: .* ([0-9]+) FAIL' "$OUT/post-install-check.txt" | grep -oE '[0-9]+ FAIL' | grep -oE '[0-9]+')

say "sentora-smoke-test"
vm 'sentora-smoke-test' > "$OUT/smoke-test.txt" 2>&1
grep -E '^\s+\[|RESULT' "$OUT/smoke-test.txt" || tail -20 "$OUT/smoke-test.txt"

say "Using everything (round 1: the system's rules as they are)"
to_vm "$INST/.github/scripts/vm-exercise.sh" /root/vm-exercise.sh
vm 'bash /root/vm-exercise.sh one' 2>&1 | tee "$OUT/exercise-1.txt"

# what the security module refused between two moments (seconds since 1970, the machine's clock)
refusals() { # refusals <file> <from> <to>
    if [ "$MAC" = selinux ]; then
        vm 'cat /var/log/audit/audit.log.1 /var/log/audit/audit.log 2> /dev/null' > "$VMDIR/audit.log"
        python3 - "$VMDIR/audit.log" "$2" "$3" "$1" <<'EOF_PY'
import re, sys, collections
src, a, b, out = sys.argv[1], float(sys.argv[2]), float(sys.argv[3]), sys.argv[4]
lines, kinds = [], collections.Counter()
def f(rx, l, d=''):
    m = re.search(rx, l); return m.group(1) if m else d
for l in open(src, errors='replace'):
    if not re.search(r'type=(AVC|USER_AVC|SELINUX_ERR|USER_SELINUX_ERR) ', l) or ('denied' not in l and 'SELINUX_ERR' not in l):
        continue
    t = float(f(r'audit\(([\d.]+)', l, '0'))
    if t < a or t > b:
        continue
    lines.append(l.rstrip())
    what = f(r' (?:name|path)="([^"]*)"', l) or ('capability ' + f(r'capability=(\d+)', l) if 'capability=' in l else '')
    kinds['%-22s %-16s {%s} %s -> %s (%s)' % (f(r'scontext=\w+:\w+:(\w+)', l), f(r'comm="([^"]*)"', l), f(r'denied\s+\{ ([^}]*) \}', l), what,
          f(r'tcontext=\w+:\w+:(\w+)', l), f(r'tclass=(\w+)', l))] += 1
open(out, 'w').write(''.join(x + '\n' for x in lines))
open(out + '.summary', 'w').write(''.join('%5d  %s\n' % (n, k) for k, n in kinds.most_common()))
EOF_PY
    else
        # (Ubuntu's own profile for "who", which its login message runs as root at every SSH login, is the
        # system's, not Sentora's; the test's SSH logins would otherwise count against Sentora)
        vm 'journalctl -k -b -o short-unix --no-pager 2> /dev/null' | grep 'apparmor="DENIED"' \
            | awk -v a="$2" -v b="$3" '{t = $1 + 0} t >= a && t <= b' | grep -v 'profile="who"' > "$1" || true
        awk '{o="";n=split("operation profile name requested_mask capname",k," ");for(i=1;i<=n;i++)if(match($0,k[i]"=\"[^\"]*\""))o=o" "substr($0,RSTART,RLENGTH);print o}' "$1" | sort | uniq -c | sort -rn > "$1.summary"
    fi
}
END1=$(vm 'date +%s')
say "What $MAC refused since the restart (must be nothing)"
refusals "$OUT/denied.txt" "$BOOTED" "$END1"
if [ -s "$OUT/denied.txt" ]; then head -60 "$OUT/denied.txt.summary"; else echo "  nothing refused"; fi

HIDDEN=0
if [ "$MAC" = selinux ]; then
    say "Hidden refusals: dontaudit rules shown (semodule -DB), everything used once more"
    vm 'semodule -DB' && HIDDEN=1
    FROM2=$(vm 'date +%s')
    vm 'bash /root/vm-exercise.sh two' 2>&1 | tee "$OUT/exercise-2.txt"
    END2=$(vm 'date +%s')
    vm 'semodule -B'
    refusals "$OUT/hidden.txt" "$FROM2" "$END2"
    if [ -s "$OUT/hidden.txt" ]; then echo "  (reported, not failed)"; head -80 "$OUT/hidden.txt.summary"; else echo "  nothing hidden either"; fi
fi

say "Logs kept"
vm 'tar czf /root/logs.tgz /var/sentora/logs /var/log/audit /var/lib/sentora/*.log /etc/fail2ban/jail.d 2> /dev/null; journalctl -b --no-pager > /root/journal.txt 2>&1; true'
scp "${SSHO[@]}" -P "$SSHP" -q root@127.0.0.1:/root/logs.tgz "$OUT/logs.tgz" 2> /dev/null
scp "${SSHO[@]}" -P "$SSHP" -q root@127.0.0.1:/root/journal.txt "$OUT/journal.log" 2> /dev/null

say "Result for $SYS (a real machine, $MAC on)"
ok=1
[ "${FAILS:-x}" = 0 ] || { echo "::error::$SYS: the post-install check has ${FAILS:-an unreadable number of} FAIL line(s)"; ok=0; }
grep -q 'RESULT: PASSED' "$OUT/smoke-test.txt" || { echo "::error::$SYS: the smoke test did not pass"; ok=0; }
grep -q 'EXERCISE: .* 0 FAIL' "$OUT/exercise-1.txt" || { echo "::error::$SYS: some exercises failed (exercise-1.txt)"; ok=0; }
grep -q "NOT loaded" "$OUT/getenforce.txt" 2> /dev/null && { echo "::error::$SYS: Sentora's SELinux rules (sentora.cil) did not load"; ok=0; }
[ -s "$OUT/denied.txt" ] && { echo "::error::$SYS: $MAC refused something Sentora does (denied.txt, denied.txt.summary)"; ok=0; }
[ "$HIDDEN" = 1 ] && [ -s "$OUT/hidden.txt" ] && echo "::warning::$SYS: $(wc -l < "$OUT/hidden.txt") hidden refusal(s) while using everything (hidden.txt.summary): look at them, most are harmless"
[ $ok -eq 1 ] && echo "$SYS: installed on a real machine with $MAC on, checks and smoke test passed, everything used, nothing refused"
[ $ok -eq 1 ]
