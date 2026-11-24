#!/usr/bin/env bash
# Run inside the install test's container, after the install and sentora-smoke-test --keep (its
# domain is the site attacked): the Web Hardening add-on (the .zpp at
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
    require "modules/php_hardening/code/ph.php"; require "modules/php_hardening/code/daemon.php"; require "modules/php_hardening/code/modsec.php"; require "modules/php_hardening/code/bans.php"; '"$1"; }
daemon() { php "$PANEL/bin/daemon.php" > /root/modsec-daemon.txt 2>&1; grep -E 'Web Hardening' /root/modsec-daemon.txt | sed 's/^/        daemon: /'; }
# the final answer for a page of a host (followed through the site's http-to-https redirect, if it has one)
code() { curl -sk -L --max-time 20 --resolve "$1:80:127.0.0.1" --resolve "$1:443:127.0.0.1" -o /dev/null -w '%{http_code}' "http://$1$2"; }
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
# a real hosted site: the domain sentora-smoke-test --keep left in place (a name with no site is
# answered by the panel's own vhost, whose redirect comes before ModSecurity's request checks)
H=$(ph '$q = $GLOBALS["zdbh"]->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_name_vc LIKE :p ORDER BY vh_id_pk DESC LIMIT 1"); $q->execute(array(":p" => "smoke%.test")); echo (string) $q->fetchColumn();')
if [ -z "$H" ]; then bad "no hosted domain to attack (run sentora-smoke-test --keep first)"; echo "MODSEC: FAILED"; exit 1; fi
echo "        attacking the hosted site $H"
c=$(code "$H" '/'); echo "        $H answers $c before the attacks"
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

echo "== Bans (fail2ban, the real one)"
F2B=$(command -v fail2ban-client || echo /usr/bin/fail2ban-client)
if ! "$F2B" ping > /dev/null 2>&1; then systemctl start fail2ban > /dev/null 2>&1; sleep 3; fi
daemon
ST=$(ph 'echo json_encode(ph_bans::State());'); echo "        state: ${ST:0:400}"
case "$ST" in *'"running":true'*) ok "the add-on sees fail2ban running" ;; *) bad "the add-on does not see fail2ban running" ;; esac
case "$ST" in *'"sentora-manual"'*) ok "its jail for bans by hand is there" ;; *) bad "no jail for bans by hand" ;; esac
case "$ST" in *'"reqtimeout":true'*) ok "Apache's request timeouts (mod_reqtimeout) are loaded" ;; *) bad "Apache has no mod_reqtimeout loaded" ;; esac
ph 'ph_bans::Ask("ban", "203.0.113.77", 1, "install test", "zadmin");'
daemon
"$F2B" get sentora-manual banip 2> /dev/null | grep -q '203\.0\.113\.77' && ok "banned by hand: fail2ban has 203.0.113.77" || bad "banned by hand: fail2ban does not have it"
ph 'echo json_encode(ph_bans::State());' | grep -q '"ip":"203.0.113.77"' && ok "the page's list shows it" || bad "the page's list does not show it"
ph 'ph_bans::Ask("unban", "203.0.113.77");'
daemon
"$F2B" get sentora-manual banip 2> /dev/null | grep -q '203\.0\.113\.77' && bad "unban: still banned" || ok "unbanned"
# flood protection: an address of its own on this box sends more than the limit to the hosted site
ph 'ph::Set("flood_on", "1"); ph::Set("flood_limit", "30"); ph::Set("flood_minutes", "10");'
daemon
"$F2B" status sentora-flood > /dev/null 2>&1 && ok "flood protection on: fail2ban has the jail" || bad "flood protection on: no sentora-flood jail ($("$F2B" status 2>&1 | tail -1))"
# (from outside: a network of its own on this box, joined by a cable pair; fail2ban never bans the box's own addresses)
ip netns add sflood 2> /dev/null; ip link add vflood0 type veth peer name vflood1 2> /dev/null
ip link set vflood1 netns sflood; ip addr add 10.199.0.1/30 dev vflood0; ip link set vflood0 up
ip netns exec sflood ip addr add 10.199.0.2/30 dev vflood1; ip netns exec sflood ip link set vflood1 up; ip netns exec sflood ip link set lo up
# (firewalld, on the Red Hat family: the new cable joins the zone the server's other connections are in, for this test only)
if command -v firewall-cmd > /dev/null && firewall-cmd --state > /dev/null 2>&1; then
    FWZ=$(firewall-cmd --get-default-zone)
    firewall-cmd -q --zone="$FWZ" --change-interface=vflood0 2> /dev/null || true
    # (the test box's firewall is the system's stock one: Sentora's own opens the web ports on a real server, not in a container)
    firewall-cmd --zone="$FWZ" --query-service=http > /dev/null 2>&1 || { firewall-cmd -q --zone="$FWZ" --add-service=http; FWOPENED=1; }
