#!/usr/bin/env bash
# Install Sentora on a fresh system in a container (systemd as PID 1), restart it like a server, and
# run sentora-post-install-check, sentora-smoke-test and ModSecurity through the Web Hardening add-on
# (modsec-test.sh). Exit 0 only when the install worked, the check has no FAIL and both tests passed.
#
#   install-test.sh <system> <core checkout> <installers checkout> <results folder>
#     system: ub26.04 | deb13 | alma10 | fedora45
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
        PREP='RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -yqq systemd systemd-sysv dbus tzdata && apt-get clean' ;;
    deb13) IMAGE=debian:13; INSTALLER=sentora2027-deb13; TAG=debian13
        PREP='RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -yqq systemd systemd-sysv dbus tzdata && apt-get clean' ;;
    alma10) IMAGE=quay.io/almalinuxorg/almalinux:10; INSTALLER=sentora2027-alma10; TAG=alma10
        PREP='RUN dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all' ;;
    fedora45) IMAGE=quay.io/fedora/fedora:45; INSTALLER=sentora2027-fedora; TAG=fedora45
        PREP='RUN dnf -y install systemd procps-ng hostname iproute tzdata && dnf clean all' ;;
    *) echo "unknown system $SYS (ub26.04, deb13, alma10, fedora45)"; exit 2 ;;
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
if [ "$SYS" = alma10 ] || [ "$SYS" = fedora45 ]; then
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
    if [ "$SYS" = alma10 ] || [ "$SYS" = fedora45 ]; then CA='COPY test-ca.crt /etc/pki/ca-trust/source/anchors/test-ca.crt
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

say "Restarting the server (services must come back by themselves)"
docker restart "$NAME" > /dev/null
for _ in $(seq 1 90); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
in_box 'systemctl --failed --no-legend' | tee "$OUT/failed-units.txt"
# why each one failed (its own log since the restart)
for u in $(awk '{for (i = 1; i <= NF; i++) if ($i ~ /\.service$/) {print $i; break}}' "$OUT/failed-units.txt"); do
    echo "--- $u"; in_box "systemctl status $u --no-pager -l 2>&1 | tail -25; journalctl -u $u -b --no-pager 2>&1 | tail -25"
done | tee "$OUT/failed-units-why.txt"
# the database first, then one daemon run (as cron would, 5 minutes after the start)
for _ in $(seq 1 60); do in_box 'mysqladmin ping > /dev/null 2>&1 || mariadb-admin ping > /dev/null 2>&1' && break; sleep 2; done
in_box 'php /etc/sentora/panel/bin/daemon.php > /dev/null 2>&1'

say "sentora-post-install-check"
in_box 'sentora-post-install-check' > "$OUT/post-install-check.txt" 2>&1
grep -E '^\s+(FAIL|WARN)|^Result' "$OUT/post-install-check.txt"
FAILS=$(grep -oE 'Result: .* ([0-9]+) FAIL' "$OUT/post-install-check.txt" | grep -oE '[0-9]+ FAIL' | grep -oE '[0-9]+')
# the log of each service the check found stopped
for u in $(grep -E '^\s+FAIL' "$OUT/post-install-check.txt" | grep -oE 'systemctl status [A-Za-z0-9@._-]+' | awk '{print $3}' | sort -u); do
    echo "--- $u"; in_box "systemctl status $u --no-pager -l 2>&1 | tail -20; journalctl -u $u -b --no-pager 2>&1 | tail -30"
done | tee "$OUT/stopped-services-why.txt"

say "sentora-smoke-test"
in_box 'sentora-smoke-test' > "$OUT/smoke-test.txt" 2>&1
grep -E '^\s+\[|RESULT' "$OUT/smoke-test.txt" || { echo "(the smoke test stopped early; its last lines:)"; tail -20 "$OUT/smoke-test.txt"; }
in_box 'cat /var/sentora/logs/smoke-test.log' > "$OUT/smoke-test.log" 2>&1

say "ModSecurity through the Web Hardening add-on (on, attacks, watch only, off)"
docker cp "$INST/sentora-repo/php_hardening.zpp" "$NAME:/root/php_hardening.zpp"
docker cp "$INST/.github/scripts/modsec-test.sh" "$NAME:/root/modsec-test.sh"
in_box 'bash /root/modsec-test.sh' > "$OUT/modsec-test.txt" 2>&1
cat "$OUT/modsec-test.txt"
in_box "tail -60 \"\$(php -q /etc/sentora/panel/bin/setso --get apache_log_dir)/modsec_audit.log\"" > "$OUT/modsec-audit.log" 2>&1

say "Result for $SYS"
ok=1
if [ "${FAILS:-x}" != "0" ]; then echo "::error::$SYS: the post-install check has ${FAILS:-an unreadable number of} FAIL line(s)"; ok=0; fi
if ! grep -q 'RESULT: PASSED' "$OUT/smoke-test.txt"; then echo "::error::$SYS: the smoke test did not pass"; ok=0; fi
if ! grep -q 'MODSEC: PASSED' "$OUT/modsec-test.txt"; then echo "::error::$SYS: ModSecurity through the Web Hardening add-on did not pass (modsec-test.txt)"; ok=0; fi
[ $ok -eq 1 ] && echo "$SYS: installed, post-install check without FAIL, smoke test passed, ModSecurity works"
[ $ok -eq 1 ]
