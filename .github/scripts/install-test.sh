#!/usr/bin/env bash
# Install Sentora on a fresh system in a container (systemd as PID 1), restart it like a server, and
# run sentora-post-install-check, sentora-smoke-test and ModSecurity through the Web Hardening add-on
# (modsec-test.sh). Exit 0 only when the install worked, the check has no FAIL and both tests passed.
#
#   install-test.sh <system> <core checkout> <installers checkout> <results folder>
#     system: ub26.04 | deb13 | alma10 | rocky10 | fedora45
#
# Used by .github/workflows/install-test.yml (every release waits for it), and on a test machine.
# Needs docker that can run privileged containers with cgroup v2. The files the installer downloads
# are served from this machine (SENTORA_DOWNLOAD_URL): the core and installers zips made from the two
# checkouts, and the system's PHP bundles from the current release (when gh can fetch them).
# On a test machine: TEST_KEEP=1 (keep the container afterwards), TEST_SOGO (a SOGo bundle file to use instead of the release's), CGROUP_DIR (a cgroup2 mount, default /sys/fs/cgroup) and TEST_CA (a certificate
# file the containers must trust, e.g. a proxy's) when that machine needs them.
set -uo pipefail
SYS=${1:?system}; CORE=$(cd "${2:?core checkout}" && pwd); INST=$(cd "${3:?installers checkout}" && pwd); OUT=${4:?results folder}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
case "$SYS" in
    ub26.04) IMAGE=ubuntu:26.04; INSTALLER=sentora2027-ub26.04; TAG=ubuntu26.04
        PREP='RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -yqq systemd systemd-sysv dbus tzdata apparmor && apt-get clean' ;;
    deb13) IMAGE=debian:13; INSTALLER=sentora2027-deb13; TAG=debian13
        PREP='RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -yqq systemd systemd-sysv dbus tzdata apparmor && apt-get clean' ;;
    alma10) IMAGE=quay.io/almalinuxorg/almalinux:10; INSTALLER=sentora2027-alma10; TAG=alma10
        PREP='RUN dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all' ;;
    rocky10) IMAGE=quay.io/rockylinux/rockylinux:10; INSTALLER=sentora2027-alma10; TAG=alma10
        PREP='RUN dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all' ;;
    fedora45) IMAGE=quay.io/fedora/fedora:45; INSTALLER=sentora2027-fedora; TAG=fedora45
        PREP='RUN dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all' ;;
    *) echo "unknown system $SYS (ub26.04, deb13, alma10, rocky10, fedora45)"; exit 2 ;;
esac
NAME=sentora-test-$SYS
PORT=${TEST_PORT:-8088}
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
in_box() { docker exec "$NAME" bash -c "umask 022; $1"; }

say "Files the installer downloads"
DL="$OUT/dl"; rm -rf "$DL"; mkdir -p "$DL"
git -C "$CORE" archive --prefix=sentora-core-2027/ -o "$DL/sentora-core-2027.zip" HEAD
git -C "$INST" archive --prefix=sentora-installers-2027/ -o "$DL/sentora-installers-2027.zip" HEAD
if command -v gh > /dev/null && gh release download current --repo RusTus/sentora-installers-2027 \
        -p "sentora-php-*-$TAG-amd64.tar.gz" -D "$DL" 2> /dev/null; then
    echo "PHP bundles: $(cd "$DL" && ls sentora-php-* 2> /dev/null | tr '\n' ' ')"
else
    echo "PHP bundles: none (the installer warns and goes on)"