fi
FC() { ip netns exec sflood curl -sk -o /dev/null -w '%{http_code}' --max-time 5 --resolve "$H:80:10.199.0.1" "http://$H$1" 2> /dev/null || true; }
c=$(FC /); echo "        from 10.199.0.2 the site answers $c before the flood"
if [ "$c" = 000 ]; then
    bad "the test's outside address can not reach the site before the flood"
    { command -v firewall-cmd > /dev/null && firewall-cmd --list-all; command -v ufw > /dev/null && ufw status; ss -ltnp 2> /dev/null | grep -E ':80 '; ip -br addr; } 2>&1 | sed 's/^/        /' | head -40
fi
for i in $(seq 1 45); do FC "/?flood=$i" > /dev/null; done
for i in $(seq 1 5); do FC "/style-$i.css" > /dev/null; done
banned=0
for _ in $(seq 1 20); do "$F2B" get sentora-flood banip 2> /dev/null | grep -q '10\.199\.0\.2' && { banned=1; break; }; sleep 2; done
[ $banned = 1 ] && ok "45 requests in a minute from 10.199.0.2: banned by flood protection" || { bad "the flood was not banned"; "$F2B" status sentora-flood 2>&1 | sed 's/^/        /'; tail -3 /var/log/fail2ban.log 2> /dev/null | sed 's/^/        /'; }
# (fail2ban lists the ban first, then its firewall action puts it in place: firewalld's takes a few seconds)
for _ in $(seq 1 10); do c=$(FC /); [ "$c" = 000 ] && break; sleep 2; done
[ "$c" = 000 ] && ok "the banned address gets no answer at all (the firewall drops it)" || bad "the banned address still got $c"
c=$(code "$H" '/'); [ "$c" != 000 ] && ok "everyone else still gets the site ($c)" || bad "the site does not answer others"
"$F2B" unban 10.199.0.2 > /dev/null 2>&1
c=$(FC /); [ "$c" != 000 ] && ok "unbanned: it gets the site again ($c)" || bad "unbanned, but still no answer"
[ -n "${FWOPENED:-}" ] && firewall-cmd -q --zone="$FWZ" --remove-service=http
ip link del vflood0 2> /dev/null; ip netns del sflood 2> /dev/null
ph 'ph::Set("flood_on", "0");'
daemon
"$F2B" status sentora-flood > /dev/null 2>&1 && bad "flood protection off: the jail is still there" || ok "flood protection off: the jail is gone"
if command -v ausearch > /dev/null 2>&1; then
    D=$(ausearch -m avc -ts boot 2> /dev/null | grep -ciE 'fail2ban' || true)
    [ "${D:-0}" -eq 0 ] && ok "no SELinux refusals for fail2ban" || { bad "$D SELinux refusal(s) for fail2ban:"; ausearch -m avc -ts boot 2> /dev/null | grep -i fail2ban | tail -5; }
fi
php modules/php_hardening/deploy/remove.run > /dev/null 2>&1
# (fail2ban restarts after the jails are taken away: a moment before it answers again)
for _ in $(seq 1 10); do [ ! -f /etc/fail2ban/jail.d/sentora-web-hardening.local ] && "$F2B" ping > /dev/null 2>&1 && break; sleep 2; done
[ ! -f /etc/fail2ban/jail.d/sentora-web-hardening.local ] && "$F2B" ping > /dev/null 2>&1 && ok "removing the add-on takes its jails away; fail2ban keeps running" || bad "after removing the add-on: its jail file is still there, or fail2ban stopped"

[ $FAIL -eq 0 ] && echo "MODSEC: PASSED" || echo "MODSEC: FAILED"
