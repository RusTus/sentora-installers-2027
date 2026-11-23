#!/bin/bash
# zGod tests: the module (sentora-repo/zgod.zpp, or the folder given as $1) in a stand-in panel (SQLite, the
# panel's own columns): every view, the two actions and every refusal, an attack test (hostile names, injection
# tries, a missing form key, a non-administrator), the daily report and the install step.
# ZG_PAGES=<folder> also saves the rendered pages there (for screenshots). Needs php (pdo_sqlite), unzip.
#   tests/zgod/run.sh [module-folder]
set -u
T=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$T/../.." && pwd)
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
if [ $# -gt 0 ]; then cp -r "$1" "$W/zgod"
else unzip -q "$REPO/sentora-repo/zgod.zpp" -d "$W/zgod"; fi
ZG_DB="$W/test.sqlite" ZG_MOD="$W/zgod" ZG_PAGES="${ZG_PAGES:-}" php "$T/test.php"