fi
# SOGo, for the systems that get it from Sentora's own build (AlmaLinux)
if [ "$SYS" = alma10 ] || [ "$SYS" = rocky10 ] || [ "$SYS" = fedora45 ]; then
    if [ -n "${TEST_SOGO:-}" ] && [ -f "$TEST_SOGO" ]; then cp "$TEST_SOGO" "$DL/sentora-sogo-$TAG-amd64.tar.gz"
    elif command -v gh > /dev/null; then gh release download current --repo RusTus/sentora-installers-2027 -p "sentora-sogo-$TAG-amd64.tar.gz" -D "$DL" 2> /dev/null || true; fi
    ls "$DL"/sentora-sogo-* > /dev/null 2>&1 && echo "SOGo bundle: $(cd "$DL" && ls sentora-sogo-*)" || echo "SOGo bundle: none (SOGo is skipped)"
fi
ls -la "$DL"

say "Serving them on port $PORT"
GW=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')
( cd "$DL" && exec python3 -m http.server "$PORT" --bind "$GW" > "$OUT/http.log" 2>&1 ) &
HTTP=$!
trap 'kill $HTTP 2> /dev/null; docker logs "$NAME" > "$OUT/container.log" 2>&1; [ -n "${TEST_KEEP:-}" ] || docker rm -f "$NAME" > /dev/null 2>&1' EXIT
sleep 1

say "A fresh $IMAGE with systemd"
CA=''
if [ -n "${TEST_CA:-}" ]; then
    cp "$TEST_CA" "$OUT/test-ca.crt"
    if [ "$SYS" = alma10 ] || [ "$SYS" = rocky10 ] || [ "$SYS" = fedora45 ]; then CA='COPY test-ca.crt /etc/pki/ca-trust/source/anchors/test-ca.crt
RUN update-ca-trust'
    else CA='COPY test-ca.crt /usr/local/share/ca-certificates/test-ca.crt
RUN apt-get update -qq && apt-get install -yqq ca-certificates > /dev/null && update-ca-certificates > /dev/null'; fi
fi
printf 'FROM %s\n%s\n%s\nSTOPSIGNAL SIGRTMIN+3\nCMD ["/sbin/init"]\n' "$IMAGE" "$CA" "$PREP" > "$OUT/Dockerfile"
built=0
for try in 1 2 3; do   # (a mirror can fail for a moment)
    if docker build --progress=plain -t "sentora-test:$SYS" -f "$OUT/Dockerfile" "$OUT" > "$OUT/image-build.log" 2>&1; then built=1; break; fi
    echo "the image did not build (try $try):"; tail -15 "$OUT/image-build.log"; sleep $(( try * 20 ))
done
[ $built -eq 1 ] || { echo "::error::the $SYS image did not build"; exit 1; }
docker rm -f "$NAME" > /dev/null 2>&1
docker run -d --name "$NAME" --privileged --cgroupns=host -v "${CGROUP_DIR:-/sys/fs/cgroup}":/sys/fs/cgroup:rw \
    --tmpfs /run --tmpfs /run/lock -h panel.example.test "sentora-test:$SYS" > /dev/null
for _ in $(seq 1 60); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
in_box 'systemctl is-system-running' || true

say "Installing ($INSTALLER)"
sed "s|^SENTORA_DOWNLOAD_URL=\"\"|SENTORA_DOWNLOAD_URL=\"http://$GW:$PORT\"|" "$INST/$INSTALLER" > "$OUT/$INSTALLER"
grep -q "^SENTORA_DOWNLOAD_URL=\"http://$GW:$PORT\"" "$OUT/$INSTALLER" || { echo "::error::could not point $INSTALLER at the test files"; exit 1; }
docker cp "$OUT/$INSTALLER" "$NAME:/root/$INSTALLER"
START=$(date +%s)
in_box "cd /root && ADMIN_NAME='Test Admin' ADMIN_EMAIL=admin@example.com ADMIN_PHONE=555 ADMIN_ADDRESS='1 Main St' \
    ADMIN_PROVINCE=TX ADMIN_POSTALCODE=75001 ADMIN_COUNTRY=US \
    timeout 3600 bash $INSTALLER -t UTC -d panel.example.test -i local < /dev/null > /root/screen.txt 2>&1"
