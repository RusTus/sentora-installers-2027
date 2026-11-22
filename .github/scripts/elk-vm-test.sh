#!/usr/bin/env bash
# The Elk node on a real virtual machine with SELinux enforcing (AlmaLinux 10 under KVM on the runner): the install
# test's containers share the runner's kernel, so SELinux is not there at all.
#
#   elk-vm-test.sh <installers checkout> <results folder>
#
# The node is installed with a test panel certificate (--panel-cert), then every source is fed from the runner through
# QEMU's port forwarding (the machine sees the runner as 10.0.2.2): the panel's lines over TLS on 6514, a FortiGate on
# 6516, Veeam on 6517, a domain controller (Filebeat, Winlogbeat's protocol) on 6518, VMware's vCenter simulator read
# by Metricbeat, Microsoft 365 through a stand-in for Microsoft Graph; the alerts are checked. Every service must run,
# every source must arrive, and SELinux must refuse nothing from before the install to the end; its hidden refusals
# (dontaudit) are shown and reported. The machine is thrown away afterwards.
#
# Needs: /dev/kvm, qemu-system-x86_64, qemu-img, cloud-localds (or genisoimage), ssh, python3, docker.
set -uo pipefail
INST=$(cd "${1:?installers checkout}" && pwd); OUT=${2:?results folder}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
IMG=https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/AlmaLinux-10-GenericCloud-latest.x86_64.qcow2
SSHP=2222
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
PASS=0 FAIL=0
ok() { echo "  ok   $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL $1"; echo "::error::$1"; FAIL=$((FAIL + 1)); }
VMDIR="$OUT/vm"; W="$OUT/w"; mkdir -p "$VMDIR" "$W"
KEY="$VMDIR/key"
SSHO=(-i "$KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10 -o ServerAliveInterval=20 -o ServerAliveCountMax=30)
vm() { ssh "${SSHO[@]}" -p "$SSHP" root@127.0.0.1 "$@"; }
to_vm() { scp "${SSHO[@]}" -P "$SSHP" -q "$1" "root@127.0.0.1:$2"; }
wait_ssh() { for _ in $(seq 1 "${1:-150}"); do vm true 2> /dev/null && return 0; sleep 2; done; return 1; }
es() { vm "curl -sS --cacert /etc/sentora-elk/http_ca.crt -u elastic:\$(cat /etc/sentora-elk/elastic.pw) -H 'Content-Type: application/json' $*"; }
cnt() { es "'https://127.0.0.1:9200/$1/_count' -d '$2'" 2> /dev/null | sed -n 's/.*"count":\([0-9]*\).*/\1/p'; }
waitcnt() { local n=0; for _ in $(seq 1 60); do n=$(cnt "$1" "$2"); [ "${n:-0}" -ge "$3" ] && break; sleep 5; done; echo "${n:-0}"; }
QPID=""
cleanup() { [ -n "$QPID" ] && kill "$QPID" 2> /dev/null; docker rm -f elk-vm-vcsim elk-vm-fb > /dev/null 2>&1; rm -f "$VMDIR/disk.qcow2" "$VMDIR/base.img"; }
trap cleanup EXIT
[ -w /dev/kvm ] || { echo "::error::no usable /dev/kvm on this machine"; exit 1; }

say "The machine: $IMG"
for try in 1 2 3; do curl -fsSL --retry 3 -o "$VMDIR/base.img" "$IMG" && break; sleep $(( try * 15 )); done
[ -s "$VMDIR/base.img" ] || { echo "::error::the cloud image did not download"; exit 1; }
qemu-img create -q -f qcow2 -F qcow2 -b "$VMDIR/base.img" "$VMDIR/disk.qcow2" 40G
ssh-keygen -q -t ed25519 -N '' -f "$KEY"
cat > "$VMDIR/user-data" <<EOF
#cloud-config
hostname: elk1
fqdn: elk1.example.test
prefer_fqdn_over_hostname: true
manage_etc_hosts: true
disable_root: false
ssh_pwauth: false
users:
  - name: root
    ssh_authorized_keys: ["$(cat "$KEY.pub")"]
growpart: {mode: auto, devices: ['/']}
EOF
printf 'instance-id: sentora-elk-vm-test\nlocal-hostname: elk1\n' > "$VMDIR/meta-data"
if command -v cloud-localds > /dev/null; then cloud-localds "$VMDIR/seed.iso" "$VMDIR/user-data" "$VMDIR/meta-data"
else genisoimage -quiet -output "$VMDIR/seed.iso" -volid cidata -joliet -rock "$VMDIR/user-data" "$VMDIR/meta-data"; fi
MEM=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 - 3072 )); [ $MEM -gt 10240 ] && MEM=10240
FWD="hostfwd=tcp:127.0.0.1:$SSHP-:22,hostfwd=tcp:127.0.0.1:8443-:443"
for p in 6514 6515 6516 6517 6518; do FWD="$FWD,hostfwd=tcp:127.0.0.1:$p-:$p"; done
qemu-system-x86_64 -enable-kvm -cpu host -machine q35 -m "$MEM" -smp "$(nproc)" \
    -drive file="$VMDIR/disk.qcow2",if=virtio,cache=unsafe,discard=unmap -drive file="$VMDIR/seed.iso",if=virtio,format=raw,readonly=on \
    -netdev user,id=n0,$FWD -device virtio-net-pci,netdev=n0 -device virtio-rng-pci \
    -display none -serial file:"$OUT/console.log" -daemonize -pidfile "$VMDIR/qemu.pid" || { echo "::error::QEMU did not start"; exit 1; }
