#!/usr/bin/env bash
# Test the Sentora 2027 Docker image the way people will run it: build it, start a container WITHOUT
# --privileged (only the options in DOCKER_FLAGS), let it install itself on its first start, restart
# it, and run sentora-post-install-check and sentora-smoke-test inside. Exit 0 only when the first
# start finished, the check has no FAIL and the smoke test passed. With a volume at /backup in the
# options: the nightly full backup is made into it, the container is removed, a new one is started on
# the same volume and restored from it, and the site the smoke test left must come back the same.
#
#   docker-test.sh <installers checkout> <results folder>
#     DOCKER_FLAGS  the docker run options to test (the image's documented ones by default)
#     IMAGE         an image already built to test instead of building one
#
# Used by .github/workflows/docker-image.yml; also runs on a test machine with Docker on cgroup v2.
set -uo pipefail
INST=$(cd "${1:?installers checkout}" && pwd); OUT=${2:?results folder}
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
FLAGS=${DOCKER_FLAGS:---cgroupns=private --security-opt writable-cgroups=true --tmpfs /run --tmpfs /run/lock --cap-add NET_ADMIN}
NAME=sentora-docker-test
DOMAIN=panel.example.test
say() { printf '\n=== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }
in_box() { docker exec "$NAME" bash -c "$1"; }
# (the documented volume's name is replaced by a test volume of its own, made fresh and removed after)
BACKUP_TEST=0
if [[ " $FLAGS " == *":/backup "* ]]; then
    BACKUP_TEST=1; FLAGS=$(printf '%s' "$FLAGS" | sed -E 's#-v [^ ]+:/backup#-v sentora-docker-test-backup:/backup#')
    docker volume rm -f sentora-docker-test-backup > /dev/null 2>&1
fi
trap 'docker logs "$NAME" > "$OUT/container.log" 2>&1; [ -n "${TEST_KEEP:-}" ] || { docker rm -f "$NAME" > /dev/null 2>&1; docker volume rm -f sentora-docker-test-backup > /dev/null 2>&1; }' EXIT

if [ -z "${IMAGE:-}" ]; then
    say "Building the image"
    IMAGE=sentora2027:test
    built=0
    for try in 1 2 3; do   # (a mirror can fail for a moment)
        if docker build --pull --progress=plain -f "$INST/docker/Dockerfile" -t "$IMAGE" "$INST" > "$OUT/image-build.log" 2>&1; then built=1; break; fi
        echo "the image did not build (try $try):"; tail -15 "$OUT/image-build.log"; sleep $(( try * 20 ))
    done
    [ $built -eq 1 ] || { echo "::error::the image did not build"; exit 1; }
fi
docker image inspect "$IMAGE" --format 'image: {{.Id}}  {{.Size}} bytes'

# start a container as the README says, and wait until its first start has installed Sentora
start_and_install() { # log-file-suffix
    say "Starting it: docker run $FLAGS"
    docker rm -f "$NAME" > /dev/null 2>&1
    # shellcheck disable=SC2086
    docker run -d --name "$NAME" $FLAGS -h "$DOMAIN" \
        -e SENTORA_EMAIL=admin@example.com -e SENTORA_IP=local -e TZ=UTC \
        "$IMAGE" > /dev/null || { echo "::error::the container did not start"; exit 1; }
    docker inspect "$NAME" --format 'privileged: {{.HostConfig.Privileged}}  caps: {{.HostConfig.CapAdd}}  security: {{.HostConfig.SecurityOpt}}' | tee "$OUT/container-config.txt"
    sleep 5; echo "PID 1's outputs: $(in_box 'ls -l /proc/1/fd/0 /proc/1/fd/1 /proc/1/fd/2 2>&1 | sed "s/.*fd/fd/"' | tr '\n' ';')"
    echo "docker logs so far: $(docker logs "$NAME" 2>&1 | wc -l) lines"
    say "First start: Sentora installs itself"
    START=$(date +%s); state=''
    for _ in $(seq 1 450); do   # (up to 60 minutes)
        if in_box 'test -f /etc/sentora/.docker-installed' 2> /dev/null; then state=installed; break; fi
        if [ "$(in_box 'systemctl is-failed sentora-docker-firstboot' 2> /dev/null)" = failed ]; then state=failed; break; fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2> /dev/null)" != true ]; then state=stopped; break; fi
        sleep 8
    done
    docker logs "$NAME" > "$OUT/first-start$1.log" 2>&1
    grep '^sentora: \|^-- \[' "$OUT/first-start$1.log" | tail -60
    echo "first start: ${state:-timed out} after $(( ($(date +%s) - START) / 60 )) minutes"
    in_box 'cat /root/sentora-install-screen.txt' > "$OUT/install-screen$1.txt" 2>&1
    in_box 'cat /root/*_sentora_install.log' > "$OUT/install$1.log" 2>&1
    in_box 'systemctl --failed --no-legend' > "$OUT/failed-units-first-start$1.txt" 2>&1
}
start_and_install ""
if [ "$state" = stopped ]; then
    echo "--- why the container stopped"
    docker inspect "$NAME" --format 'exit code {{.State.ExitCode}}  error: {{.State.Error}}  OOM: {{.State.OOMKilled}}'
    echo "--- the same start again, with systemd telling everything on the console"
    docker rm -f "$NAME-why" > /dev/null 2>&1
    # shellcheck disable=SC2086
    docker run -d -t --name "$NAME-why" $FLAGS -h "$DOMAIN" "$IMAGE" /sbin/init --log-target=console --log-level=debug > /dev/null
    sleep 20
    docker logs "$NAME-why" 2>&1 | tr -d '\r' | tail -80 | tee "$OUT/why-stopped.log"
    docker inspect "$NAME-why" --format 'running: {{.State.Running}}  exit code {{.State.ExitCode}}'
    docker rm -f "$NAME-why" > /dev/null 2>&1