RC=$?
docker cp "$NAME:/root/screen.txt" "$OUT/install-screen.txt" > /dev/null 2>&1
in_box 'cat /root/*_sentora_install.log' > "$OUT/install.log" 2>&1
echo "the installer ended with $RC after $(( ($(date +%s) - START) / 60 )) minutes"
if ! grep -q "Sentora is installed" "$OUT/install-screen.txt"; then
    tail -40 "$OUT/install-screen.txt"
    echo "::error::$SYS: the installation did not finish (install-screen.txt and install.log in the results)"
    exit 1
fi
grep -E '^\s+\[ !! \]|WARNING' "$OUT/install-screen.txt" | head -20 || true

# AppArmor (Debian, Ubuntu): a real server loads the system's profiles at boot (its apparmor service);
# in a container that service does nothing, so the container's own profiles (with Sentora's local
# additions, as the installer left them) are loaded into this machine's kernel here. They confine the
# same programs inside the container, from the restart below on; every refusal is a failure.
AA=0
if in_box 'test -d /etc/apparmor.d && command -v apparmor_parser' > /dev/null 2>&1 && [ -d /sys/kernel/security/apparmor ]; then
    say "AppArmor: loading the system's profiles (enforce mode, as on a real server)"
    in_box 'mountpoint -q /sys/kernel/security || mount -t securityfs securityfs /sys/kernel/security
        cd /etc/apparmor.d && for f in *; do
            [ -f "$f" ] && [ ! -e "disable/$f" ] || continue
            apparmor_parser --config-file /dev/null -r -K "$f" 2>&1 | grep -v "^Warning\|^Caching" | sed "s/^/  $f: /"
        done' 2>&1 | tee "$OUT/apparmor-load.txt"
    in_box 'dmesg -C' > /dev/null 2>&1 || sudo -n dmesg -C > /dev/null 2>&1 || true
    AA=1
fi

say "Restarting the server (services must come back by themselves)"
docker restart "$NAME" > /dev/null
for _ in $(seq 1 90); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
# (the check reads which profiles are loaded from securityfs, which a container has to mount)
[ $AA -eq 1 ] && in_box 'mountpoint -q /sys/kernel/security || mount -t securityfs securityfs /sys/kernel/security'
in_box 'systemctl --failed --no-legend' | tee "$OUT/failed-units.txt"
# why each one failed (its own log since the restart)
for u in $(awk '{for (i = 1; i <= NF; i++) if ($i ~ /\.service$/) {print $i; break}}' "$OUT/failed-units.txt"); do
    echo "--- $u"; in_box "systemctl status $u --no-pager -l 2>&1 | tail -25; journalctl -u $u -b --no-pager 2>&1 | tail -25"
done | tee "$OUT/failed-units-why.txt"
# the database first, then one daemon run (as cron would, 5 minutes after the start)
for _ in $(seq 1 60); do in_box 'mysqladmin ping > /dev/null 2>&1 || mariadb-admin ping > /dev/null 2>&1' && break; sleep 2; done
# (as cron runs it, so its report is the one the check reads: the restart may have cut a run short)
in_box 'php -q /etc/sentora/panel/bin/daemon.php > /var/sentora/logs/daemon-last-run.log 2>&1'

say "sentora-post-install-check"
in_box 'sentora-post-install-check' > "$OUT/post-install-check.txt" 2>&1
grep -E '^\s+(FAIL|WARN)|^Result' "$OUT/post-install-check.txt"
FAILS=$(grep -oE 'Result: .* ([0-9]+) FAIL' "$OUT/post-install-check.txt" | grep -oE '[0-9]+ FAIL' | grep -oE '[0-9]+')
# the log of each service the check found stopped
for u in $(grep -E '^\s+FAIL' "$OUT/post-install-check.txt" | grep -oE 'systemctl status [A-Za-z0-9@._-]+' | awk '{print $3}' | sort -u); do
    echo "--- $u"; in_box "systemctl status $u --no-pager -l 2>&1 | tail -20; journalctl -u $u -b --no-pager 2>&1 | tail -30"
