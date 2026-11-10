#!/bin/bash
# PHP Hardening tests: the module (sentora-repo/php_hardening.zpp, or the unzipped folder given as $1)
# in a stand-in panel (SQLite), with the real Snuffleupagus on this machine's PHP (fake PHP-FPMs are
# that PHP with their own settings folders) and a fake systemctl, journalctl and GitHub.
# Needs php (pdo_sqlite), unzip; and either PH_SO=<a snuffleupagus.so built for this PHP>, or php-dev,
# git and a compiler to build one (the version and commit of preconf/php-build/snuffleupagus.sh).
#   tests/php_hardening/run.sh [module-folder]
set -u
T=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$T/../.." && pwd)
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
if [ $# -gt 0 ]; then cp -r "$1" "$W/php_hardening"
else unzip -q "$REPO/sentora-repo/php_hardening.zpp" -d "$W/php_hardening"; fi
if [ -z "${PH_SO:-}" ]; then
    . "$REPO/preconf/php-build/snuffleupagus.sh"
    echo "Building Snuffleupagus $SP_VERSION for $(php -r 'echo PHP_VERSION;')..."
    git clone -q --depth 1 --branch "v$SP_VERSION" https://github.com/jvoisin/snuffleupagus.git "$W/sp" || exit 1
    [ "$(git -C "$W/sp" rev-parse HEAD)" = "$SP_COMMIT" ] || { echo "not the expected commit"; exit 1; }
    (cd "$W/sp/src" && phpize >/dev/null && ./configure -q --enable-snuffleupagus >/dev/null && make -s -j"$(nproc)" >/dev/null) || exit 1
    PH_SO="$W/sp/src/modules/snuffleupagus.so"
fi
mkdir -p "$W/t"
PH_W="$W/t" PH_DB="$W/test.sqlite" PH_SO="$PH_SO" PH_MOD="$W/php_hardening" php "$T/test.php"
