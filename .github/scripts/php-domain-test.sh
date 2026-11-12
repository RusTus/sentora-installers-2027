#!/usr/bin/env bash
# Run inside the install test's container, after sentora-smoke-test --keep (its domain is used):
# a domain's own PHP settings (Apache Admin, x_vhosts.vh_php_ini_tx) must reach that site through a
# PHP-FPM pool of its own, keep the account's safety fences, still let the site's .user.ini change a
# value, and go away again when the settings are removed. Also: the server-wide PHP defaults.
# Prints "PHPDOMAIN: PASSED" or "PHPDOMAIN: FAILED".
set -u
PANEL=/etc/sentora/panel
sp() { php -q "$PANEL/bin/setso" --get "$1" 2> /dev/null; }
FAIL=0
ok()  { echo "  ok   $*"; }
bad() { echo "  FAIL $*"; FAIL=1; }
db()  { php -r 'chdir("/etc/sentora/panel"); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php"; '"$1"; }
daemon() { db '$GLOBALS["zdbh"]->exec("UPDATE x_settings SET so_value_tx = '"'"'true'"'"' WHERE so_name_vc = '"'"'apache_changed'"'"'");'
    php "$PANEL/bin/daemon.php" > /root/php-domain-daemon.txt 2>&1; grep -E 'PHP|refused' /root/php-domain-daemon.txt | sed 's/^/        daemon: /' | head -8; }
get() { curl -sk -L --max-time 20 --resolve "$H:80:127.0.0.1" --resolve "$H:443:127.0.0.1" "http://$H/sentora-php-domain-test.php"; }
cd "$PANEL" || exit 1

ROW=$(db '$q = $GLOBALS["zdbh"]->prepare("SELECT v.vh_id_pk, v.vh_name_vc, v.vh_directory_vc, v.vh_acc_fk, a.ac_user_vc FROM x_vhosts v JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk WHERE v.vh_deleted_ts IS NULL AND v.vh_name_vc LIKE :p ORDER BY v.vh_id_pk DESC LIMIT 1"); $q->execute(array(":p" => "smoke%.test")); $r = $q->fetch(); if ($r) { echo $r["vh_id_pk"], " ", $r["vh_name_vc"], " ", $r["vh_acc_fk"], " ", $r["ac_user_vc"], " ", trim($r["vh_directory_vc"], "/"); }')
read -r VID H ACC USER DIR <<< "$ROW"
if [ -z "${H:-}" ]; then echo "  FAIL no hosted domain (run sentora-smoke-test --keep first)"; echo "PHPDOMAIN: FAILED"; exit 1; fi
ROOT="$(sp hosted_dir)$USER/public_html/$DIR"
echo "        domain $H (id $VID, account $USER, folder $ROOT)"
cat > "$ROOT/sentora-php-domain-test.php" <<'EOF'
<?php
foreach (array('apc.shm_size', 'upload_max_filesize', 'post_max_size', 'max_input_vars', 'session.use_strict_mode', 'session.cookie_httponly', 'session.cookie_samesite', 'opcache.validate_permission', 'open_basedir', 'date.timezone') as $k) {
    echo $k, '=', ini_get($k), "\n";
}
echo 'apcu=', extension_loaded('apcu') ? 1 : 0, "\n";
echo 'user=', function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user(), "\n";
EOF
chown "web$ACC:web$ACC" "$ROOT/sentora-php-domain-test.php"; chmod 640 "$ROOT/sentora-php-domain-test.php"
val() { echo "$1" | sed -n "s/^$2=//p"; }

echo "== the server's own values (no settings for this domain)"
B=$(get); echo "$B" | sed 's/^/        /'
[ "$(val "$B" user)" = "web$ACC" ] && ok "the site's PHP runs as web$ACC" || bad "the site's PHP runs as '$(val "$B" user)'"
BASE_UP=$(val "$B" upload_max_filesize)

echo "== PHP settings for this domain"
db '$GLOBALS["zdbh"]->prepare("UPDATE x_vhosts SET vh_php_ini_tx = :t WHERE vh_id_pk = :id")->execute(array(":t" => "upload_max_filesize = 77M\npost_max_size = 80M\nmax_input_vars = 4321\nopen_basedir = /", ":id" => '"$VID"'));' 2> /dev/null \
    || { sys_domainphp_col=$(db 'var_dump(sys_domainphp::EnsureColumn());'); echo "        column: $sys_domainphp_col"; db '$GLOBALS["zdbh"]->prepare("UPDATE x_vhosts SET vh_php_ini_tx = :t WHERE vh_id_pk = :id")->execute(array(":t" => "upload_max_filesize = 77M\npost_max_size = 80M\nmax_input_vars = 4321\nopen_basedir = /", ":id" => '"$VID"'));'; }