fi
if [ "$state" != installed ]; then
    tail -40 "$OUT/install-screen.txt"
    echo "--- services that failed"; cat "$OUT/failed-units-first-start.txt"
    echo "::error::the first start did not install Sentora (${state:-timed out}; first-start.log, install-screen.txt and install.log in the results)"
    exit 1
fi
# the progress must reach docker logs (what people watch), and no password may
grep -q '^sentora: Sentora is installed' "$OUT/first-start.log" && grep -q '^-- \[' "$OUT/first-start.log" \
    || { echo "::error::docker logs does not show the install's steps and its end"; exit 1; }
echo "docker logs shows $(grep -c '^-- \[' "$OUT/first-start.log") steps and the end"
# no password may reach the container's log
in_box 'cat /root/passwords.txt' 2> /dev/null | grep -v '^Server IP\|^Panel URL' | awk -F' : ' 'NF > 1 {print $2}' \
    | awk '{print $NF}' | grep -E '^[^ ()]{8,}$' > "$OUT/.pw"
echo "passwords looked for: $(wc -l < "$OUT/.pw")"
if [ -s "$OUT/.pw" ] && grep -qFf "$OUT/.pw" "$OUT/first-start.log"; then
    rm -f "$OUT/.pw"; echo "::error::a password from /root/passwords.txt is in the container's log"; exit 1
fi
rm -f "$OUT/.pw"
echo "no password in the container's log"

in_box 'head -c 32 /etc/sentora/panel/VERSION' | tr -cd 'A-Za-z0-9._-' > "$OUT/version.txt"
echo "installed: Sentora-$(cat "$OUT/version.txt")"
in_box "grep -h '^PassivePorts' /etc/sentora/configs/proftpd/proftpd-mysql.conf" | tee "$OUT/ftp-passive.txt"
grep -qE '61000[[:space:]]+61099' "$OUT/ftp-passive.txt" || { echo "::error::FTP's passive ports are not 61000-61099"; exit 1; }

say "Restarting the container (services must come back by themselves)"
docker restart "$NAME" > /dev/null
for _ in $(seq 1 90); do in_box 'systemctl is-system-running 2> /dev/null' | grep -qE 'running|degraded' && break; sleep 2; done
in_box 'systemctl is-system-running' || true
in_box 'systemctl --failed --no-legend' | tee "$OUT/failed-units.txt"
for u in $(awk '{for (i = 1; i <= NF; i++) if ($i ~ /\.service$/) {print $i; break}}' "$OUT/failed-units.txt"); do
    echo "--- $u"; in_box "systemctl status $u --no-pager -l 2>&1 | tail -25; journalctl -u $u -b --no-pager 2>&1 | tail -25"
done | tee "$OUT/failed-units-why.txt"
for _ in $(seq 1 60); do in_box 'mariadb-admin ping > /dev/null 2>&1 || mysqladmin ping > /dev/null 2>&1' && break; sleep 2; done
in_box 'php -q /etc/sentora/panel/bin/daemon.php > /var/sentora/logs/daemon-last-run.log 2>&1'

say "sentora-post-install-check"
in_box 'sentora-post-install-check' > "$OUT/post-install-check.txt" 2>&1
grep -E '^\s+(FAIL|WARN)|^Result' "$OUT/post-install-check.txt"
FAILS=$(grep -oE 'Result: .* ([0-9]+) FAIL' "$OUT/post-install-check.txt" | grep -oE '[0-9]+ FAIL' | grep -oE '[0-9]+')

say "sentora-smoke-test"
KEEP=''; [ $BACKUP_TEST -eq 1 ] && KEEP=' --keep'   # (its site is what the restore must bring back)
in_box "sentora-smoke-test$KEEP" > "$OUT/smoke-test.txt" 2>&1
grep -E '^\s+\[|RESULT' "$OUT/smoke-test.txt" || { echo "(the smoke test stopped early; its last lines:)"; tail -20 "$OUT/smoke-test.txt"; }
in_box 'cat /var/sentora/logs/smoke-test.log' > "$OUT/smoke-test.log" 2>&1

