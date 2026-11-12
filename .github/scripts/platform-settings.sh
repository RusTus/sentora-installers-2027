#!/bin/bash
# platform-settings.sh: write platform-settings.json, the values each installer gives the settings
# of the Linux system (the core's dryden/sys/platform.class.php), read from the installers
# themselves: each installer's "Configuring Sentora" step runs here with a stand-in setso that
# only records what it is given. sentora-update downloads the file from the current release and
# hands it to sentora-platform-fill, which gives servers installed before a setting existed the
# value their installer sets today (instead of the Debian/Ubuntu default they got).
#
#   bash .github/scripts/platform-settings.sh [output file]     (run from the repository's root)
set -euo pipefail
OUT=${1:-platform-settings.json}
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
mkdir -p "$W/panel/bin"
# the stand-in setso: one record per --set (name and value, NUL-separated)
cat > "$W/panel/bin/setso" <<'EOF'
#!/bin/bash
[ "$1" = "--set" ] && printf '%s\0%s\0' "$2" "$3" >> "$SETTINGS_OUT"
exit 0
EOF
chmod 755 "$W/panel/bin/setso"

for inst in sentora2027-ub26.04 sentora2027-deb13 sentora2027-alma10 sentora2027-fedora; do
    s=$(grep -n '^ui_step "Configuring Sentora"' "$inst" | head -1 | cut -d: -f1)
    e=$(awk -v s="$s" 'NR > s && /^zadminpassword=/ {print NR; exit}' "$inst")
    [ -n "$s" ] && [ -n "$e" ] || { echo "$inst: its Configuring Sentora step was not found" >&2; exit 1; }
    {
        echo 'set -e'
        # the installer's own assignments of the variables the step uses (each one line, a constant)
        grep -E '^(BIND_USER|DB_SERVICE|FTP_CONF_PATH|HTTP_GROUP|HTTP_USER|MY_CNF_PATH|PHP_BUNDLE_LIBS|MODSEC_OFF)=' "$inst"
        echo "PANEL_PATH='$W'; PANEL_CONF=/etc/sentora/configs"
        sed -n "$((s + 1)),$((e - 1))p" "$inst"
    } > "$W/step.sh"
    SETTINGS_OUT="$W/$inst.rec" bash "$W/step.sh"
done

python3 - "$W" "$OUT" <<'EOF'
import json, sys
w, out = sys.argv[1], sys.argv[2]
systems = {}
for inst in ('sentora2027-ub26.04', 'sentora2027-deb13', 'sentora2027-alma10', 'sentora2027-fedora'):
    parts = open(w + '/' + inst + '.rec', 'rb').read().split(b'\0')[:-1]
    vals = {parts[i].decode(): parts[i + 1].decode() for i in range(0, len(parts), 2)}
    tag = vals.get('php_bundle_tag')
    if not tag:
        sys.exit(inst + ': sets no php_bundle_tag')
    systems[tag] = dict(sorted(vals.items()))
json.dump({'systems': systems}, open(out, 'w'), indent=1, sort_keys=True)
print(out + ': ' + ', '.join('%s %d' % (t, len(v)) for t, v in systems.items()))
EOF
