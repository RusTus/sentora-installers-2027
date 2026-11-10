#!/bin/bash
# Payment Plans tests: the module (sentora-repo/payment_plans.zpp, or the unzipped folder given as $1)
# in a stand-in panel (SQLite, small stand-ins for the core classes), against fake PayPal and Stripe
# services, with the pay link served by PHP's own web server. Needs php (pdo_sqlite, curl, sodium), unzip, git.
#   tests/payment_plans/run.sh [module-folder]
set -u
T=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$T/../.." && pwd)
W=$(mktemp -d)
rm -rf "$T/root/modules"; mkdir -p "$T/root/modules"
if [ $# -gt 0 ]; then cp -r "$1" "$T/root/modules/payment_plans"
else unzip -q "$REPO/sentora-repo/payment_plans.zpp" -d "$T/root/modules/payment_plans"; fi
# version 101, for the upgrade test
git -C "$REPO" show ff1c05dc:sentora-repo/payment_plans.zpp > "$W/101.zpp" && unzip -q "$W/101.zpp" -d "$W/101"
export PP_OLD_DIR="$W/101" PP_DB="$W/test.sqlite" FAKE_STATE="$W/fake.json"
export PP_FAKE=http://127.0.0.1:18081 FAKE_BASE=http://127.0.0.1:18081 PP_WEB=http://127.0.0.1:18082
php -S 127.0.0.1:18081 "$T/fake.php" >"$W/fake.log" 2>&1 &
F=$!
php -S 127.0.0.1:18082 -t "$T/root" >"$W/web.log" 2>&1 &
S=$!
sleep 1
php "$T/test.php"; R=$?
kill $F $S
rm -rf "$W" "$T/root/modules"
exit $R