done | tee "$OUT/stopped-services-why.txt"

say "sentora-smoke-test"
# (--keep: its test client and domain stay, for the ModSecurity test below to attack a real site)
in_box 'sentora-smoke-test --keep' > "$OUT/smoke-test.txt" 2>&1
grep -E '^\s+\[|RESULT' "$OUT/smoke-test.txt" || { echo "(the smoke test stopped early; its last lines:)"; tail -20 "$OUT/smoke-test.txt"; }
in_box 'cat /var/sentora/logs/smoke-test.log' > "$OUT/smoke-test.log" 2>&1

say "Apache's status page (Server Admin > Apache Status reads it from this server)"
in_box 'code=$(curl -s -o /tmp/st -w "%{http_code}" http://127.0.0.1/server-status?auto); echo "  http://127.0.0.1/server-status?auto: HTTP $code"
    if [ "$code" = 200 ] && grep -q "^ServerUptime" /tmp/st; then echo "STATUS: PASSED"; else head -5 /tmp/st; echo "STATUS: FAILED"; fi' > "$OUT/apache-status.txt" 2>&1
cat "$OUT/apache-status.txt"

say "Root actions from the panel (zsudo's queue, as the panel's user)"
in_box 'P=/etc/sentora/panel/bin/zsudo; ls -l $P | cut -d" " -f1,3,4
    if [ -u $P ]; then echo "ZSUDO: FAILED (still set-uid)"; exit 0; fi
    runuser -u sentora -- $P service apache2 "reload;id" > /dev/null 2>&1; rc=$?
    [ $rc -eq 2 ] && echo "  a forbidden action: refused (2)" || { echo "ZSUDO: FAILED (a forbidden action got $rc)"; exit 0; }
    rm -f /var/sentora/logs/daemon-panel-run.log
    runuser -u sentora -- $P daemon-run || { echo "ZSUDO: FAILED (could not queue: $?)"; exit 0; }
    for i in $(seq 1 60); do [ -s /var/sentora/logs/daemon-panel-run.log ] && break; sleep 2; done
    if [ -s /var/sentora/logs/daemon-panel-run.log ]; then echo "  daemon-run queued by the panel'"'"'s user, run by root within $((i * 2)) s"; echo "ZSUDO: PASSED"
    else echo "ZSUDO: FAILED (the queued daemon-run did not run)"; systemctl status sentora-zsudo.path sentora-zsudo.service --no-pager 2>&1 | tail -20; ls -la /var/lib/sentora/zsudo/queue; fi' > "$OUT/zsudo-test.txt" 2>&1
cat "$OUT/zsudo-test.txt"

say "Problems log (errors from the logs the panel can not read; never a password)"
in_box 'echo "$(date "+%b %d %H:%M:%S") imap(test@example.test): Error: sentora-install-test problem line" >> /var/log/dovecot.log
    echo "$(date "+%b %d %H:%M:%S") auth: Error: sentora-install-test password=Secret123 must never be copied" >> /var/log/dovecot.log
    php -q /etc/sentora/panel/bin/daemon.php > /var/sentora/logs/daemon-last-run.log 2>&1
    P=/var/sentora/logs/problems.log; ls -l $P | cut -d" " -f1,3,4
    ok=1
    grep -q "sentora-install-test problem line" $P && echo "  the test error is in it" || { echo "  the test error is NOT in it"; ok=0; }
    grep -q "Secret123" $P && { echo "  a password line was copied"; ok=0; } || echo "  the password line was not copied"
    [ "$(stat -c "%a %U %G" $P)" = "640 root sentora" ] || { echo "  its owner or mode is wrong"; ok=0; }
    grep -q "ran daemon-run" /var/sentora/logs/zsudo.log && echo "  zsudo.log records the queued daemon-run" || { echo "  zsudo.log has no record"; ok=0; }
    [ $ok -eq 1 ] && echo "PROBLEMS: PASSED" || { tail -5 $P; echo "PROBLEMS: FAILED"; }' > "$OUT/problems-log.txt" 2>&1
