#!/bin/bash
# Docker Hosting tests: the add-on (sentora-repo/docker_hosting.zpp, or the unzipped folder given as $1)
# in a stand-in panel (SQLite, small stand-ins for the core classes) served by PHP's own web server,
# and the node agent (node/sentora-node-agent.php) against it, with fake podman, caddy, useradd...
# (fakebin/). Needs php (pdo_sqlite, curl, sodium), unzip.
#   tests/docker_hosting/run.sh [module-folder]
# Against a real MySQL or MariaDB instead of SQLite (its database is emptied first):
#   DH_MYSQL="host;port;user;password;database" tests/docker_hosting/run.sh
set -u
T=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$T/../.." && pwd)
W=$(mktemp -d)
rm -rf "$T/root/modules"; mkdir -p "$T/root/modules"
if [ $# -gt 0 ]; then cp -r "$1" "$T/root/modules/docker_hosting"
else unzip -q "$REPO/sentora-repo/docker_hosting.zpp" -d "$T/root/modules/docker_hosting"; fi
mkdir -p "$W/fake" "$W/node/etc" "$W/node/state" "$W/node/caddy/sentora" "$W/node/run" "$W/node/home" "$W/node/sub"
touch "$W/node/sub/subuid" "$W/node/sub/subgid"
echo "import sentora/*.caddy" > "$W/node/caddy/Caddyfile"
export DH_DB="$W/test.sqlite" DH_WEB=http://127.0.0.1:18092 DH_AGENT="$REPO/node/sentora-node-agent.php" DH_W="$W"
export FAKE_DIR="$W/fake" SNODE_ETC="$W/node/etc" SNODE_STATE="$W/node/state" SNODE_CADDY="$W/node/caddy" SNODE_RUN="$W/node/run"
export SNODE_HOME="$W/node/home" SNODE_SUBID="$W/node/sub" SNODE_USERPATH="$T/fakebin:/usr/bin:/bin"
export SNODE_UPDATE_WAIT=0 SNODE_KEYFILE="$W/node/sentora-node-key.txt" DH_MAIL="$W/mail.txt"
export SNODE_PASSWD="$W/fake/passwd" SNODE_RESOLV="$W/fake/resolv.conf" SNODE_RUNMARK="$W/node/runmark"
printf "nameserver 192.168.1.1\nnameserver 8.8.8.8\n" > "$W/fake/resolv.conf"; touch "$W/fake/passwd"
if [ -n "${DH_MYSQL:-}" ]; then
    php -r 'list($h, $p, $u, $w, $d) = explode(";", getenv("DH_MYSQL")); $x = new PDO("mysql:host=$h;port=$p", $u, $w);
        $x->exec("DROP DATABASE IF EXISTS `$d`"); $x->exec("CREATE DATABASE `$d` CHARACTER SET utf8mb4"); echo "database: ", $x->query("SELECT VERSION()")->fetchColumn(), "\n";' || exit 1
fi
php -d display_errors=stderr -d error_reporting=-1 -S 127.0.0.1:18092 -t "$T/root" >"$W/web.log" 2>&1 &
S=$!
sleep 1
php "$T/test.php"; R=$?
if grep -q "PHP \(Warning\|Notice\|Deprecated\|Fatal\)" "$W/web.log"; then echo "FAIL the web server logged PHP problems:"; grep "PHP " "$W/web.log"; R=1; fi
kill $S
[ -n "${DH_KEEP:-}" ] && echo "kept: $W" || rm -rf "$W"
rm -rf "$T/root/modules"
exit $R