say "From outside the container, through the published ports"
outside=1
if [[ " $FLAGS " == *" -p 443:443 "* ]]; then
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/")
    echo "panel over https (443): HTTP $code"; [[ "$code" =~ ^(200|302)$ ]] || outside=0
fi
if [[ " $FLAGS " == *" -p 25:25 "* ]]; then
    banner=$(timeout 15 bash -c 'exec 3<>/dev/tcp/127.0.0.1/25; head -1 <&3; printf "QUIT\r\n" >&3' 2>/dev/null | tr -d '\r')
    echo "mail (25): ${banner:-no answer}"; [[ "$banner" == 220* ]] || outside=0
fi
if [[ " $FLAGS " == *" -p 21:21 "* ]]; then
    banner=$(timeout 15 bash -c 'exec 3<>/dev/tcp/127.0.0.1/21; head -1 <&3' 2>/dev/null | tr -d '\r')
    echo "FTP (21): ${banner:-no answer}"; [[ "$banner" == 220* ]] || outside=0
fi

restored=1
if [ $BACKUP_TEST -eq 1 ]; then
    say "Nightly full backup into the volume at /backup"
    st=$(in_box '/etc/sentora/panel/bin/setso --get offsite_path; /etc/sentora/panel/bin/setso --get offsite_enabled' | tr '\n' ' ')
    echo "off-server backup settings: $st"; [ "$st" = "/backup true " ] || { echo "the first start did not switch the backup on"; restored=0; }
    H=$(in_box "cd /etc/sentora/panel && php -r 'require \"dryden/loader.inc.php\"; require \"cnf/db.php\"; require \"inc/dbc.inc.php\"; \$q = \$zdbh->prepare(\"SELECT vh_name_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_name_vc LIKE :p ORDER BY vh_id_pk DESC LIMIT 1\"); \$q->execute(array(\":p\" => \"smoke%.test\")); echo (string) \$q->fetchColumn();'")
    page() { in_box "curl -s -o /tmp/page -w '%{http_code}' --max-time 20 --resolve '$H:80:127.0.0.1' 'http://$H/'; echo \" \$(sha256sum < /tmp/page | cut -c1-16)\""; }
    before=$(page); echo "the smoke test's site $H: $before"
    in_box '/etc/sentora/panel/bin/sentora-offsite-backup' 2>&1 | tail -3 | tee "$OUT/backup.txt"
    SNAP=$(in_box "ls -1 /backup/$DOMAIN 2> /dev/null | grep -E '^20[0-9-]+$' | tail -1")
    echo "snapshot in the volume: ${SNAP:-none}"; [ -n "$SNAP" ] || restored=0

    say "The container removed; a new one on the same volume, restored from it"
    docker rm -f "$NAME" > /dev/null
    start_and_install "-2"
    [ "$state" = installed ] || restored=0
    grep -q 'already holds a backup' "$OUT/first-start-2.log" && echo "the new container saw the backup" || { echo "the new container did not report the backup"; restored=0; }
    in_box "/etc/sentora/panel/bin/sentora-panel-restore --full '$DOMAIN' '$SNAP' --yes" > "$OUT/restore.txt" 2>&1; rc=$?
    tail -5 "$OUT/restore.txt"; [ $rc -eq 0 ] || { echo "the restore ended with $rc"; restored=0; }
    in_box 'php -q /etc/sentora/panel/bin/daemon.php > /var/sentora/logs/daemon-last-run.log 2>&1'
    after=$(page); echo "the smoke test's site $H after the restore: $after"
    [ -n "$H" ] && [[ "$before" == 200\ * ]] && [ "$before" = "$after" ] || { echo "the site did not come back the same"; restored=0; }
    in_box 'sentora-post-install-check' > "$OUT/post-install-check-restored.txt" 2>&1
    grep -E '^\s+FAIL|^Result' "$OUT/post-install-check-restored.txt"
    grep -qE 'Result: .* 0 FAIL' "$OUT/post-install-check-restored.txt" || { echo "the restored server's check has FAIL lines"; restored=0; }
fi

say "Result"
ok=1
if [ "${FAILS:-x}" != "0" ]; then echo "::error::the post-install check has ${FAILS:-an unreadable number of} FAIL line(s)"; ok=0; fi
if ! grep -q 'RESULT: PASSED' "$OUT/smoke-test.txt"; then echo "::error::the smoke test did not pass"; ok=0; fi
if [ $outside -eq 0 ]; then echo "::error::the published ports did not all answer from outside the container"; ok=0; fi
if [ $restored -eq 0 ]; then echo "::error::the backup in the volume did not bring the server back in a new container"; ok=0; fi
[ $ok -eq 1 ] && echo "docker run $FLAGS: installed on the first start, post-install check without FAIL, smoke test passed"
[ $ok -eq 1 ]
