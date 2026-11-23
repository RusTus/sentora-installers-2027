#!/bin/bash
# Web Hardening, bans and flood protection: the module (sentora-repo/php_hardening.zpp, or the folder
# given as $1) in a stand-in panel with a fake fail2ban. PH_PAGES=<folder> also saves the pages.
# Needs php (pdo_sqlite), unzip.   tests/php_hardening/bans.sh [module-folder]
set -u
T=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$T/../.." && pwd)
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
if [ $# -gt 0 ]; then cp -r "$1" "$W/php_hardening"
else unzip -q "$REPO/sentora-repo/php_hardening.zpp" -d "$W/php_hardening"; fi
mkdir -p "$W/t"
PH_W="$W/t" PH_DB="$W/test.sqlite" PH_MOD="$W/php_hardening" PH_PAGES="${PH_PAGES:-}" php "$T/bans.php"
