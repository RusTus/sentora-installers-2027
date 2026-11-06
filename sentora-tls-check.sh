#!/bin/bash
# sentora-tls-check.sh - scan every TLS service of a Sentora server with testssl.sh
# and write plain-text reports.
#
# Usage (as root, on the server):
#   bash sentora-tls-check.sh [host name] [quick]
#
#   host name  the name the certificate is for (default: this server's full name)
#   quick      only the panel's HTTPS (443), not the mail and FTP ports
#
# Output: a folder tls-report-<host>-<date>/ with
#   findings.txt       every CRITICAL, HIGH, MEDIUM, LOW and WARN finding, most severe first
#   <service>.txt      the full testssl.sh report for each service, as plain text
#   <service>.json     the same results in JSON
#
# testssl.sh is looked for next to this script, in /opt/testssl, or unpacked from
# testssl_sh-3_2_5.zip (next to this script, or downloaded from the installers release).

set -u
TESTSSL_ZIP_URL="https://github.com/RusTus/sentora-installers-2027/releases/download/current/testssl_sh-3_2_5.zip"
# SHA-256 of testssl_sh-3_2_5.zip; a download is checked against this before it is used.
TESTSSL_ZIP_SHA256="79ed1af15dcc0c101f8c7615cce085be1d903cac9c63f15f83a8ce4f412cdbd4"
TESTSSL_DIR="/opt/testssl"

HOST="${1:-$(hostname -f 2> /dev/null || hostname)}"
MODE="${2:-full}"
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="$PWD/tls-report-$HOST-$(date +%Y%m%d-%H%M)"

say() { echo -e "\e[1;34m==>\e[0m $*"; }
warn() { echo -e "\e[1;33mWARNING:\e[0m $*"; }
die() { echo -e "\e[1;31mERROR:\e[0m $*"; exit 1; }

[ "$(id -u)" -eq 0 ] || warn "not running as root: missing tools can not be installed"