daemon
POOLS=$(find /etc /opt/sentora-php -name "web$ACC-d$VID.conf" 2> /dev/null)
[ -n "$POOLS" ] && ok "a pool of its own: $POOLS" || bad "no pool web$ACC-d$VID.conf"
grep -q 'open_basedir = /$' $POOLS 2> /dev/null && bad "the refused line (open_basedir = /) reached the pool" || ok "the refused line (open_basedir) is left out"
grep -rqs "web$ACC-d$VID" "$(sp apache_vhost)" && ok "Apache sends the domain to it" || bad "Apache does not send the domain to its pool"
S=$(get); echo "$S" | sed 's/^/        /'
[ "$(val "$S" upload_max_filesize)" = 77M ] && ok "upload_max_filesize 77M reached the site" || bad "upload_max_filesize: $(val "$S" upload_max_filesize) (77M expected)"
[ "$(val "$S" max_input_vars)" = 4321 ] && ok "max_input_vars 4321 reached the site" || bad "max_input_vars: $(val "$S" max_input_vars) (4321 expected)"
case "$(val "$S" open_basedir)" in "$(sp hosted_dir)$USER/"*) ok "open_basedir still fences the account: $(val "$S" open_basedir)" ;; *) bad "open_basedir: '$(val "$S" open_basedir)'" ;; esac
[ "$(val "$S" user)" = "web$ACC" ] && ok "still runs as web$ACC" || bad "runs as '$(val "$S" user)'"

echo "== the site's own .user.ini can still change a value"
printf 'max_input_vars = 1234\n' > "$ROOT/.user.ini"; chown "web$ACC:web$ACC" "$ROOT/.user.ini"
systemctl reload "$(db 'echo sys_accountphp::ServiceName(sys_phpversions::Effective(""));')" 2> /dev/null; sleep 2
U=$(get)
[ "$(val "$U" max_input_vars)" = 1234 ] && ok ".user.ini's max_input_vars 1234 wins" || bad ".user.ini: max_input_vars $(val "$U" max_input_vars) (1234 expected)"
rm -f "$ROOT/.user.ini"

echo "== settings removed: back to the server's values"
db '$GLOBALS["zdbh"]->prepare("UPDATE x_vhosts SET vh_php_ini_tx = NULL WHERE vh_id_pk = :id")->execute(array(":id" => '"$VID"'));'
daemon
[ -z "$(find /etc /opt/sentora-php -name "web$ACC-d$VID.conf" 2> /dev/null)" ] && ok "its pool is gone" || bad "its pool is still there"
R=$(get)
[ "$(val "$R" upload_max_filesize)" = "$BASE_UP" ] && ok "upload_max_filesize is the server's again ($BASE_UP)" || bad "upload_max_filesize: $(val "$R" upload_max_filesize) ($BASE_UP expected)"

echo "== the server-wide PHP defaults"
echo "$R" | grep -q '^session.use_strict_mode=1' && ok "session.use_strict_mode on" || bad "session.use_strict_mode: $(val "$R" session.use_strict_mode)"
echo "$R" | grep -q '^session.cookie_httponly=1' && ok "session.cookie_httponly on" || bad "session.cookie_httponly: $(val "$R" session.cookie_httponly)"
[ "$(val "$R" session.cookie_samesite)" = Lax ] && ok "session.cookie_samesite Lax" || bad "session.cookie_samesite: '$(val "$R" session.cookie_samesite)'"
[ "$(val "$R" upload_max_filesize)" = 64M ] && ok "upload_max_filesize 64M" || bad "upload_max_filesize: $(val "$R" upload_max_filesize) (64M expected)"
[ "$(val "$R" max_input_vars)" = 3000 ] && ok "max_input_vars 3000" || bad "max_input_vars: $(val "$R" max_input_vars) (3000 expected)"
echo "$R" | grep -q '^opcache.validate_permission=1' && ok "opcache.validate_permission on" || bad "opcache.validate_permission: '$(val "$R" opcache.validate_permission)'"

echo "== PHP extensions (PHP Versions > Extensions): apcu added from the system's package, with a setting"
V=$(db 'echo sys_phpversions::DefaultVersion();')
db 'sys_phpext::SaveWanted(array("'"$V"'" => array("apcu" => array("on" => true, "ini" => "apc.shm_size = 48M", "asked" => time()))));'
daemon
grep -A4 'START PHP extensions' /root/php-domain-daemon.txt | sed 's/^/        /'
E=$(get); echo "$E" | grep -E '^apcu' | sed 's/^/        /'
[ "$(val "$E" apcu)" = 1 ] && ok "apcu reached the site (PHP $V)" || bad "apcu is not loaded for the site: $(db 'echo json_encode(sys_phpext::Inventory()["'"$V"'"]["results"]["apcu"] ?? null);')"
[ "$(val "$E" apc.shm_size)" = 48M ] && ok "its setting apc.shm_size 48M reached the site" || bad "apc.shm_size: '$(val "$E" apc.shm_size)' (48M expected)"
db '$i = sys_phpext::ParseSettings("apcu", "apcu.shm_size = /etc/passwd\nopcache.preload = x"); echo count($i[1]), "\n";' | grep -qx 2 && ok "a path and another extension's setting are refused" || bad "a refused setting was taken"
rm -f "$ROOT/sentora-php-domain-test.php"
[ $FAIL -eq 0 ] && echo "PHPDOMAIN: PASSED" || echo "PHPDOMAIN: FAILED"
