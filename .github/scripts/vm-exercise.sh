#!/usr/bin/env bash
# shellcheck disable=SC2034  # (ADMIN, LOGGED, CRON, ...: flags read by need() through ${!name})
# Use a freshly installed Sentora server the way people do, through its real doors (the panel in a
# browser, mail and FTP clients, webmail, SOGo and ActiveSync, the websites, cron, the daemon's own
# runs), so that SELinux or AppArmor see everything Sentora does. Run as root on the server (the VM
# test, .github/scripts/vm-test.sh, runs it twice: once as the system is, once with the security
# module's hidden refusals shown). It prints one line per exercise ("OK", "FAIL: why" or "SKIP: why")
# and ends with "EXERCISE: n OK, n FAIL, n SKIP". Everything it makes is removed at the end.
#
#   vm-exercise.sh [<name>]     name: a short word for this round (default: the time)
#
# What it does not judge: whether the security module refused something. The VM test reads that from
# the system's own log afterwards (ausearch, the kernel's AppArmor lines).
set -u
PANEL=/etc/sentora/panel
ROUND=${1:-$(date +%H%M%S)}
sp() { php -q "$PANEL/bin/setso" --get "$1" 2> /dev/null; }
OKS=0; FAILS=0; SKIPS=0; WHY=""
ex() { # ex "what" function: 0 = OK, 1 = FAIL ($WHY), 2 = SKIP ($WHY)
    WHY=""; local t0; t0=$(date +%s)
    "$2"; local rc=$?
    WHY=$(printf '%s' "$WHY" | tr -s '[:space:]' ' ' | cut -c1-220)
    case $rc in
        0) OKS=$((OKS + 1)); printf '  %-58s OK (%ss)\n' "$1" "$(( $(date +%s) - t0 ))" ;;
        2) SKIPS=$((SKIPS + 1)); printf '  %-58s SKIP: %s\n' "$1" "$WHY" ;;
        *) FAILS=$((FAILS + 1)); printf '  %-58s FAIL: %s\n' "$1" "${WHY:-no reason given}" ;;
    esac
}
need() { [ -n "${!1:-}" ] && return 0; WHY="needs: $2"; return 2; }
wait_for() { # wait_for seconds command...: true as soon as the command is
    local n=$1; shift
    for _ in $(seq 1 "$n"); do "$@" && return 0; sleep 1; done
    return 1
}

