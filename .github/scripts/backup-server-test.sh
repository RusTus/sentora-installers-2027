#!/bin/bash
# Backup server test: a fresh system in a container with systemd is made a Sentora backup server with
# backup/sentora2027-backup-server, then used from outside over real SSH and rsync as a panel would:
# its own folder only, nights linked to the one before, nothing else allowed (no shell, no password,
# no way out of its folder), a second panel kept apart, a removed panel locked out.
#   .github/scripts/backup-server-test.sh <results folder> [alma10|rocky10|fedora45|debian13|ubuntu26.04]
set -uo pipefail
OUT=${1:?results folder}; SYS=${2:-debian13}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
REPO=$(cd "$(dirname "$0")/../.." && pwd)
case "$SYS" in
    alma10) IMAGE=quay.io/almalinuxorg/almalinux:10; PKGS=dnf ;;
    rocky10) IMAGE=quay.io/rockylinux/rockylinux:10; PKGS=dnf ;;
    fedora45) IMAGE=quay.io/fedora/fedora:45; PKGS=dnf ;;
    debian13) IMAGE=docker.io/library/debian:13; PKGS=apt ;;
    ubuntu26.04) IMAGE=docker.io/library/ubuntu:26.04; PKGS=apt ;;
    *) echo "unknown system $SYS"; exit 2 ;;
esac
NAME=sentora-backup-test
FAILS=0
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
ok() { echo "  ok   $1"; }
bad() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); echo "::error::$1"; }
in_box() { docker exec "$NAME" bash -c "$1"; }
W=$(mktemp -d)
cleanup() {
    docker exec "$NAME" cat /var/log/sentora2027-backup-server.log > "$OUT/install.log" 2>&1
    docker exec "$NAME" cat /var/log/sentora-backup-gate.log > "$OUT/gate.log" 2>&1
    docker exec "$NAME" journalctl -u ssh -u sshd --no-pager > "$OUT/sshd.log" 2>&1
    [ -n "${TEST_KEEP:-}" ] || { docker rm -f "$NAME" > /dev/null 2>&1; rm -rf "$W"; }
}
trap cleanup EXIT

say "A fresh $IMAGE with systemd"
if [ "$PKGS" = apt ]; then
    RUNLINE='apt-get update && DEBIAN_FRONTEND=noninteractive apt-get -y install systemd systemd-sysv dbus procps hostname iproute2 tzdata curl ca-certificates && rm -rf /var/lib/apt/lists/*'
else
    RUNLINE='dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all'
fi
printf 'FROM %s\nRUN %s\nSTOPSIGNAL SIGRTMIN+3\nCMD ["/sbin/init"]\n' "$IMAGE" "$RUNLINE" > "$W/Dockerfile"
docker build -q -t sentora-backup-test "$W" > "$OUT/image-build.log" 2>&1 || { tail -20 "$OUT/image-build.log"; echo "::error::image did not build"; exit 1; }
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock -h backup1.example.test sentora-backup-test > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$NAME")

say "Installing the backup server"
ssh-keygen -q -t ed25519 -N "" -C "sentora-offsite@panel1.example.test" -f "$W/k1"
ssh-keygen -q -t ed25519 -N "" -C "sentora-offsite@panel2.example.test" -f "$W/k2"
docker cp "$REPO/backup/sentora2027-backup-server" "$NAME:/root/sentora2027-backup-server"
docker cp "$REPO/backup/sentora-backup-gate" "$NAME:/root/sentora-backup-gate"
START=$(date +%s)
in_box "cd /root && bash sentora2027-backup-server --key '$(cat "$W/k1.pub")' --gate /root/sentora-backup-gate" 2>&1 | tee "$OUT/install-screen.txt"
echo "installed in $(( $(date +%s) - START )) seconds"
grep -q '^Done: this server is ready to keep the backups of "panel1.example.test"' "$OUT/install-screen.txt" && ok "installer finished (the panel's name from its key)" || { bad "installer did not finish"; in_box "tail -30 /var/log/sentora2027-backup-server.log"; exit 1; }
FP=$(grep -oE 'SHA256:[A-Za-z0-9+/]{43}' "$OUT/install-screen.txt" | head -1)
ssh-keyscan -t ed25519 "$IP" 2> /dev/null > "$W/known_hosts"
[ -n "$FP" ] && ssh-keygen -lf "$W/known_hosts" | grep -qF "$FP" && ok "the fingerprint it shows is its real SSH key ($FP)" || bad "fingerprint shown ($FP) is not the server's"
SSH1=(ssh -i "$W/k1" -o BatchMode=yes -o IdentitiesOnly=yes -o UserKnownHostsFile="$W/known_hosts" -o StrictHostKeyChecking=yes)
SSH2=(ssh -i "$W/k2" -o BatchMode=yes -o IdentitiesOnly=yes -o UserKnownHostsFile="$W/known_hosts" -o StrictHostKeyChecking=yes)
B=sentora-backup@$IP