QPID=$(cat "$VMDIR/qemu.pid")
echo "QEMU $QPID: $MEM MB, $(nproc) CPUs"
wait_ssh 150 || { tail -40 "$OUT/console.log"; echo "::error::the machine did not answer on SSH"; exit 1; }
vm 'cloud-init status --wait > /dev/null 2>&1; hostname -f; grep PRETTY /etc/os-release; uname -r; getenforce' | tee "$OUT/machine.txt"
grep -q Enforcing "$OUT/machine.txt" || { echo "::error::SELinux is not enforcing on this image"; exit 1; }

say "A test panel certificate, and the node installed"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$W/panel.key" -out "$W/panel.crt" -days 2 -subj /CN=panel.example.test 2> /dev/null
vm 'mkdir -p /root/elk-node'
tar -C "$INST" -czf "$W/elk-node.tgz" elk-node tests/elk
to_vm "$W/elk-node.tgz" /root/elk-node.tgz; to_vm "$W/panel.crt" /root/panel.crt
vm 'cd /root && tar xzf elk-node.tgz'
START=$(vm 'date +%s')
vm "cd /root && setsid nohup bash -c 'timeout 3000 bash elk-node/sentora2027-elk-node --panel-cert /root/panel.crt --files elk-node < /dev/null > /root/screen.txt 2>&1; echo \$? > /root/install-rc' > /dev/null 2>&1 &"
for _ in $(seq 1 300); do vm 'test -f /root/install-rc' 2> /dev/null && break; sleep 10; done
vm 'cat /root/screen.txt' > "$OUT/install-screen.txt" 2>&1
vm 'cat /var/log/sentora2027-elk-node.log' > "$OUT/install.log" 2>&1
[ "$(vm 'cat /root/install-rc' 2> /dev/null)" = 0 ] && ok "the Elk node installed with SELinux enforcing ($(( ($(vm 'date +%s') - START) / 60 )) minutes)" || { bad "the install did not finish"; tail -30 "$OUT/install-screen.txt"; }
for s in elasticsearch logstash kibana heartbeat-elastic sentora-elk-alerts firewalld; do
    vm "systemctl is-active --quiet $s" && ok "$s runs" || bad "$s does not run: $(vm "journalctl -u $s -n 15 --no-pager" | tail -5)"
done
[ "$(curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:8443/login)" = 200 ] && ok "Kibana answers through the firewall" || bad "Kibana does not answer on 443"
vm 'sentora-elk-node panel-from 10.0.2.2' > /dev/null 2>&1

say "The panel's lines (TLS on 6514, the panel's certificate)"
vm 'cat /etc/sentora-elk/ca.crt' > "$W/node-ca.crt"
python3 -I "$INST/tests/elk/send.py" 127.0.0.1 "$W/node-ca.crt" "$W/panel.crt" "$W/panel.key" "$INST/tests/elk/lines.tsv" > "$OUT/send.txt" 2>&1
n=$(waitcnt 'logs-sentora-*' '{"query":{"bool":{"must_not":[{"term":{"sentora.log":"health"}}]}}}' 100)
[ "${n:-0}" -ge 100 ] && ok "the panel's lines arrived ($n)" || bad "panel lines: $n ($(tail -2 "$OUT/send.txt"))"