cat "$OUT/problems-log.txt"

say "This system's settings for servers installed before a setting existed (sentora-platform-fill)"
( cd "$INST" && bash .github/scripts/platform-settings.sh "$OUT/platform-settings.json" ) > /dev/null
docker cp "$OUT/platform-settings.json" "$NAME:/root/platform-settings.json"
in_box 'F=/etc/sentora/panel/bin/sentora-platform-fill; J=/root/platform-settings.json
    out=$(php -q $F --dry-run $J); echo "  fresh install: $out"
    echo "$out" | grep -q "all as its installer sets them" || { echo "PLATFORM: FAILED (the file and the installer differ)"; exit 0; }
    # as on a server installed before: each of this system'"'"'s own values back to the default
    n=$(cd /etc/sentora/panel && php -r '"'"'require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php";
        $j = json_decode(file_get_contents("/root/platform-settings.json"), true)["systems"][ctrl_options::GetSystemOption("php_bundle_tag")];
        $n = 0; foreach ($j as $k => $v) { if (isset(sys_platform::$settings[$k]) && sys_platform::$settings[$k][0] !== $v && $k !== "php_bundle_tag") {
            ctrl_options::SetSystemOption($k, sys_platform::$settings[$k][0], false); $n++; } } echo $n;'"'"')
    echo "  $n setting(s) put back to the default"
    php -q $F $J | tail -1 | sed "s/^/  /"
    out=$(php -q $F --dry-run $J)
    echo "$out" | grep -q "all as its installer sets them" && echo "PLATFORM: PASSED" || { echo "$out"; echo "PLATFORM: FAILED"; }' > "$OUT/platform-settings.txt" 2>&1
cat "$OUT/platform-settings.txt"

say "Hardening score (Server Monitor's card: the check queued by the panel's user, run by root)"
in_box 'H=/var/sentora/logs/hardening.json; rm -f $H
    runuser -u sentora -- /etc/sentora/panel/bin/zsudo hardening-check || { echo "HARDENING: FAILED (could not queue: $?)"; exit 0; }
    for i in $(seq 1 150); do [ -s $H ] && break; sleep 2; done
    [ -s $H ] || { echo "HARDENING: FAILED (no report after 300 s)"; tail -5 /var/sentora/logs/zsudo.log; exit 0; }
    echo "  report written within $((i * 2)) s: $(stat -c "%a %U %G" $H)"
    php -r '"'"'$j = json_decode(file_get_contents("/var/sentora/logs/hardening.json"), true);
        if (!is_array($j) || !isset($j["score"], $j["checks"])) { echo "HARDENING: FAILED (not a report)\n"; exit; }
        $by = array(); foreach ($j["checks"] as $c) { if ($c["status"] !== "ok") { $by[] = "  " . strtoupper($c["status"]) . " [" . $c["section"] . "] " . $c["text"]; } }
        echo "  score " . $j["score"] . " / 100 from " . count($j["checks"]) . " checks\n" . implode("\n", $by) . "\n";
        $h = json_decode(@file_get_contents("/var/sentora/logs/hardening-history.json"), true);
        echo is_array($h) && count($h) > 0 ? "  history: " . count($h) . " result(s)\n" : "  no history\n";
        if ($j["score"] > 0 && is_array($h) && count($h) > 0) { echo "HARDENING: PASSED\n"; } else { echo "HARDENING: FAILED\n"; }'"'"'
    [ "$(stat -c "%a %U %G" $H)" = "640 root sentora" ] || echo "HARDENING: FAILED (owner or mode)"' > "$OUT/hardening.txt" 2>&1
cat "$OUT/hardening.txt"

say "ModSecurity through the Web Hardening add-on (on, attacks, watch only, off)"
docker cp "$INST/sentora-repo/php_hardening.zpp" "$NAME:/root/php_hardening.zpp"
docker cp "$INST/.github/scripts/modsec-test.sh" "$NAME:/root/modsec-test.sh"
in_box 'bash /root/modsec-test.sh' > "$OUT/modsec-test.txt" 2>&1
cat "$OUT/modsec-test.txt"
in_box "tail -60 \"\$(php -q /etc/sentora/panel/bin/setso --get apache_log_dir)/modsec_audit.log\"" > "$OUT/modsec-audit.log" 2>&1

if [ $AA -eq 1 ]; then
    say "AppArmor: which programs ran confined, and what it refused"
    in_box 'for p in named mysqld mariadbd proftpd clamd freshclam; do
        for pid in $(pidof $p); do printf "  %-10s %s\n" "$p" "$(cat /proc/$pid/attr/current 2> /dev/null)"; break; done; done' | tee "$OUT/apparmor-confined.txt"
    { in_box 'dmesg' 2> /dev/null || sudo -n dmesg; } | grep 'apparmor="DENIED"' > "$OUT/apparmor-denied.txt" || true
    if [ -s "$OUT/apparmor-denied.txt" ]; then
        awk '{o="";n=split("operation profile name requested_mask capname",k," ");for(i=1;i<=n;i++)if(match($0,k[i]"=\"[^\"]*\""))o=o" "substr($0,RSTART,RLENGTH);print o}' "$OUT/apparmor-denied.txt" | sort | uniq -c | sort -rn | head -40
    else
        echo "  nothing refused"
    fi
fi

in_box "cat /var/sentora/logs/daemon-last-run.log" > "$OUT/daemon-last-run.log" 2>&1

say "Result for $SYS"
ok=1
if [ "${FAILS:-x}" != "0" ]; then echo "::error::$SYS: the post-install check has ${FAILS:-an unreadable number of} FAIL line(s)"; ok=0; fi
if ! grep -q 'RESULT: PASSED' "$OUT/smoke-test.txt"; then echo "::error::$SYS: the smoke test did not pass"; ok=0; fi
if ! grep -q 'STATUS: PASSED' "$OUT/apache-status.txt"; then echo "::error::$SYS: Apache's status page does not answer on 127.0.0.1 (apache-status.txt)"; ok=0; fi
if ! grep -q 'PROBLEMS: PASSED' "$OUT/problems-log.txt"; then echo "::error::$SYS: the problems log did not work as it should (problems-log.txt)"; ok=0; fi
if ! grep -q 'PLATFORM: PASSED' "$OUT/platform-settings.txt"; then echo "::error::$SYS: this system's settings were not filled in as its installer sets them (platform-settings.txt)"; ok=0; fi
if ! grep -q 'HARDENING: PASSED' "$OUT/hardening.txt" || grep -q 'HARDENING: FAILED' "$OUT/hardening.txt"; then echo "::error::$SYS: the hardening report was not written as it should (hardening.txt)"; ok=0; fi
if ! grep -q 'ZSUDO: PASSED' "$OUT/zsudo-test.txt"; then echo "::error::$SYS: the panel's root actions (zsudo's queue) did not work (zsudo-test.txt)"; ok=0; fi
if ! grep -q 'MODSEC: PASSED' "$OUT/modsec-test.txt"; then echo "::error::$SYS: ModSecurity through the Web Hardening add-on did not pass (modsec-test.txt)"; ok=0; fi
if [ $AA -eq 1 ] && [ -s "$OUT/apparmor-denied.txt" ]; then echo "::error::$SYS: AppArmor refused something Sentora does (apparmor-denied.txt)"; ok=0; fi
[ $ok -eq 1 ] && echo "$SYS: installed, post-install check without FAIL, smoke test passed, ModSecurity works"
[ $ok -eq 1 ]