say "What a panel's key may do"
[ "$("${SSH1[@]}" "$B" sentora-backup hello 2>&1)" = "sentora-backup 1 panel1.example.test" ] && ok "it says it is a Sentora backup server, for this panel's folder" || bad "hello: $("${SSH1[@]}" "$B" sentora-backup hello 2>&1)"
[ "$("${SSH1[@]}" "$B" sentora-backup ready /panel1.example.test 2>&1)" = ready ] && ok "ready: its folder made and writable" || bad "ready: $("${SSH1[@]}" "$B" sentora-backup ready /panel1.example.test 2>&1)"
mkdir -p "$W/src/var/sentora/hostdata/acc1/public_html" "$W/src/databases"
echo "night one" > "$W/src/var/sentora/hostdata/acc1/public_html/index.html"; echo '{"format":2}' > "$W/src/manifest.json"; head -c 200000 /dev/urandom > "$W/src/databases/big.sql.gz"
H=/panel1.example.test
for night in 2026-11-10 2026-11-11; do
    "${SSH1[@]}" "$B" sentora-backup begin $H $night
    LINK=(); [ "$("${SSH1[@]}" "$B" sentora-backup latest $H 2> /dev/null)" = yes ] && LINK=(--link-dest=$H/latest/)
    rsync -aHR --delete --numeric-ids --partial "${LINK[@]}" -e "${SSH1[*]}" "$W/src/./" "$B:$H/$night.partial/" > "$W/rsync.out" 2>&1 || { bad "rsync to the backup server ($night): $(tail -2 "$W/rsync.out")"; }
    "${SSH1[@]}" "$B" sentora-backup finish $H $night 14 || bad "finish $night"
done
[ "$("${SSH1[@]}" "$B" sentora-backup count $H 2>&1)" = 2 ] && ok "two nights kept" || bad "count: $("${SSH1[@]}" "$B" sentora-backup count $H 2>&1)"
in_box "[ \$(stat -c %i /srv/sentora-backups/panel1.example.test$H/2026-11-10/databases/big.sql.gz) = \$(stat -c %i /srv/sentora-backups/panel1.example.test$H/2026-11-11/databases/big.sql.gz) ]" \
    && ok "the second night links to the first (an unchanged file is stored once)" || bad "no hard links between nights"
rm -rf "$W/back"; rsync -a -e "${SSH1[*]}" "$B:$H/latest/var/" "$W/back/" > /dev/null 2>&1
[ "$(cat "$W/back/sentora/hostdata/acc1/public_html/index.html" 2> /dev/null)" = "night one" ] && ok "read back (as a restore does)" || bad "could not read the copy back"
"${SSH1[@]}" "$B" sentora-backup list 2>&1 | grep -q '^@@SNAPSHOT panel1.example.test/2026-11-11$' && ok "list: its nights with their manifest" || bad "list: $("${SSH1[@]}" "$B" sentora-backup list 2>&1 | head -3)"
for n in 03 04 05; do "${SSH1[@]}" "$B" sentora-backup begin $H 2026-11-$n > /dev/null 2>&1; "${SSH1[@]}" "$B" sentora-backup finish $H 2026-11-$n 3 > /dev/null 2>&1; done
[ "$("${SSH1[@]}" "$B" sentora-backup count $H 2>&1)" = 3 ] && ok "older nights removed (3 kept)" || bad "keep 3: $("${SSH1[@]}" "$B" sentora-backup count $H 2>&1)"

