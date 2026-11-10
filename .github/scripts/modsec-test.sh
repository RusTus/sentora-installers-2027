#!/usr/bin/env bash
# Run inside the install test's container, after the install: the Web Hardening add-on (the .zpp at
# /root/php_hardening.zpp, put in place as Repo Browser would) switches ModSecurity on; requests that
# look like attacks must get 403, an ordinary one and the panel's own must not; what was caught must
# reach the add-on's list; then it is switched off and Apache must run without ModSecurity.
# Prints "MODSEC: PASSED" or "MODSEC: FAILED".
set -u
PANEL=/etc/sentora/panel
sp() { php -q "$PANEL/bin/setso" --get "$1" 2> /dev/null; }
FAIL=0
ok()  { echo "  ok   $*"; }
bad() { echo "  FAIL $*"; FAIL=1; }
ph()  { php -r 'chdir("/etc/sentora/panel"); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php";
    require "modules/php_hardening/code/ph.php"; require "modules/php_hardening/code/daemon.php"; require "modules/php_hardening/code/modsec.php"; '"$1"; }
daemon() { php "$PANEL/bin/daemon.php" > /root/modsec-daemon.txt 2>&1; grep -E 'Web Hardening' /root/modsec-daemon.txt | sed 's/^/        daemon: /'; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H "Host: $1" "http://127.0.0.1$2"; }
cd "$PANEL" || exit 1

echo "== the add-on, as Repo Browser installs it"
rm -rf modules/php_hardening && mkdir -p modules/php_hardening && unzip -q /root/php_hardening.zpp -d modules/php_hardening \
    || { echo "MODSEC: FAILED (could not unpack the add-on)"; exit 1; }
php -r 'chdir("/etc/sentora/panel"); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php"; ui_module::ModuleInfoToDB("php_hardening");'
php modules/php_hardening/deploy/install.run

echo "== ModSecurity on"
ph 'ph::Set("ms:mode", "on");'
daemon
ST=$(ph 'echo json_encode(ph::Status("modsec"));'); echo "        state: $ST"
case "$ST" in *'"st_state_vc":"on"'*) ok "the add-on switched it on" ;; *) bad "the add-on did not switch it on" ;; esac
if $(sp apache_modules_cmd) 2> /dev/null | grep -q security2_module; then ok "Apache has ModSecurity loaded"; else bad "Apache has no ModSecurity loaded"; fi
systemctl is-active --quiet "$(sp apache_sn)" && ok "Apache runs" || bad "Apache does not run"
H=modsec-test.example
c=$(code "$H" '/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E'); [ "$c" = 403 ] && ok "cross-site scripting: 403" || bad "cross-site scripting: $c (403 expected)"
c=$(code "$H" "/?id=1%27%20OR%20%271%27%3D%271%27%20--%20"); [ "$c" = 403 ] && ok "SQL injection: 403" || bad "SQL injection: $c (403 expected)"
c=$(code "$H" '/?file=../../../../etc/passwd'); [ "$c" = 403 ] && ok "path traversal: 403" || bad "path traversal: $c (403 expected)"
c=$(code "$H" '/?page=about&lang=en'); [ "$c" != 403 ] && [ "$c" != 000 ] && ok "an ordinary request: $c" || bad "an ordinary request: $c"
P=$(sp sentora_domain)
c=$(code "$P" '/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E'); [ "$c" != 403 ] && [ "$c" != 000 ] && ok "the panel itself is only watched, not blocked: $c" || bad "the panel itself was blocked: $c"
daemon
N=$(ph 'echo count(array_filter(ph::Caught(), function ($c) { return $c["ca_feature_vc"] === "modsecurity" && $c["ca_action_vc"] === "drop"; }));')
[ "${N:-0}" -ge 3 ] && ok "$N blocked requests in the add-on's list" || bad "${N:-0} blocked requests in the add-on's list (3 expected)"
ph 'foreach (array_slice(ph::Caught(), 0, 3) as $c) { echo "        caught: ", $c["ca_file_tx"], " ", $c["ca_message_tx"], "\n"; }'
if command -v ausearch > /dev/null 2>&1; then
    D=$(ausearch -m avc -ts boot 2> /dev/null | grep -ciE 'httpd|mod_sec' || true)
    [ "${D:-0}" -eq 0 ] && ok "no SELinux refusals for Apache" || { bad "$D SELinux refusal(s) for Apache:"; ausearch -m avc -ts boot 2> /dev/null | grep -iE 'httpd|mod_sec' | tail -5; }
fi

echo "== watch only"
ph 'ph::Set("ms:mode", "watch");'
daemon
c=$(code "$H" '/?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E'); [ "$c" != 403 ] && [ "$c" != 000 ] && ok "watch only: the same request passes ($c)" || bad "watch only: $c"

echo "== ModSecurity off"
ph 'ph::Set("ms:mode", "off");'
daemon
[ ! -f "$(sp modsec_conf_dir)/sentora-web-hardening.conf" ] && ok "its settings file is gone" || bad "its settings file is still there"
if $(sp apache_modules_cmd) 2> /dev/null | grep -q security2_module; then bad "Apache still has ModSecurity loaded"; else ok "Apache runs without ModSecurity"; fi
systemctl is-active --quiet "$(sp apache_sn)" && ok "Apache runs" || bad "Apache does not run"

[ $FAIL -eq 0 ] && echo "MODSEC: PASSED" || echo "MODSEC: FAILED"