say "A FortiGate (6516) and Veeam (6517) through rsyslog over TLS"
vm 'sentora-elk-node fortigate 10.0.2.2' > "$OUT/fortigate-on.txt" 2>&1
vm 'cat /etc/sentora-elk/sources/fortigate.crt' > "$W/fgt.crt"; vm 'cat /etc/sentora-elk/sources/fortigate.key' > "$W/fgt.key"
vm 'sentora-elk-node veeam 10.0.2.2' > "$OUT/veeam-on.txt" 2>&1
for _ in $(seq 1 60); do vm 'ss -ltn | grep -q ":6516 " && ss -ltn | grep -q ":6517 " && ss -ltn | grep -q "127.0.0.1:5516 " && ss -ltn | grep -q "127.0.0.1:5517 "' && break; sleep 3; done
python3 -I "$INST/tests/elk/srcsend.py" 127.0.0.1 6516 "$W/node-ca.crt" "$W/fgt.crt" "$W/fgt.key" "$INST/tests/elk/fortigate.txt" > "$OUT/fgt-send.txt" 2>&1
sed "s/ 2026-11-20T/ $(date -u +%Y-%m-%d)T/" "$INST/tests/elk/veeam.txt" > "$W/veeam.txt"
python3 -I "$INST/tests/elk/srcsend.py" 127.0.0.1 6517 "$W/node-ca.crt" - - "$W/veeam.txt" > "$OUT/veeam-send.txt" 2>&1
n=$(waitcnt 'logs-fortigate-*' '{"query":{"match_all":{}}}' 15); [ "$n" = 15 ] && ok "the FortiGate's 15 lines arrived (rsyslog on 6516, SELinux enforcing)" || bad "FortiGate lines: $n of 15 ($(cat "$OUT/fgt-send.txt"))"
n=$(waitcnt 'logs-veeam-*' '{"query":{"match_all":{}}}' 7); [ "$n" = 7 ] && ok "Veeam's 7 events arrived (rsyslog on 6517)" || bad "Veeam events: $n of 7"
vm "semanage port -l | grep -E '^syslogd_port_t'" | tee "$OUT/ports.txt" | grep -q 6516 && ok "6516/6517 are labelled for rsyslog" || bad "the syslog ports are not labelled: $(cat "$OUT/ports.txt")"

