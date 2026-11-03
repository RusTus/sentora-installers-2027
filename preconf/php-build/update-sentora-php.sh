#!/bin/bash
#
# Update an extra PHP version on a running Sentora server with a newer bundle
# made by build-sentora-php.sh. The version's settings (etc/) are kept.
#
# Usage, as root on the Sentora server:
#     bash update-sentora-php.sh 8.3                       # from hembackup1
#     bash update-sentora-php.sh 8.3 /root/sentora-php-8.3-ubuntu26.04-amd64.tar.gz
set -euo pipefail

MM="${1:-}"
FILE="${2:-}"
DOWNLOAD_URL="http://hembackup1.hemsaw.local/installers"
if [[ ! "$MM" =~ ^8\.[0-9]+$ ]]; then
    echo "Usage: $0 <major.minor, e.g. 8.3> [bundle file]"
    exit 2
fi
NAME="sentora-php-$MM-ubuntu26.04-amd64.tar.gz"
P="/opt/sentora-php/$MM"
SERVICE="sentora-php$MM-fpm"
[ -d "$P/etc" ] || { echo "PHP $MM is not installed in $P."; exit 1; }

WORK=$(mktemp -d /tmp/sentora-php-update.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
if [ -z "$FILE" ]; then
    FILE="$WORK/$NAME"
    wget -nv -O "$FILE" "$DOWNLOAD_URL/$NAME"
fi
tar -tzf "$FILE" "opt/sentora-php/$MM/sbin/php-fpm" > /dev/null || { echo "$FILE is not a PHP $MM bundle."; exit 1; }

OLD=$("$P/sbin/php-fpm" -v | head -1)
# Unpack beside the live copy, check it runs, then swap in bin/lib/sbin/include
tar -xzmf "$FILE" -C "$WORK"
NEW="$WORK/opt/sentora-php/$MM"
"$NEW/sbin/php-fpm" -v > /dev/null || { echo "The new PHP $MM does not run on this server, nothing changed."; exit 1; }
"$NEW/sbin/php-fpm" --test --fpm-config "$P/etc/php-fpm.conf" -c "$P/etc/php.ini" > /dev/null 2>&1 \
    || { echo "The new PHP $MM rejects the current settings, nothing changed."; exit 1; }

systemctl stop "$SERVICE" 2> /dev/null || true
for d in bin sbin lib include php; do
    [ -d "$NEW/$d" ] || continue
    rm -rf "${P:?}/${d:?}.old"; [ -d "$P/$d" ] && mv "$P/$d" "$P/$d.old"
    mv "$NEW/$d" "$P/$d"
done
cp "$P/BUILD-INFO" "$WORK/BUILD-INFO.old" 2> /dev/null || true
cp "$NEW/BUILD-INFO" "$P/BUILD-INFO" 2> /dev/null || true
if systemctl start "$SERVICE"; then
    for d in bin sbin lib include php; do rm -rf "${P:?}/${d:?}.old"; done
    echo "Updated: $OLD  ->  $("$P/sbin/php-fpm" -v | head -1)"
else
    echo "PHP $MM did not start with the new files, putting the old ones back."
    for d in bin sbin lib include php; do
        [ -d "$P/$d.old" ] && { rm -rf "${P:?}/${d:?}"; mv "$P/$d.old" "$P/$d"; }
    done
    cp "$WORK/BUILD-INFO.old" "$P/BUILD-INFO" 2> /dev/null || true
    systemctl start "$SERVICE"
    exit 1
fi