DBC=$(sp db_client); DBC=${DBC:-mysql}
val() { grep -o "\$$1 = '[^']*'" "$PANEL/cnf/db.php" | head -1 | cut -d"'" -f2; }
MYCNF=$(mktemp); chmod 600 "$MYCNF"; printf '[client]\nuser=%s\npassword=%s\n' "$(val user)" "$(val pass)" > "$MYCNF"
q() { "$DBC" --defaults-extra-file="$MYCNF" -N -B -e "$1" sentora_core 2> /dev/null; }
FQDN=$(q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='sentora_domain'")
HOSTED=$(q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='hosted_dir'"); HOSTED=${HOSTED%/}
RND=$(head -c 3 /dev/urandom | od -An -tx1 | tr -d ' \n')
U="vmx$ROUND$RND"; U=${U:0:20}; UPW="Vm-$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')"
DOM="vmx$RND.test"; MAILPW="Ma1l-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
FTPPW="Ftp-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
APW="Adm-$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TMP=$(mktemp -d); CJ="$TMP/client.jar"; AJ="$TMP/admin.jar"
ACC_ID=""; WEBU=""; DOMDIR=""; SITE=""; FTPUSER=""; MAILADDR=""; MYUSER=""; MYPW=""; DBNAME=""; LOGGED=""; ADMIN=""
BANIP=10.99.0.1

# the panel in a browser: the client's and the administrator's own sessions
cu() { curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -c "$CJ" -b "$CJ" "$@"; }
au() { curl -sk --max-time 120 --resolve "$FQDN:443:127.0.0.1" -c "$AJ" -b "$AJ" "$@"; }
ctok() { cu "https://$FQDN/?module=$1" | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
atok() { au "https://$FQDN/?module=$1" | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
site() { curl -sk --max-time 30 --resolve "$DOM:80:127.0.0.1" --resolve "$DOM:443:127.0.0.1" "$@"; }
php_panel() {
    ( cd "$PANEL" && php -r 'require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php"; $_SESSION["zpuid"] = 1; $GLOBALS["controller"] = new runtime_controller(); '"$1" "${@:2}" 2>&1 )
}
# a daemon run the way the panel's "Run the daemon now" starts one (zsudo's queue, as root's service)
daemon_now() {
    local f=/var/sentora/logs/daemon-panel-run.log; rm -f "$f"
    runuser -u sentora -- "$(sp zsudo)" daemon-run > /dev/null 2>&1 || return 1
    wait_for 600 bash -c "grep -q 'Daemon run complete' $f 2> /dev/null"
}
page_ok() { # the page in $1 is a real panel page without a PHP error
    [ -s "$1" ] || { WHY="empty page"; return 1; }
    grep -qiE '<b>(Fatal error|Warning|Notice|Parse error)</b>|Uncaught |Stack trace:' "$1" && { WHY="PHP error: $(grep -oiE '(Fatal error|Warning|Notice|Uncaught)[^<]{0,140}' "$1" | head -1)"; return 1; }
    return 0
}
mailpy() { # imap <subject> | imap-append | pop3 <subject> | submit <port> <file> | send <host> <file> <rcpt>
    MAILADDR="$MAILADDR" MAILPW="$MAILPW" python3 - "$@" <<'EOF_PY'
import imaplib, poplib, smtplib, ssl, sys, os, time
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
user, pw = os.environ['MAILADDR'], os.environ['MAILPW']
a = sys.argv[1:]
try:
    if a[0] == 'imap':
        m = imaplib.IMAP4_SSL('127.0.0.1', 993, ssl_context=ctx); m.login(user, pw); m.select('INBOX')
        typ, data = m.search(None, 'SUBJECT', '"%s"' % a[1])
        ids = data[0].split()
        if not ids: sys.exit(1)
        m.fetch(ids[-1], '(RFC822)'); m.store(ids[-1], '+FLAGS', '\\Seen'); m.logout(); sys.exit(0)
    if a[0] == 'imap-append':
        m = imaplib.IMAP4_SSL('127.0.0.1', 993, ssl_context=ctx); m.login(user, pw)
        m.create('VMtest'); m.subscribe('VMtest')
        r = m.append('VMtest', '\\Seen', imaplib.Time2Internaldate(time.time()), b'Subject: vm append\r\n\r\nappended\r\n')
        m.select('VMtest'); typ, data = m.search(None, 'ALL'); n = len(data[0].split())
        m.close(); m.delete('VMtest'); m.logout(); sys.exit(0 if n == 1 else 1)
    if a[0] == 'pop3':
        p = poplib.POP3_SSL('127.0.0.1', 995, context=ctx); p.user(user); p.pass_(pw)
        n, _ = p.stat()
        found = any(a[1].encode() in b'\n'.join(p.top(i, 0)[1]) for i in range(1, n + 1))
        p.quit(); sys.exit(0 if found else 1)
    if a[0] == 'submit':
        port = int(a[1])
        s = smtplib.SMTP_SSL('127.0.0.1', port, context=ctx, timeout=60) if port == 465 else smtplib.SMTP('127.0.0.1', port, timeout=60)
        if port != 465: s.starttls(context=ctx)
        s.login(user, pw); s.sendmail(user, [user], open(a[2], 'rb').read()); s.quit(); sys.exit(0)
    if a[0] == 'send':
        s = smtplib.SMTP(a[1], 25, timeout=90); s.sendmail('outside@example.com', [a[3]], open(a[2], 'rb').read()); s.quit(); sys.exit(0)
except SystemExit:
    raise
except Exception as e:
    print('error: %s' % e); sys.exit(1)
EOF_PY
}
msg() { printf 'From: %s\r\nTo: %s\r\nSubject: %s\r\nDate: %s\r\nMessage-ID: <%s@vm.test>\r\n\r\n%s\r\n' "$1" "$2" "$3" "$(date -R)" "$RANDOM$RANDOM" "$4" > "$5"; }
arrived() { wait_for 90 bash -c "MAILADDR='$MAILADDR' MAILPW='$MAILPW'; $(declare -f mailpy); mailpy imap '$1' > /dev/null 2>&1"; }

# --- the administrator -------------------------------------------------------------------------
x_admin_login() {
    "$PANEL/bin/setzadmin" --set "$APW" > /dev/null 2>&1 || php -q "$PANEL/bin/setzadmin" --set "$APW" > /dev/null 2>&1 || { WHY="setzadmin failed"; return 1; }
    au -o "$TMP/a.html" "https://$FQDN/"
    local t; t=$(grep -o 'name="csfr_token" value="[^"]*"' "$TMP/a.html" | head -1 | sed 's/.*value="//;s/"//')
    au -o /dev/null -d "inUsername=zadmin&inPassword=$APW&csfr_token=$t" "https://$FQDN/"
    au -o "$TMP/a.html" "https://$FQDN/?module=sentoraconfig"
    grep -q 'name="inUsername"' "$TMP/a.html" && { WHY="the login was refused"; return 1; }
    ADMIN=1
}
walk() { # walk <a|c> <label>: every module page the account can open
    local who=$1 m c n=0 bad=""
    for m in "$PANEL"/modules/*/module.xml; do
        m=$(basename "$(dirname "$m")")
        if [ "$who" = a ]; then c=$(au -o "$TMP/w.html" -w '%{http_code}' "https://$FQDN/?module=$m")
        else c=$(cu -o "$TMP/w.html" -w '%{http_code}' "https://$FQDN/?module=$m"); fi
        n=$((n + 1))
        if [ "$c" != 200 ]; then bad="$bad $m($c)"; continue; fi
        page_ok "$TMP/w.html" || bad="$bad $m($WHY)"
        # (a page that loads data after it opens: its feeds, as the page asks for them)
        case $m in
            server_monitor) if [ "$who" = a ]; then
                    c=$(au -o "$TMP/f.json" -w '%{http_code}' -H 'X-Requested-With: XMLHttpRequest' "https://$FQDN/modules/server_monitor/code/hardening.php")
                    [ "$c" = 200 ] || bad="$bad server_monitor/hardening.php($c)"; fi ;;
        esac
    done
    # the widget rail, as every page asks for it
    if [ "$who" = a ]; then c=$(au -o "$TMP/r.html" -w '%{http_code}' -H 'X-Requested-With: sentora-rail' "https://$FQDN/modules/dashboard/code/rail.php")
    else c=$(cu -o "$TMP/r.html" -w '%{http_code}' -H 'X-Requested-With: sentora-rail' "https://$FQDN/modules/dashboard/code/rail.php"); fi
    [ "$c" = 200 ] || bad="$bad rail($c)"
    WHY="$n pages"; [ -z "$bad" ] && return 0
    WHY="$n pages, these not right:$bad"; return 1
}
x_admin_walk() { need ADMIN "administrator login" || return $?; walk a; }
x_admin_daemon_button() {
    need ADMIN "administrator login" || return $?
    local f=/var/sentora/logs/daemon-panel-run.log; rm -f "$f"
    au -L -o "$TMP/a.html" -d "csfr_token=$(atok sentoraconfig)" "https://$FQDN/?module=sentoraconfig&action=RunDaemon"
    page_ok "$TMP/a.html" || return 1
    wait_for 600 bash -c "grep -q 'Daemon run complete' $f 2> /dev/null" || { WHY="the daemon did not run (zsudo.log: $(tail -2 /var/sentora/logs/zsudo.log 2> /dev/null))"; return 1; }
}
x_admin_hardening_button() {
    need ADMIN "administrator login" || return $?
    local h=/var/sentora/logs/hardening.json; local before; before=$(stat -c %Y "$h" 2> /dev/null || echo 0)
    au -L -o "$TMP/a.html" -d "csfr_token=$(atok server_monitor)" "https://$FQDN/?module=server_monitor&action=HardeningCheck"
    page_ok "$TMP/a.html" || return 1
    wait_for 400 bash -c "[ \$(stat -c %Y $h 2> /dev/null || echo 0) -gt $before ]" || { WHY="no new hardening report"; return 1; }
    WHY="score $(php -r 'echo json_decode(file_get_contents("/var/sentora/logs/hardening.json"), true)["score"];')"
}
x_admin_update_check() {
    need ADMIN "administrator login" || return $?
    au -L -o "$TMP/a.html" -d "csfr_token=$(atok panel_update)" "https://$FQDN/?module=panel_update&action=CheckNow"
    page_ok "$TMP/a.html" || return 1
    wait_for 300 bash -c "grep -q 'panel-check' /var/sentora/logs/zsudo.log" || { WHY="zsudo never ran panel-check"; return 1; }
}
x_apache_status() {
    need ADMIN "administrator login" || return $?
    au -o "$TMP/a.html" "https://$FQDN/?module=apache_status"; page_ok "$TMP/a.html" || return 1
    grep -qiE 'uptime|requests' "$TMP/a.html" || { WHY="no figures on the page"; return 1; }
}
x_logviewer() {
    need ADMIN "administrator login" || return $?
    local n=0 bad="" id c read
    au -o "$TMP/a.html" "https://$FQDN/?module=system_logviewer"; page_ok "$TMP/a.html" || return 1
    read=$(grep -o 'data-read="[^"]*"' "$TMP/a.html" | head -1 | sed 's/data-read="//;s/"$//;s/&amp;/\&/g')
    [ -n "$read" ] || { WHY="no log reader on the page"; return 1; }
    case $read in http*) ;; /*) read="https://$FQDN$read" ;; *) read="https://$FQDN/${read#./}" ;; esac
    for id in $(grep -o 'class="lv-item" data-id="[^"]*"' "$TMP/a.html" | sed 's/.*data-id="//;s/"//'); do
        c=$(au -o "$TMP/l.out" -w '%{http_code}' "$read?id=$id"); n=$((n + 1))
        [ "$c" = 200 ] || bad="$bad $id($c)"
    done
    WHY="$n logs read"; [ -z "$bad" ] && return 0
    WHY="$n logs, not read:$bad"; return 1
}

# --- a client, as one uses the panel -----------------------------------------------------------
x_client() {
    local r; r=$(php_panel 'require "modules/manage_clients/code/controller.ext.php";
        $pkg = (int) $zdbh->query("SELECT pk_id_pk FROM x_packages WHERE pk_reseller_fk = 1 AND pk_deleted_ts IS NULL ORDER BY pk_id_pk LIMIT 1")->fetchColumn();
        $ok = module_controller::ExecuteCreateClient(1, $argv[1], $pkg, 3, "VM Test", $argv[1] . "@example.invalid", "-", "-", "-", $argv[2], 0, "", "");
        echo $ok ? (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = " . $zdbh->quote($argv[1]) . " AND ac_deleted_ts IS NULL")->fetchColumn() : "failed";' "$U" "$UPW" | tail -1)
    [[ "$r" =~ ^[0-9]+$ ]] && [ "$r" -gt 0 ] || { WHY="not made: $r"; return 1; }
    ACC_ID=$r; WEBU="web$r"
    cu -o "$TMP/c.html" "https://$FQDN/"
    local t; t=$(grep -o 'name="csfr_token" value="[^"]*"' "$TMP/c.html" | head -1 | sed 's/.*value="//;s/"//')
    cu -o /dev/null -d "inUsername=$U&inPassword=$UPW&csfr_token=$t" "https://$FQDN/"
    cu -o "$TMP/c.html" "https://$FQDN/?module=domains"
    grep -q 'name="inDomain"' "$TMP/c.html" || { WHY="the login was refused"; return 1; }
    LOGGED=1
}
x_domains() {
    need LOGGED "client login" || return $?
    cu -o "$TMP/c.html" -d "inDomain=$DOM&inDestination=&inAutoHome=1&csfr_token=$(ctok domains)" "https://$FQDN/?module=domains&action=CreateDomain"
    DOMDIR=$(q "SELECT vh_directory_vc FROM x_vhosts WHERE vh_name_vc='$DOM' AND vh_acc_fk=$ACC_ID AND vh_deleted_ts IS NULL")
    [ -n "$DOMDIR" ] || { WHY="the domain was not added"; return 1; }
    cu -o "$TMP/c.html" -d "inSub=www2&inDomain=$DOM&inDestination=&inAutoHome=1&csfr_token=$(ctok sub_domains)" "https://$FQDN/?module=sub_domains&action=CreateSubDomain"
    cu -o "$TMP/c.html" -d "inDomain=park$RND.test&csfr_token=$(ctok parked_domains)" "https://$FQDN/?module=parked_domains&action=CreateParkedDomain"
    local n; n=$(q "SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk=$ACC_ID AND vh_deleted_ts IS NULL")
    [ "$n" -ge 3 ] || { WHY="only $n of domain, subdomain and parked domain were added"; return 1; }
    daemon_now || { WHY="the daemon run did not finish"; return 1; }
    SITE="$HOSTED/$U/public_html$DOMDIR"
    wait_for 60 test -S "$(sp php_fpm_socket_dir | sed 's#/$##')/$WEBU.sock" -o -S "/run/sentora-php/$WEBU.sock" || { WHY="no PHP pool for $WEBU"; return 1; }
}
x_ftp() {
    need SITE "domains" || return $?
    cu -o "$TMP/c.html" -d "inFTPUsername=ftp&inPassword=$FTPPW&inAccess=RW&inAutoHome=2&inDestination=public_html${DOMDIR}&inDomainDestination=&inSubmit=1&csfr_token=$(ctok ftp_management)" "https://$FQDN/?module=ftp_management&action=CreateFTP"
    FTPUSER="${U}_ftp"
    [ -n "$(q "SELECT userid FROM sentora_proftpd.ftpuser WHERE userid='$FTPUSER'")" ] || { FTPUSER=""; WHY="the FTP account was not made"; return 1; }
    local f="ftp://127.0.0.1" a=(-sSk --max-time 60 --ssl-reqd -u "$FTPUSER:$FTPPW")
    echo "first $RND" > "$TMP/one.txt"; echo "second $RND" > "$TMP/two.txt"
    curl "${a[@]}" -T "$TMP/one.txt" "$f/one.txt" 2> "$TMP/e" || { WHY="upload: $(tail -1 "$TMP/e")"; return 1; }
    curl "${a[@]}" -T "$TMP/two.txt" --ftp-create-dirs "$f/sub/dir/two.txt" 2> "$TMP/e" || { WHY="upload into new folders: $(tail -1 "$TMP/e")"; return 1; }
    curl "${a[@]}" "$f/" -Q "RNFR one.txt" -Q "RNTO renamed.txt" -o /dev/null 2> "$TMP/e" || { WHY="rename: $(tail -1 "$TMP/e")"; return 1; }
    # swap the two files' places
    curl "${a[@]}" "$f/" -Q "RNFR renamed.txt" -Q "RNTO sub/swap.tmp" -Q "RNFR sub/dir/two.txt" -Q "RNTO renamed.txt" -Q "RNFR sub/swap.tmp" -Q "RNTO sub/dir/two.txt" -o /dev/null 2> "$TMP/e" || { WHY="swap: $(tail -1 "$TMP/e")"; return 1; }
    [ "$(curl "${a[@]}" "$f/renamed.txt" 2> /dev/null)" = "second $RND" ] || { WHY="the swapped file has the wrong content"; return 1; }
    curl "${a[@]}" "$f/sub/" -l > "$TMP/list" 2> "$TMP/e" || { WHY="list: $(tail -1 "$TMP/e")"; return 1; }
    curl "${a[@]}" "$f/" -Q "DELE renamed.txt" -Q "DELE sub/dir/two.txt" -Q "RMD sub/dir" -Q "RMD sub" -o /dev/null 2> "$TMP/e" || { WHY="delete: $(tail -1 "$TMP/e")"; return 1; }
    [ ! -e "$SITE/renamed.txt" ] && [ ! -e "$SITE/sub" ] || { WHY="the deleted files are still there"; return 1; }
    [ "$(stat -c %U "$SITE")" = "$WEBU" ] || { WHY="the site folder is not owned by $WEBU"; return 1; }
}
x_mail_setup() {
    need SITE "domains" || return $?
    cu -o "$TMP/c.html" -d "inAddress=box&inDomain=$DOM&inPassword=$MAILPW&inCreate=1&csfr_token=$(ctok mailboxes)" "https://$FQDN/?module=mailboxes&action=AddMailbox"
    [ -n "$(q "SELECT username FROM sentora_postfix.mailbox WHERE username='box@$DOM'")" ] || { WHY="the mailbox was not made"; return 1; }
    MAILADDR="box@$DOM"
    cu -o "$TMP/c.html" -d "inAddress=alias&inDomain=$DOM&inDestination=$MAILADDR&csfr_token=$(ctok aliases)" "https://$FQDN/?module=aliases&action=CreateAlias"
    cu -o "$TMP/c.html" -d "inAddress=box&inDestinationName=fwd&inDestinationDomain=example.invalid&inKeepMessage=1&csfr_token=$(ctok forwarders)" "https://$FQDN/?module=forwarders&action=CreateForwarder"
    cu -o "$TMP/c.html" -d "inAddress=list&inDomain=$DOM&csfr_token=$(ctok distlists)" "https://$FQDN/?module=distlists&action=AddDistList"
    local n; n=$(q "SELECT COUNT(*) FROM sentora_postfix.alias WHERE address LIKE '%@$DOM'")
    [ "$n" -ge 2 ] || { WHY="only $n alias rows (alias, list)"; return 1; }
}
x_mail_flow() {
    need MAILADDR "mailbox" || return $?
    local s
    msg "$MAILADDR" "$MAILADDR" "vm submit587 $RND" "by 587" "$TMP/m1"; mailpy submit 587 "$TMP/m1" > "$TMP/e" 2>&1 || { WHY="587: $(cat "$TMP/e")"; return 1; }
    msg "$MAILADDR" "$MAILADDR" "vm submit465 $RND" "by 465" "$TMP/m2"; mailpy submit 465 "$TMP/m2" > "$TMP/e" 2>&1 || { WHY="465: $(cat "$TMP/e")"; return 1; }
    msg outside@example.com "alias@$DOM" "vm inbound $RND" "from outside to the alias" "$TMP/m3"; mailpy send 127.0.0.1 "$TMP/m3" "alias@$DOM" > "$TMP/e" 2>&1 || { WHY="25: $(cat "$TMP/e")"; return 1; }
    for s in "vm submit587 $RND" "vm submit465 $RND" "vm inbound $RND"; do arrived "$s" || { WHY="'$s' did not arrive"; return 1; }; done
    mailpy pop3 "vm inbound $RND" > "$TMP/e" 2>&1 || { WHY="POP3: $(cat "$TMP/e")"; return 1; }
    mailpy imap-append > "$TMP/e" 2>&1 || { WHY="IMAP folder and append: $(cat "$TMP/e")"; return 1; }
}
x_webmail() {
    need MAILADDR "mailbox" || return $?
    local j="$TMP/rc.jar" u="https://$FQDN/etc/apps/webmail/" t
    t=$(curl -sk --max-time 30 --resolve "$FQDN:443:127.0.0.1" -c "$j" -b "$j" "$u" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
    [ -n "$t" ] || { WHY="no Roundcube login page at $u"; return 1; }
    curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -c "$j" -b "$j" -o "$TMP/rc.html" --data-urlencode "_token=$t" -d "_task=login&_action=login&_timezone=UTC" \
        --data-urlencode "_user=$MAILADDR" --data-urlencode "_pass=$MAILPW" -L "$u?_task=login"
    grep -q 'rcmail' "$TMP/rc.html" && grep -qE '_task=mail|"task":"mail"|rcmail.set_env\(\{"task":"mail"' "$TMP/rc.html" || { WHY="Roundcube login did not reach the mail view: $(grep -o 'class="[^"]*error[^"]*"[^<]*<[^<]*' "$TMP/rc.html" | head -1)"; return 1; }
}
x_sogo() {
    need MAILADDR "mailbox" || return $?
    [ -x "$(sp sogo_bin)" ] || { WHY="SOGo is not installed"; return 2; }
    local base="https://$FQDN/SOGo/dav/$MAILADDR" c
    c=$(curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "{\"userName\":\"$MAILADDR\",\"password\":\"$MAILPW\",\"rememberLogin\":0}" "https://$FQDN/SOGo/connect")
    [ "$c" = 200 ] || { WHY="web login answered $c"; return 1; }
    printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//vm//EN\r\nBEGIN:VEVENT\r\nUID:vm-%s\r\nDTSTAMP:20261112T100000Z\r\nDTSTART:20261113T100000Z\r\nDTEND:20261113T110000Z\r\nSUMMARY:VM test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n' "$RND" > "$TMP/ev.ics"
    c=$(curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -u "$MAILADDR:$MAILPW" -o "$TMP/dav" -w '%{http_code}' -T "$TMP/ev.ics" -H 'Content-Type: text/calendar' "$base/Calendar/personal/vm-$RND.ics")
    [[ "$c" =~ ^20[14]$ ]] || { WHY="CalDAV PUT answered $c"; return 1; }
    printf 'BEGIN:VCARD\r\nVERSION:3.0\r\nUID:vmc-%s\r\nFN:VM Test\r\nEMAIL:vm@example.com\r\nEND:VCARD\r\n' "$RND" > "$TMP/c.vcf"
    c=$(curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -u "$MAILADDR:$MAILPW" -o "$TMP/dav" -w '%{http_code}' -T "$TMP/c.vcf" -H 'Content-Type: text/vcard' "$base/Contacts/personal/vmc-$RND.vcf")
    [[ "$c" =~ ^20[14]$ ]] || { WHY="CardDAV PUT answered $c"; return 1; }
    c=$(curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -u "$MAILADDR:$MAILPW" -o /dev/null -w '%{http_code}' -X PROPFIND -H 'Depth: 1' "$base/Calendar/personal/")
    [ "$c" = 207 ] || { WHY="CalDAV PROPFIND answered $c"; return 1; }
}
x_activesync() {
    need MAILADDR "mailbox" || return $?
    [ -x "$(sp sogo_bin)" ] || { WHY="SOGo is not installed"; return 2; }
    local u="https://$FQDN/Microsoft-Server-ActiveSync" c
    c=$(curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -u "$MAILADDR:$MAILPW" -X OPTIONS -D "$TMP/as.h" -o /dev/null -w '%{http_code}' "$u")
    [ "$c" = 200 ] && grep -qi 'MS-ASProtocolVersions' "$TMP/as.h" || { WHY="OPTIONS answered $c without the protocol versions"; return 1; }
    # FolderSync (WBXML: the folder list of a new phone, sync key 0)
    printf '\x03\x01\x6a\x00\x00\x07\x56\x52\x03\x30\x00\x01\x01' > "$TMP/fs.wbxml"
    c=$(curl -sk --max-time 90 --resolve "$FQDN:443:127.0.0.1" -u "$MAILADDR:$MAILPW" -o "$TMP/fs.out" -w '%{http_code}' \
        -H 'Content-Type: application/vnd.ms-sync.wbxml' -H 'MS-ASProtocolVersion: 14.1' -H 'User-Agent: VMtest/1.0' \
        --data-binary @"$TMP/fs.wbxml" "$u?Cmd=FolderSync&User=$MAILADDR&DeviceId=VMTEST$RND&DeviceType=VMtest")
    [ "$c" = 200 ] && [ -s "$TMP/fs.out" ] || { WHY="FolderSync answered $c"; return 1; }
}
x_site_php() {
    need SITE "domains" || return $?
    need MYPW "database" || return $?
    cat > "$SITE/vmtest.php" <<EOF_PHP
<?php
\$r = array();
\$r['user'] = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : getmyuid();
\$r['write'] = file_put_contents(__DIR__ . '/vmtest-written.txt', 'x') === 1;
@mkdir(__DIR__ . '/vmtest-dir'); \$r['mkdir'] = is_dir(__DIR__ . '/vmtest-dir') && rmdir(__DIR__ . '/vmtest-dir');
session_start(); \$_SESSION['n'] = 1; \$r['session'] = session_write_close();
\$r['tmp'] = file_put_contents(sys_get_temp_dir() . '/vmtest-' . getmypid(), 'x') === 1;
\$r['upload'] = !empty(\$_FILES['f']['tmp_name']) && move_uploaded_file(\$_FILES['f']['tmp_name'], __DIR__ . '/vmtest-upload.txt');
\$r['error_log'] = error_log('vmtest error_log line');
try { \$db = new PDO('mysql:host=localhost;dbname=$DBNAME', '$MYUSER', '$MYPW'); \$db->exec('CREATE TABLE IF NOT EXISTS t (v INT)'); \$r['mysql'] = \$db->exec('INSERT INTO t VALUES (1)') === 1; } catch (Exception \$e) { \$r['mysql'] = \$e->getMessage(); }
\$r['mail'] = mail('$MAILADDR', 'vm php mail $RND', 'sent by PHP mail()', 'From: $MAILADDR');
\$s = @fsockopen('127.0.0.1', 25, \$en, \$es, 5); \$r['smtp_socket'] = (bool) \$s;
\$r['gd'] = function_exists('imagecreate');
\$r['curl_https'] = function_exists('curl_init');
echo 'VMJSON' . json_encode(\$r);
EOF_PHP
    chown "$WEBU:$(id -gn "$WEBU")" "$SITE/vmtest.php"
    echo "upload $RND" > "$TMP/up.txt"
    local out; out=$(site -F "f=@$TMP/up.txt" "http://$DOM/vmtest.php" | grep -o 'VMJSON.*' | sed 's/^VMJSON//')
    [ -n "$out" ] || { WHY="no answer from the test page"; return 1; }
    echo "$out" > "$TMP/php.json"
    local bad; bad=$(php -r '$r = json_decode(file_get_contents($argv[1]), true); $b = array();
        foreach (array("write", "mkdir", "session", "tmp", "upload", "error_log", "mysql", "mail") as $k) { if ($r[$k] !== true) { $b[] = $k . "=" . var_export($r[$k], true); } }
        if ($r["user"] !== $argv[2]) { $b[] = "user=" . $r["user"]; } echo implode(" ", $b);' "$TMP/php.json" "$WEBU")
    [ -z "$bad" ] || { WHY="$bad"; return 1; }
    arrived "vm php mail $RND" || { WHY="PHP's mail() did not arrive"; return 1; }
    # https too, the site's error log written by Apache, and a missing page
    site -o /dev/null "https://$DOM/vmtest.php"; site -o /dev/null "http://$DOM/no-such-page-$RND"
}
x_mysql() {
    need LOGGED "client login" || return $?
    cu -o /dev/null -d "inDatabase=db&csfr_token=$(ctok mysql_databases)" "https://$FQDN/?module=mysql_databases&action=CreateDatabase"
    DBNAME="${U}_db"
    local id; id=$(q "SELECT my_id_pk FROM x_mysql_databases WHERE my_name_vc='$DBNAME' AND my_deleted_ts IS NULL")
    [ -n "$id" ] || { WHY="no database"; return 1; }
    cu -o "$TMP/c.html" -d "inUserName=usr&inDatabase=$id&inAccess=1&csfr_token=$(ctok mysql_users)" "https://$FQDN/?module=mysql_users&action=CreateUser"
    MYUSER="${U}_usr"; MYPW=$(grep -o '<code class="mu-pw">[^<]*</code>' "$TMP/c.html" | head -1 | sed 's/<[^>]*>//g')
    [ -n "$MYPW" ] || { WHY="no MySQL user password shown"; return 1; }
}
x_phpmyadmin() {
    need MYPW "database" || return $?
    local j="$TMP/pma.jar" u="https://$FQDN/etc/apps/phpmyadmin/" t
    t=$(curl -sk --max-time 30 --resolve "$FQDN:443:127.0.0.1" -c "$j" -b "$j" "$u" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
    [ -n "$t" ] || { WHY="no login page at $u"; return 1; }
    curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -c "$j" -b "$j" -L -o "$TMP/pma.html" --data-urlencode "token=$t" --data-urlencode "pma_username=$MYUSER" --data-urlencode "pma_password=$MYPW" -d "server=1" "$u"
    grep -q "$DBNAME" "$TMP/pma.html" || { WHY="the database is not listed after logging in"; return 1; }
}
x_dns() {
    need SITE "domains" || return $?
    command -v dig > /dev/null || { WHY="no dig"; return 2; }
    # DNS Manager: "Create these records" for the domain, then the daemon writes the zone and reloads BIND
    local vid; vid=$(q "SELECT vh_id_pk FROM x_vhosts WHERE vh_name_vc='$DOM' AND vh_deleted_ts IS NULL")
    cu -L -o "$TMP/c.html" -d "inDomain=$vid&inUserID=$ACC_ID&csfr_token=$(ctok dns_manager)" "https://$FQDN/?module=dns_manager&action=CreateDefaultRecords"
    [ "$(q "SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=$vid AND dn_deleted_ts IS NULL")" -gt 0 ] || { WHY="no records were made"; return 1; }
    daemon_now || { WHY="the daemon run did not finish"; return 1; }
    local a; wait_for 60 bash -c "dig +short @127.0.0.1 $DOM SOA | grep -q ." || { WHY="no SOA for $DOM (named: $(journalctl -u "$(sp bind_service)" -n 3 --no-pager 2> /dev/null | tail -2 | tr '\n' ' '))"; return 1; }
    a=$(dig +short @127.0.0.1 "www2.$DOM" A; dig +short @127.0.0.1 "mail.$DOM" A; dig +short @127.0.0.1 "$DOM" MX)
    [ -n "$a" ] || { WHY="no records for the subdomain, mail and MX"; return 1; }
}
x_cron() {
    need SITE "domains" || return $?
    printf '<?php file_put_contents(__DIR__ . "/vmtest-cron.txt", date("c"));\n' > "$SITE/vmcron.php"; chown "$WEBU:$(id -gn "$WEBU")" "$SITE/vmcron.php"
    cu -o "$TMP/c.html" --data-urlencode "inScript=${DOMDIR#/}/vmcron.php" -d "inDescription=vm+test&csfr_token=$(ctok cron)" --data-urlencode "inTiming=* * * * *" "https://$FQDN/?module=cron&action=CreateCron"
    [ -n "$(q "SELECT ct_id_pk FROM x_cronjobs WHERE ct_acc_fk=$ACC_ID AND ct_deleted_ts IS NULL")" ] || { WHY="the task was not saved"; return 1; }
    CRON=1   # (checked at the end: it starts after the next daemon run)
}
x_cron_ran() {
    need CRON "cron task" || return $?
    wait_for 420 test -s "$SITE/vmtest-cron.txt" || { WHY="the client's task did not run within 7 minutes"; return 1; }
    [ "$(stat -c %U "$SITE/vmtest-cron.txt")" = "$WEBU" ] || { WHY="it ran as $(stat -c %U "$SITE/vmtest-cron.txt")"; return 1; }
}
x_protect() {
    need SITE "domains" || return $?
    mkdir -p "$SITE/private"; echo "secret $RND" > "$SITE/private/index.html"; chown -R "$WEBU:$(id -gn "$WEBU")" "$SITE/private"
    cu -o "$TMP/c.html" -L --data-urlencode "inPath=${DOMDIR#/}/private" -d "inMessage=VM+test&csfr_token=$(ctok protected_directories)" "https://$FQDN/?module=protected_directories&action=CreateProtection"
    local id; id=$(q "SELECT x_htpasswd_file_id FROM x_htpasswd_file WHERE x_htpasswd_sentora_user_id=$ACC_ID AND x_htpasswd_file_deleted IS NULL ORDER BY 1 DESC LIMIT 1")
    [ -n "$id" ] || { WHY="the folder was not protected"; return 1; }
    cu -o "$TMP/c.html" -d "inId=$id&inUser=vm&inPassword=Pd-$RND-x9&csfr_token=$(ctok protected_directories)" "https://$FQDN/?module=protected_directories&action=AddUser"
    local c; c=$(site -o /dev/null -w '%{http_code}' "http://$DOM/private/")
    [ "$c" = 401 ] || { WHY="without a password: $c"; return 1; }
    site -u "vm:Pd-$RND-x9" "http://$DOM/private/" | grep -q "secret $RND" || { WHY="with the password: refused"; return 1; }
}
x_backup() {
    need SITE "domains" || return $?
    local d; d="$HOSTED/$U/backups"; local before; before=$(ls "$d" 2> /dev/null | wc -l)
    cu -o "$TMP/c.html" -d "csfr_token=$(ctok backupmgr)" "https://$FQDN/?module=backupmgr&action=BackupNow"
    page_ok "$TMP/c.html" || return 1
    wait_for 300 bash -c "[ \$(ls $d/*.zip 2> /dev/null | wc -l) -gt $before ]" || { WHY="no new backup in $d"; return 1; }
    BACKUPZIP=$(ls -t "$d"/*.zip | head -1)
    unzip -Z1 "$BACKUPZIP" | grep -q "vmtest.php" || { WHY="the backup has no web files"; return 1; }
}
x_restore() {
    need BACKUPZIP "backup" || return $?
    rm -f "$SITE/vmtest.php"
    "$PANEL/bin/sentora-restore" "$U" "$BACKUPZIP" --yes > "$TMP/restore.log" 2>&1 || { WHY="$(tail -1 "$TMP/restore.log")"; return 1; }
    site "http://$DOM/vmtest.php" | grep -q VMJSON || { WHY="the restored page does not run"; return 1; }
}
x_fail2ban() {
    command -v fail2ban-client > /dev/null || { WHY="fail2ban is not installed"; return 2; }
    ip addr add "$BANIP/32" dev lo 2> /dev/null
    local i t j="$TMP/bad.jar"
    for i in 1 2 3 4 5 6 7; do
        t=$(curl -sk --max-time 20 --interface "$BANIP" --resolve "$FQDN:443:$BANIP" -c "$j" -b "$j" "https://$FQDN/" | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
        curl -sk --max-time 20 --interface "$BANIP" --resolve "$FQDN:443:$BANIP" -c "$j" -b "$j" -o /dev/null -d "inUsername=nosuchuser&inPassword=wrong$i&csfr_token=$t" "https://$FQDN/"
        sleep 1
    done
    wait_for 60 bash -c "fail2ban-client status sentora-panel 2> /dev/null | grep -q '$BANIP'" || {
        WHY="sentora-panel did not ban $BANIP; the panel's log ($(ls -lZ /var/lib/sentora/sentora-auth.log 2>&1 | awk '{print $1, $3, $4, $5}')): $(tail -2 /var/lib/sentora/sentora-auth.log 2>&1 | tr '\n' '|'); fail2ban: $(fail2ban-client status sentora-panel 2>&1 | grep -E 'failed|banned' | tr -s ' \n' ' '); $(journalctl -u fail2ban -n 3 --no-pager 2> /dev/null | tail -2 | tr '\n' ' ')"
        ip addr del "$BANIP/32" dev lo 2> /dev/null; return 1; }
    fail2ban-client set sentora-panel unbanip "$BANIP" > /dev/null 2>&1
    ip addr del "$BANIP/32" dev lo 2> /dev/null
    # wrong mail passwords (the mail jails read Dovecot's and Postfix's logs)
    MAILADDR=${MAILADDR:-nobody@$DOM} MAILPW=wrong python3 -c '
import imaplib, ssl, os
c = ssl.create_default_context(); c.check_hostname = False; c.verify_mode = ssl.CERT_NONE
try: imaplib.IMAP4_SSL("127.0.0.1", 993, ssl_context=c).login(os.environ["MAILADDR"], "wrong")
except Exception: pass' 2> /dev/null
    return 0
}
x_hourly_daily() {
    php -q "$PANEL/bin/setso" --set daemon_hourrun 0 > /dev/null
    php -q "$PANEL/bin/setso" --set daemon_dayrun 0 > /dev/null
    daemon_now || { WHY="the daemon run did not finish"; return 1; }
    local f=/var/sentora/logs/daemon-panel-run.log
    cp "$f" "/var/sentora/logs/vm-daemon-hourly-$ROUND.log"
    if grep -qE "(^| )FAILED[:. ]|PHP (Fatal|Warning)" "$f"; then WHY="$(grep -m2 -E "(^| )FAILED[:. ]|PHP (Fatal|Warning)" "$f" | cut -c1-200 | tr '\n' ' ')"; return 1; fi
    grep -q "Daily" "$f" || grep -qi "day" "$f" || { WHY="no daily jobs in the run"; return 1; }
}
x_cron_daemon() { # the daemon as cron starts it (every 5 minutes)
    local f=/var/sentora/logs/daemon-last-run.log; local before; before=$(stat -c %Y "$f" 2> /dev/null || echo 0)
    wait_for 330 bash -c "[ \$(stat -c %Y $f 2> /dev/null || echo 0) -gt $before ] && grep -q 'Daemon run complete' $f" || { WHY="cron's daemon run did not finish within 5.5 minutes"; return 1; }
    grep -qE "PHP (Fatal|Warning)" "$f" && { WHY="$(grep -m1 -E 'PHP (Fatal|Warning)' "$f" | cut -c1-200)"; return 1; }
    return 0
}
x_logrotate() {
    systemctl start logrotate.service 2> /dev/null || { WHY="logrotate.service: $(systemctl status logrotate.service --no-pager 2>&1 | grep -m1 -iE 'error|fail' | cut -c1-160)"; return 1; }
}
x_services_restart() {
    local s bad=""
    for s in apache_sn php_fpm_service bind_service db_service dovecot_service ftp_service clamd_service clamav_milter_service spamd_service spamass_milter_service sogo_service cron_service; do
        local n; n=$(sp "$s" | sed "s/{v}/*/g"); [ -n "$n" ] || continue
        for u in $(systemctl list-units --all --plain --no-legend "$n.service" "$n" 2> /dev/null | awk '{print $1}' | grep '\.service$' | sort -u); do
            systemctl is-enabled -q "$u" 2> /dev/null || continue
            systemctl restart "$u" 2> /dev/null; sleep 2
            systemctl is-active -q "$u" || bad="$bad $u"
        done
    done
    [ -z "$bad" ] || { WHY="did not come back:$bad"; return 1; }
}
x_client_walk() { need LOGGED "client login" || return $?; walk c; }
x_remove_client() {
    need ACC_ID "client" || return $?
    php_panel 'require "modules/manage_clients/code/controller.ext.php"; module_controller::ExecuteDeleteClient((int) $argv[1], 1);' "$ACC_ID" > /dev/null
    [ -n "$DBNAME" ] && q "DROP DATABASE IF EXISTS \`$DBNAME\`"
    [ -n "$MYUSER" ] && q "DROP USER IF EXISTS '$MYUSER'@'%'; DROP USER IF EXISTS '$MYUSER'@'localhost'"
    daemon_now || { WHY="the daemon run did not finish"; return 1; }
    [ ! -e "$(sp apache_vhost_dir 2> /dev/null)/$DOM.conf" ] || true
    rm -rf "${HOSTED:?}/$U"
    id "$WEBU" > /dev/null 2>&1 && userdel "$WEBU" > /dev/null 2>&1
    return 0
}

echo "Exercise round '$ROUND' on $FQDN: client $U, domain $DOM ($(date '+%H:%M:%S'))"
ex "The administrator logs in" x_admin_login
ex "The administrator opens every page" x_admin_walk
ex "Run the daemon now (Sentora Config)" x_admin_daemon_button
ex "Run the check now (Server Monitor, hardening)" x_admin_hardening_button
ex "Check now (Panel Update)" x_admin_update_check
ex "Apache Status" x_apache_status
ex "System Log Viewer: every log" x_logviewer
ex "A client is made and logs in" x_client
ex "Domain, subdomain and parked domain (and a daemon run)" x_domains
ex "The client opens every page" x_client_walk
ex "Database and MySQL user" x_mysql
ex "FTP account: upload, folders, rename, swap, delete" x_ftp
ex "Mailbox, alias, forwarder and list" x_mail_setup
ex "Mail: send 587 and 465, receive 25, IMAP, POP3" x_mail_flow
ex "A website's PHP: files, session, upload, MySQL, mail()" x_site_php
ex "Cron Manager task" x_cron
ex "Webmail (Roundcube) login" x_webmail
ex "SOGo: web login, CalDAV, CardDAV" x_sogo
ex "ActiveSync: OPTIONS and FolderSync" x_activesync
ex "phpMyAdmin login" x_phpmyadmin
ex "DNS answers for the domains" x_dns
ex "Protected directory (password, 401, 200)" x_protect
ex "Backup Now (Backup Manager)" x_backup
ex "Restore from that backup" x_restore
ex "fail2ban bans password guessing" x_fail2ban
ex "Hourly and daily jobs (forced daemon run)" x_hourly_daily
ex "logrotate (its own service)" x_logrotate
ex "The client's cron task ran as the client" x_cron_ran
ex "The daemon as cron runs it" x_cron_daemon
ex "Restart every Sentora service" x_services_restart
ex "Remove the client (and a daemon run)" x_remove_client
rm -rf "$TMP" "$MYCNF"
echo "EXERCISE: $OKS OK, $FAILS FAIL, $SKIPS SKIP"
[ $FAILS -eq 0 ]