say "A domain controller (Winlogbeat's protocol, Filebeat on the runner) on 6518"
vm 'sentora-elk-node windows 10.0.2.2' > "$OUT/windows-on.txt" 2>&1
vm 'cat /etc/sentora-elk/sources/dc-10.0.2.2.crt' > "$W/dc.crt"; vm 'cat /etc/sentora-elk/sources/dc-10.0.2.2.key' > "$W/dc.key"
mkdir -p "$W/fb"; cp "$W/node-ca.crt" "$W/dc.crt" "$W/dc.key" "$W/fb/"; python3 -I "$INST/tests/elk/winevents.py" > "$W/fb/winevents.ndjson"
printf 'filebeat.inputs:\n  - type: filestream\n    id: dc\n    paths: ["/data/winevents.ndjson"]\n    parsers:\n      - ndjson: {target: "", overwrite_keys: true}\noutput.logstash:\n  hosts: ["127.0.0.1:6518"]\n  ssl.enabled: true\n  ssl.verification_mode: certificate\n  ssl.certificate_authorities: ["/data/node-ca.crt"]\n  ssl.certificate: "/data/dc.crt"\n  ssl.key: "/data/dc.key"\n' > "$W/fb/fb.yml"
chmod 644 "$W/fb"/*
for _ in $(seq 1 60); do vm 'ss -ltn | grep -q ":6518 "' && break; sleep 3; done
docker run -d --name elk-vm-fb --network host --user root -v "$W/fb:/data" docker.elastic.co/beats/filebeat:9.5.6 filebeat -c /data/fb.yml --strict.perms=false --path.data /tmp/fbd > /dev/null
n=$(waitcnt 'logs-windows-*' '{"query":{"match_all":{}}}' 13); [ "$n" = 13 ] && ok "the domain controller's 13 events arrived" || bad "windows events: $n of 13 ($(docker logs elk-vm-fb 2>&1 | grep -i -m2 error))"
docker rm -f elk-vm-fb > /dev/null 2>&1

say "VMware vCenter (the simulator on the runner, read by Metricbeat on the node)"
docker run -d --name elk-vm-vcsim -p 443:443 vmware/vcsim:latest -l 0.0.0.0:443 -cluster 1 -host 3 -vm 6 -ds 2 > /dev/null
for _ in $(seq 1 20); do curl -sk -o /dev/null https://127.0.0.1/sdk && break; sleep 2; done
printf 'yes\npass\n' | vm 'sentora-elk-node vsphere 10.0.2.2 user' > "$OUT/vsphere-on.txt" 2>&1
grep -q "vCenter answers:.*host" "$OUT/vsphere-on.txt" && ok "Metricbeat reads vCenter as its own user, SELinux enforcing" || bad "vsphere: $(tail -4 "$OUT/vsphere-on.txt")"

say "Microsoft 365 (the reader against a stand-in for Microsoft Graph)"
to_vm "$INST/tests/elk/fakegraph.py" /root/fakegraph.py
vm 'cd /root && openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout fg.key -out fg.crt -subj /CN=localhost -addext subjectAltName=DNS:localhost -days 2 2> /dev/null; install -m 644 fg.crt /usr/local/share/fg-test.crt; (setsid python3 -I fakegraph.py 18443 fg.crt fg.key > /dev/null 2>&1 &)'
printf 'test-secret\n' | vm 'sentora-elk-node m365 11111111-2222-3333-4444-555555555555 66666666-7777-8888-9999-000000000000' > "$OUT/m365-on.txt" 2>&1
vm 'systemctl stop sentora-elk-reader; runuser -u sentora-elk-reader -- env SENTORA_TEST_LOGIN=https://localhost:18443 SENTORA_TEST_GRAPH=https://localhost:18443 SENTORA_TEST_CA=/usr/local/share/fg-test.crt /usr/bin/python3 -I /usr/local/lib/sentora-elk-reader/sentora-elk-reader.py check' > "$OUT/m365-check.txt" 2>&1
grep -q "sign-in(s) read" "$OUT/m365-check.txt" && ok "the Microsoft 365 reader runs" || bad "m365: $(tail -3 "$OUT/m365-check.txt")"
vm 'systemctl start sentora-elk-reader'

say "Alerts, uptime, an update"
vm 'sentora-elk-node alerts' > "$OUT/alerts.txt" 2>&1 && grep -q "locked out" "$OUT/alerts.txt" && ok "the alerts service checks every source (a lockout is an alert)" || bad "alerts: $(tail -4 "$OUT/alerts.txt")"
vm 'cd /root && bash elk-node/sentora2027-elk-node --update --files elk-node' > "$OUT/update.txt" 2>&1 && ok "--update finished" || bad "--update: $(tail -4 "$OUT/update.txt")"
for s in elasticsearch logstash kibana sentora-vsphere rsyslog; do vm "systemctl is-active --quiet $s" || bad "$s is not running after the update"; done
END=$(vm 'date +%s')

# what SELinux refused between two moments (seconds since 1970, the machine's clock)
refusals() { # refusals <file> <from> <to>
    vm 'cat /var/log/audit/audit.log.* /var/log/audit/audit.log 2> /dev/null' > "$VMDIR/audit.log"
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
    what = f(r' (?:name|path|dest|src)=("?[^ ]*)', l)
    kinds['%-22s %-16s {%s} %s -> %s (%s)' % (f(r'scontext=\w+:\w+:(\w+)', l), f(r'comm="([^"]*)"', l), f(r'denied\s+\{ ([^}]*) \}', l), what,
          f(r'tcontext=\w+:\w+:(\w+)', l), f(r'tclass=(\w+)', l))] += 1
open(out, 'w').write(''.join(x + '\n' for x in lines))
open(out + '.summary', 'w').write(''.join('%5d  %s\n' % (n, k) for k, n in kinds.most_common()))
EOF_PY
}
say "What SELinux refused from before the install to now (must be nothing)"
refusals "$OUT/denied.txt" "$START" "$END"
if [ -s "$OUT/denied.txt" ]; then head -60 "$OUT/denied.txt.summary"; bad "SELinux refused something the Elk node does (denied.txt.summary)"; else ok "SELinux refused nothing"; fi

say "Hidden refusals (dontaudit shown), the sources once more"
vm 'semodule -DB'; FROM2=$(vm 'date +%s')
python3 -I "$INST/tests/elk/srcsend.py" 127.0.0.1 6516 "$W/node-ca.crt" "$W/fgt.crt" "$W/fgt.key" "$INST/tests/elk/fortigate.txt" > /dev/null 2>&1
python3 -I "$INST/tests/elk/srcsend.py" 127.0.0.1 6517 "$W/node-ca.crt" - - "$W/veeam.txt" > /dev/null 2>&1
python3 -I "$INST/tests/elk/send.py" 127.0.0.1 "$W/node-ca.crt" "$W/panel.crt" "$W/panel.key" "$INST/tests/elk/lines.tsv" > /dev/null 2>&1
vm 'sentora-elk-node alerts > /dev/null 2>&1; systemctl restart sentora-vsphere rsyslog; sleep 90'
END2=$(vm 'date +%s'); vm 'semodule -B'
refusals "$OUT/hidden.txt" "$FROM2" "$END2"
if [ -s "$OUT/hidden.txt" ]; then echo "  (reported, not failed)"; head -40 "$OUT/hidden.txt.summary"; echo "::warning::$(wc -l < "$OUT/hidden.txt") hidden refusal(s) (hidden.txt.summary)"; else echo "  nothing hidden either"; fi

vm 'journalctl -b --no-pager > /root/journal.txt 2>&1; true'; scp "${SSHO[@]}" -P "$SSHP" -q root@127.0.0.1:/root/journal.txt "$OUT/journal.log" 2> /dev/null
echo
echo "ELK VM TEST (AlmaLinux 10, SELinux enforcing): $PASS ok, $FAIL FAIL"
[ "$FAIL" = 0 ]