say "What it may not do"
o=$("${SSH1[@]}" "$B" id 2>&1); [[ "$o" == *refused* ]] && [[ "$o" != *uid=* ]] && ok "no other commands (id: refused)" || bad "id: $o"
o=$("${SSH1[@]}" -tt "$B" 2>&1 < /dev/null); [[ "$o" != *'$ '* ]] && ok "no shell" || bad "a shell: $o"
o=$("${SSH1[@]}" "$B" 'sentora-backup ready /x; cat /etc/shadow' 2>&1); [[ "$o" == *refused* ]] && [[ "$o" != *root:* ]] && ok "no command chains" || bad "chain: $o"
o=$("${SSH1[@]}" "$B" sentora-backup ready /../../etc 2>&1); [[ "$o" == *refused* ]] && ok "no way out of its folder (..)" || bad "..: $o"
o=$(rsync -a -e "${SSH1[*]}" "$B:/../../../etc/passwd" "$W/p" 2>&1); [ ! -f "$W/p" ] && ok "rsync can not read outside its folder" || bad "rsync read /etc/passwd"
ln -s /etc "$W/evil"; rsync -a -e "${SSH1[*]}" "$W/evil" "$B:$H/" > /dev/null 2>&1
o=$(rsync -a -e "${SSH1[*]}" "$B:$H/evil/passwd" "$W/p2" 2>&1); [ ! -f "$W/p2" ] && ok "nor through a link it uploaded" || bad "read through a link"
o=$(ssh -o BatchMode=yes -o PubkeyAuthentication=no -o PreferredAuthentications=password -o UserKnownHostsFile="$W/known_hosts" "$B" true 2>&1); [[ "$o" == *denied* ]] && ok "no password logins" || bad "password: $o"
in_box 'sshd -t' && ok "SSH's settings are valid" || bad "sshd -t failed"
in_box 'stat -c "%U %a" /etc/ssh/sentora-backup/authorized_keys' | grep -qx 'root 644' && ok "the keys file belongs to root (the backup user can not add keys)" || bad "keys file: $(in_box 'stat -c "%U %a" /etc/ssh/sentora-backup/authorized_keys')"
in_box 'stat -c "%U %a" /srv/sentora-backups' | grep -qx 'sentora-backup 700' && ok "the copies' folder: the backup user's only" || bad "folder: $(in_box 'stat -c "%U %a" /srv/sentora-backups')"

