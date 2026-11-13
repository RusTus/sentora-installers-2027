#!/usr/bin/env bash
# Speed test: on an installed panel (run as root on it; the Speed test workflow runs it in the install
# test's container), how long each page takes and what it asks the database. A test administrator
# logs in and opens every module's page 5 times; for each page: the median time, its size, and the
# database queries it made (MySQL's general log, as a table, only while the test runs): how many, and
# how many were the same query again. Then the queries asked most often over all pages, whether the
# panel's PHP has OPcache on, and how the style sheets and scripts are sent (compressed, cached).
# Nothing is changed for good: the test administrator is removed and the general log switched off.
#   speed-test.sh [results folder]      (default /root/speed-test)
set -uo pipefail
OUT=${1:-/root/speed-test}; mkdir -p "$OUT"
PANEL=/etc/sentora/panel
sp() { "$PANEL/bin/setso" --get "$1" 2> /dev/null; }
DBC=$(sp db_client); DBC=${DBC:-mysql}
val() { grep -o "\$$1 = '[^']*'" "$PANEL/cnf/db.php" | head -1 | cut -d"'" -f2; }
MYCNF=$(mktemp); chmod 600 "$MYCNF"
printf '[client]\nuser=%s\npassword=%s\n' "$(val user)" "$(val pass)" > "$MYCNF"
q() { "$DBC" --defaults-extra-file="$MYCNF" -N -B -e "$1" "${2:-sentora_core}" 2> /dev/null; }
# (the panel's database user is MySQL's root: it may switch the general log on and read it)
qr() { "$DBC" --defaults-extra-file="$MYCNF" -N -B -e "$1" mysql 2> /dev/null; }
FQDN=$(q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='sentora_domain'")
TMP=$(mktemp -d); CJ="$TMP/cookies"
pcurl() { curl -sk --max-time 60 --resolve "$FQDN:443:127.0.0.1" -c "$CJ" -b "$CJ" "$@"; }
R="$OUT/report.txt"; : > "$R"
say() { echo "$*" | tee -a "$R"; }

# the daemon and other jobs would add their queries to the log: stopped while the test runs
for s in cron crond; do systemctl stop "$s" 2> /dev/null; done

U="speedtest$RANDOM"; UPW="Sp33d-$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')"
ID=$(cd "$PANEL" && php -r 'chdir("'"$PANEL"'"); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php"; $_SESSION["zpuid"] = 1; $GLOBALS["controller"] = new runtime_controller();
    require "modules/manage_clients/code/controller.ext.php";
    $pkg = (int) $zdbh->query("SELECT pk_id_pk FROM x_packages WHERE pk_reseller_fk = 1 AND pk_deleted_ts IS NULL ORDER BY pk_id_pk LIMIT 1")->fetchColumn();
    $ok = module_controller::ExecuteCreateClient(1, $argv[1], $pkg, 1, "Speed Test", $argv[1] . "@example.invalid", "-", "-", "-", $argv[2], 0, "", "");
    echo $ok ? (int) $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc = " . $zdbh->quote($argv[1]) . " AND ac_deleted_ts IS NULL")->fetchColumn() : "failed";' "$U" "$UPW" 2>&1 | tail -1)
[[ "$ID" =~ ^[0-9]+$ ]] || { say "could not make the test administrator: $ID"; exit 1; }
cleanup() {
    qr "SET GLOBAL general_log = 'OFF'"
    (cd "$PANEL" && php -r 'chdir("'"$PANEL"'"); require "dryden/loader.inc.php"; require "cnf/db.php"; require "inc/dbc.inc.php"; $_SESSION["zpuid"] = 1; $GLOBALS["controller"] = new runtime_controller(); require "modules/manage_clients/code/controller.ext.php"; module_controller::ExecuteDeleteClient((int) $argv[1], 1);' "$ID" > /dev/null 2>&1)
    for s in cron crond; do systemctl start "$s" 2> /dev/null; done
    rm -rf "$TMP" "$MYCNF"
}
trap cleanup EXIT

t0=$(date +%s%N)
pcurl -o "$TMP/p.html" "https://$FQDN/"
t=$(grep -o 'name="csfr_token" value="[^"]*"' "$TMP/p.html" | head -1 | sed 's/.*value="//;s/"//')
LOGIN=$(pcurl -o /dev/null -w '%{time_total}' -d "inUsername=$U&inPassword=$UPW&csfr_token=$t" "https://$FQDN/")
pcurl -o "$TMP/p.html" "https://$FQDN/"
grep -q 'name="inUsername"' "$TMP/p.html" && { say "the test administrator could not log in"; exit 1; }

say "Speed test: $(sed -n 's/^PRETTY_NAME="\(.*\)"/\1/p' /etc/os-release), Sentora $(cat "$PANEL/VERSION" 2> /dev/null), $(nproc) CPUs, $(date -u '+%F %H:%M UTC')"
say "Logging in took ${LOGIN}s (the password check is meant to be slow)."
say ""

# the general log as a table, so each page's queries can be counted
qr "SET GLOBAL log_output = 'TABLE'; SET GLOBAL general_log = 'ON'"
PAGES=("" $(q "SELECT mo_folder_vc FROM x_modules WHERE mo_enabled_en = 'true' AND mo_type_en IN ('user','modadmin') ORDER BY mo_folder_vc"))
printf '%-28s %8s %8s %8s %8s %6s\n' PAGE "MEDIAN" "FIRST" "KB" QUERIES SAME | tee -a "$R"
: > "$TMP/all-queries"
for m in "${PAGES[@]}"; do
    url="https://$FQDN/"; [ -n "$m" ] && url="https://$FQDN/?module=$m"
    times=()
    first=''
    for i in 1 2 3 4 5; do
        qr "TRUNCATE TABLE general_log"
        o=$(pcurl -o "$TMP/page.html" -w '%{time_total} %{size_download} %{http_code}' "$url")
        [ -z "$first" ] && first=${o%% *}
        times+=("${o%% *}")
        if [ $i -eq 5 ]; then
            # the queries of this one request: Query and Execute, without the log's own commands
            qr "SELECT argument FROM general_log WHERE command_type IN ('Query','Execute') AND argument NOT LIKE '%general_log%'" | sed 's/[0-9]\{4,\}/N/g' > "$TMP/q"
            n=$(wc -l < "$TMP/q"); same=$(( n - $(sort -u "$TMP/q" | wc -l) ))
            sed "s|^|${m:-dashboard}	|" "$TMP/q" >> "$TMP/all-queries"
            kb=$(( $(echo "$o" | cut -d' ' -f2) / 1024 )); code=$(echo "$o" | cut -d' ' -f3)
        fi
    done
    med=$(printf '%s\n' "${times[@]}" | sort -n | sed -n 3p)
    printf '%-28s %7.3fs %7.3fs %8s %8s %6s%s\n' "${m:-dashboard}" "$med" "$first" "$kb" "$n" "$same" "$([ "$code" = 200 ] || echo "  (HTTP $code)")" | tee -a "$R"
done
qr "SET GLOBAL general_log = 'OFF'; TRUNCATE TABLE general_log"
cp "$TMP/all-queries" "$OUT/queries.tsv"

say ""
say "Queries asked most often over all pages (each page counted once; N = a number):"
cut -f2- "$TMP/all-queries" | sort | uniq -c | sort -rn | head -25 | cut -c1-200 | tee -a "$R"
say ""
say "The same query several times in one page (page, times, query):"
sort "$TMP/all-queries" | uniq -c | awk '$1 > 2' | sort -rn | head -25 | cut -c1-200 | tee -a "$R"

say ""
say "The panel's PHP:"
probe="$PANEL/speedprobe$RANDOM.php"
printf '<?php header("Content-Type: text/plain"); echo PHP_VERSION, " ", PHP_SAPI, "\\n"; $s = function_exists("opcache_get_status") ? @opcache_get_status(false) : false; echo "opcache: ", $s ? ("on, " . round($s["memory_usage"]["used_memory"] / 1048576) . " MB used, hit rate " . round($s["opcache_statistics"]["opcache_hit_rate"], 1) . "%%, " . $s["opcache_statistics"]["num_cached_scripts"] . " scripts") : "off", "\\n"; foreach (array("opcache.memory_consumption", "opcache.max_accelerated_files", "opcache.validate_timestamps", "opcache.revalidate_freq", "opcache.jit", "realpath_cache_size", "session.save_handler", "session.save_path") as $k) echo $k, " = ", ini_get($k), "\\n"; echo "apcu: ", function_exists("apcu_fetch") && ini_get("apc.enabled") ? "on" : "no", "; memcached: ", class_exists("Memcached") ? "extension present" : "no", "; redis: ", class_exists("Redis") ? "extension present" : "no", "\\n";' > "$probe"
chmod 644 "$probe"; chown "$(stat -c %U "$PANEL/index.php")" "$probe" 2> /dev/null
pcurl "https://$FQDN/$(basename "$probe")" | sed 's/^/  /' | tee -a "$R"
rm -f "$probe"

say ""
pcurl -o "$TMP/p.html" "https://$FQDN/"
say "Style sheets and scripts as a browser gets them (compressed? kept by the browser?):"
for f in $(grep -o '\(href\|src\)="[^"]*\.\(css\|js\)[^"]*"' "$TMP/p.html" | cut -d'"' -f2 | grep -v '^https\?://' | sort -u | head -12); do
    h=$(pcurl -o /dev/null -D - -H 'Accept-Encoding: gzip, br' "https://$FQDN/${f#/}" 2> /dev/null | tr -d '\r')
    printf '  %-60s %s | %s | %s\n' "${f:0:60}" "$(echo "$h" | sed -n 's/^[Cc]ontent-[Ee]ncoding: //p' | head -1 | sed 's/^$/-/;s/^/enc /')" "$(echo "$h" | sed -n 's/^[Cc]ache-[Cc]ontrol: //p' | head -1)" "$(echo "$h" | grep -ci '^expires:') expires" | tee -a "$R"
done
say ""
say "The dashboard page asks the browser for $(grep -o '\(href\|src\)="[^"]*\.\(css\|js\|png\|svg\|woff2\?\)[^"]*"' "$TMP/p.html" | wc -l) files."

say ""
say "The database server (after the test; only what usually matters):"
st() { qr "SHOW GLOBAL STATUS LIKE '$1'" | awk '{print $2}'; }
va() { qr "SHOW GLOBAL VARIABLES LIKE '$1'" | awk '{print $2}'; }
say "  $(qr 'SELECT VERSION()'), up $(st Uptime) s"
bp=$(va innodb_buffer_pool_size); data=$(qr "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE engine = 'InnoDB'")
say "  InnoDB buffer pool: $(( bp / 1048576 )) MB for $(( data / 1048576 )) MB of InnoDB data (all of it fits: $([ "$bp" -gt "$data" ] && echo yes || echo NO))"
rr=$(st Innodb_buffer_pool_read_requests); rd=$(st Innodb_buffer_pool_reads)
[ "${rr:-0}" -gt 0 ] && say "  reads served from memory: $(awk -v a="$rr" -v b="$rd" 'BEGIN { printf "%.2f", 100 - 100 * b / a }')% ($rd of $rr read from disk)"
say "  temporary tables: $(st Created_tmp_tables), on disk $(st Created_tmp_disk_tables) (tmp_table_size $(( $(va tmp_table_size) / 1048576 )) MB)"
say "  tables opened: $(st Opened_tables), open now $(st Open_tables), table_open_cache $(va table_open_cache)"
say "  connections: $(st Connections), threads made $(st Threads_created), thread_cache_size $(va thread_cache_size), max used $(st Max_used_connections) of $(va max_connections)"
say "  slow queries: $(st Slow_queries) (long_query_time $(va long_query_time) s), full table scans (Select_scan): $(st Select_scan), joins without index: $(st Select_full_join)"
say "  sort merge passes: $(st Sort_merge_passes) (sort_buffer_size $(( $(va sort_buffer_size) / 1024 )) KB)"
say "  memory on this server: $(free -m | awk '/^Mem:/ {print $2 " MB, " $7 " MB available"}')"
say ""
say "Tables of the panel without an index for what is looked up (queries of this test that read every row):"
qr "SELECT DIGEST_TEXT, COUNT_STAR, SUM_NO_INDEX_USED FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = 'sentora_core' AND SUM_NO_INDEX_USED > 0 ORDER BY SUM_NO_INDEX_USED DESC LIMIT 15" | cut -c1-220 | sed 's/^/  /' | tee -a "$R"