# --- tools testssl.sh needs ------------------------------------------------------
need=()
command -v hexdump > /dev/null || need+=(bsdextrautils)
command -v dig > /dev/null || command -v host > /dev/null || need+=(bind9-dnsutils)
command -v ps > /dev/null || need+=(procps)
command -v openssl > /dev/null || need+=(openssl)
command -v python3 > /dev/null || need+=(python3)
# socat lets testssl.sh run its STARTTLS command-injection check (ftp/smtp/submission);
# without it that check is skipped rather than actually tested.
command -v socat > /dev/null || need+=(socat)
if [ ${#need[@]} -gt 0 ]; then
    say "Installing the tools testssl.sh needs: ${need[*]}"
    apt-get -qq update && apt-get -y -qq install "${need[@]}" > /dev/null || die "could not install: ${need[*]}"
fi

# --- testssl.sh itself -----------------------------------------------------------
TESTSSL=""
for t in "$HERE/testssl.sh" "$HERE"/testssl.sh-*/testssl.sh "$TESTSSL_DIR"/testssl.sh "$TESTSSL_DIR"/*/testssl.sh; do
    [ -f "$t" ] && { TESTSSL="$t"; break; }
done
if [ -z "$TESTSSL" ]; then
    ZIP=""
    for z in "$HERE"/testssl_sh-*.zip "$HERE"/testssl*.zip; do
        [ -f "$z" ] && { ZIP="$z"; break; }
    done
    if [ -z "$ZIP" ]; then
        say "Downloading testssl.sh from $TESTSSL_ZIP_URL"
        ZIP="$(mktemp --suffix=.zip)"
        wget -q -O "$ZIP" "$TESTSSL_ZIP_URL" || curl -fsSL -o "$ZIP" "$TESTSSL_ZIP_URL" \
            || die "could not download testssl.sh: put testssl_sh-3_2_5.zip next to this script, or check the installers release at $TESTSSL_ZIP_URL"
        have="$(sha256sum "$ZIP" | cut -d' ' -f1)"
        [ "$have" = "$TESTSSL_ZIP_SHA256" ] \
            || die "testssl.sh download failed its checksum (got $have, expected $TESTSSL_ZIP_SHA256); refusing to use it"
    fi
    if ! command -v unzip > /dev/null; then
        apt-get -qq update && apt-get -y -qq install unzip > /dev/null || die "could not install unzip"
    fi
    say "Unpacking testssl.sh into $TESTSSL_DIR"
    mkdir -p "$TESTSSL_DIR" && unzip -qo "$ZIP" -d "$TESTSSL_DIR" || die "could not unpack $ZIP"
    TESTSSL="$(ls "$TESTSSL_DIR"/*/testssl.sh "$TESTSSL_DIR"/testssl.sh 2> /dev/null | head -1)"
    [ -n "$TESTSSL" ] || die "testssl.sh is not in $ZIP"
fi
chmod +x "$TESTSSL"
VERSION=$(grep -m1 -oE 'VERSION="[^"]+"' "$TESTSSL" | cut -d'"' -f2)
say "Using testssl.sh ${VERSION:-(version unknown)} ($TESTSSL)"

# --- what to scan: name, port, extra testssl.sh options --------------------------
SERVICES=("https 443 ")
if [ "$MODE" != "quick" ]; then
    SERVICES+=(
        "smtp-submission-tls 465 "
        "smtp-submission 587 --starttls smtp"
        "smtp 25 --starttls smtp"
        "imaps 993 "
        "pop3s 995 "
        "ftps 21 --starttls ftp"
    )
fi

mkdir -p "$OUT" || die "can not write $OUT"
say "Scanning $HOST, reports in $OUT"
echo "(a full scan takes about 3 to 5 minutes per service)"

for s in "${SERVICES[@]}"; do
    read -r name port extra <<< "$s"
    # Skip ports nothing listens on
    if ! timeout 5 bash -c "exec 3<>/dev/tcp/$HOST/$port" 2> /dev/null; then
        warn "$name (port $port): nothing answers, skipped"
        continue
    fi
    say "$name (port $port)"
    # shellcheck disable=SC2086  # $extra is several options on purpose
    "$TESTSSL" --color 0 --warnings off --quiet \
        --logfile "$OUT/$name.txt" --jsonfile "$OUT/$name.json" \
        $extra "$HOST:$port" > /dev/null 2>&1
    if [ -s "$OUT/$name.json" ]; then
        grade=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(next((x.get('finding','') for x in d if isinstance(x, dict) and x.get('id') == 'overall_grade'), ''))" "$OUT/$name.json" 2> /dev/null)
        echo "    done${grade:+, grade $grade}"
    else
        warn "$name: testssl.sh wrote no results (see $OUT/$name.txt)"
    fi
done

# --- one short findings file, most severe first ----------------------------------
python3 - "$OUT" "$HOST" > "$OUT/findings.txt" << 'EOF'
import glob, json, os, sys
out, host = sys.argv[1], sys.argv[2]
order = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'WARN']
print(f"TLS findings for {host}")
print("=" * 78)
rows, grades = [], {}
for path in sorted(glob.glob(os.path.join(out, '*.json'))):
    service = os.path.basename(path)[:-5]
    try:
        data = json.load(open(path))
    except Exception:
        continue
    if isinstance(data, dict):
        data = data.get('scanResult', [])
    for x in data:
        if not isinstance(x, dict):
            continue
        if x.get('id') == 'overall_grade':
            grades[service] = x.get('finding', '')
        if x.get('severity') in order:
            rows.append((order.index(x['severity']), service, x['severity'], x.get('id', ''), x.get('finding', '')))
if grades:
    print("Grades: " + ", ".join(f"{s} {g}" for s, g in grades.items()))
    print()
if not rows:
    print("No CRITICAL, HIGH, MEDIUM, LOW or WARN findings.")
for _, service, sev, ident, finding in sorted(rows):
    print(f"{sev:9} {service:20} {ident:30} {finding}")
print()
print("Full reports: " + ", ".join(sorted(os.path.basename(p) for p in glob.glob(os.path.join(out, '*.txt')) if not p.endswith('findings.txt'))))
EOF

echo
cat "$OUT/findings.txt"
echo
say "Done. Send the folder $OUT (or at least findings.txt) for review."