if [ -n "${CORE:-}" ] && [ -f "$CORE/bin/sentora-backup-common" ]; then
    say "The panel's side (the core's sentora-backup-common)"
    printf 'OB_ENABLED=true\nOB_SERVER=%s\nOB_PORT=22\nOB_USER=sentora-backup\nOB_PATH=/\nOB_KEEP=3\nOB_HOSTKEY=%s\n' "$IP" "$FP" > "$W/settings"
    PANELSIDE='. "$CORE/bin/sentora-backup-common"
        BASE="$(rpath)/panel9"; BASE=${BASE//\/\//\/}
        pin_hostkey || exit 9
        is_gate && echo gate
        there_ready "$BASE"
        there_begin "$BASE" 2026-11-12 && echo begun
        rsync -aHR --numeric-ids -e "$SSH" "$SRC/./manifest.json" "$(rhost):$BASE/2026-11-12.partial/" && echo copied
        there_finish "$BASE" 2026-11-12 3 && echo finished
        there_latest "$BASE" && echo latest
        echo "count $(there_count "$BASE")"'
    o=$(CORE="$CORE" SRC="$W/src" SENTORA_OFFSITE_SETTINGS="$W/settings" SENTORA_OFFSITE_KEY="$W/k1" SENTORA_OFFSITE_KNOWN="$W/panel_known" bash -c "$PANELSIDE" 2>&1)
    echo "$o" | sed 's/^/    /'
    for w in gate ready begun copied finished latest "count 1"; do echo "$o" | grep -qx "$w" || bad "the panel's side: no '$w'"; done
    echo "$o" | grep -qx "count 1" && ok "the panel's own steps work through the gate (its key checked)"
    grep -q "$IP" "$W/panel_known" && ok "the backup server's key is kept once it matched" || bad "no key kept"
    printf 'OB_SERVER=%s\nOB_PORT=22\nOB_USER=sentora-backup\nOB_PATH=/\nOB_HOSTKEY=SHA256:%s\n' "$IP" "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" > "$W/settings2"
    o=$(CORE="$CORE" SENTORA_OFFSITE_SETTINGS="$W/settings2" SENTORA_OFFSITE_KEY="$W/k1" SENTORA_OFFSITE_KNOWN="$W/panel_known2" bash -c '. "$CORE/bin/sentora-backup-common"; pin_hostkey; echo "rc=$?"' 2>&1)
    echo "$o" | grep -q "rc=1" && echo "$o" | grep -q "not the one set in the panel" && ok "another key than the panel's fingerprint: refused" || bad "wrong fingerprint: $o"
fi

say "A second panel"
"${SSH2[@]}" "$B" sentora-backup hello > /dev/null 2>&1 && bad "an unknown key got in" || ok "a key not added: refused"
in_box "sentora-backup-server add --key '$(cat "$W/k2.pub")'" > "$W/add.txt" 2>&1
grep -q 'ready to keep the backups of "panel2.example.test"' "$W/add.txt" && ok "added (sentora-backup-server add)" || bad "add: $(tail -3 "$W/add.txt")"
[ "$("${SSH2[@]}" "$B" sentora-backup hello 2>&1)" = "sentora-backup 1 panel2.example.test" ] && ok "it has its own folder" || bad "panel2 hello"
[ "$("${SSH2[@]}" "$B" sentora-backup count $H 2>&1)" = 0 ] && ok "it does not see the first panel's nights" || bad "panel2 sees panel1's nights"
o=$(rsync -a -e "${SSH2[*]}" "$B:/../panel1.example.test/" "$W/p3" 2>&1); [ ! -d "$W/p3/panel1.example.test" ] && [ ! -f "$W/p3/2026-11-05" ] && ok "nor can it reach them" || bad "panel2 reached panel1's folder"
in_box 'sentora-backup-server list' | grep -q '^panel1.example.test' && ok "sentora-backup-server list shows the panels" || bad "list: $(in_box 'sentora-backup-server list')"
in_box 'sentora-backup-server remove panel2.example.test' > /dev/null
"${SSH2[@]}" "$B" sentora-backup hello > /dev/null 2>&1 && bad "a removed panel still gets in" || ok "a removed panel is locked out"
[ "$("${SSH1[@]}" "$B" sentora-backup count $H 2>&1)" = 3 ] && ok "the other panel is not touched" || bad "panel1 after removing panel2"

say "Updating"
in_box 'bash /usr/local/lib/sentora-backup/installer --update --gate /root/sentora-backup-gate' > "$W/upd.txt" 2>&1
grep -q '^Done: this backup server is up to date' "$W/upd.txt" && ok "--update" || bad "update: $(tail -3 "$W/upd.txt")"
[ "$("${SSH1[@]}" "$B" sentora-backup count $H 2>&1)" = 3 ] && ok "keys and copies kept" || bad "after the update"
in_box 'systemctl is-active firewalld || ufw status' | grep -qE '^active|Status: active' && ok "the firewall is on" || bad "no firewall"

echo
[ $FAILS -eq 0 ] && echo "BACKUP SERVER TEST: all passed" || { echo "BACKUP SERVER TEST: $FAILS FAILED"; exit 1; }
