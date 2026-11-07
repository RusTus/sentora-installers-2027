#!/bin/bash
#
# Update an extra PHP version on a running Sentora server with a newer bundle
# made by build-sentora-php.sh. The version's settings (etc/) are kept.
#
# Usage, as root on the Sentora server:
#     bash update-sentora-php.sh 8.3                       # from the GitHub release
#     bash update-sentora-php.sh 8.3 /root/sentora-php-8.3-ubuntu26.04-amd64.tar.gz
#
# The bundle comes from the "current" release of RusTus/sentora-installers-2027
# (TAG=latest in the environment takes the newest release, TAG=<tag> another one) and
# is checked against the SHA-256 GitHub lists for it.
set -euo pipefail

MM="${1:-}"
FILE="${2:-}"
REPO="RusTus/sentora-installers-2027"
TAG="${TAG:-current}"
if [[ ! "$MM" =~ ^[78]\.[0-9]+$ ]]; then
    echo "Usage: $0 <major.minor, e.g. 8.3 or 7.1> [bundle file]"
    exit 2
fi
NAME="sentora-php-$MM-ubuntu26.04-amd64.tar.gz"
P="/opt/sentora-php/$MM"
SERVICE="sentora-php$MM-fpm"
[ -d "$P/etc" ] || { echo "PHP $MM is not installed in $P (to add it: sentora-php-install $MM)."; exit 1; }

WORK=$(mktemp -d /tmp/sentora-php-update.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
if [ -z "$FILE" ]; then
    FILE="$WORK/$NAME"
    if [ "$TAG" = "latest" ]; then
        API="https://api.github.com/repos/$REPO/releases?per_page=1"
    else
        API="https://api.github.com/repos/$REPO/releases/tags/$TAG"
    fi
    # the release's tag and the bundle's SHA-256 (empty when the API does not answer)
    INFO=$(wget -q -T 20 -O - --header="Accept: application/vnd.github+json" "$API" 2> /dev/null | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (isset($d[0])) { $d = $d[0]; }
        if (!is_array($d) || empty($d["tag_name"])) { exit(1); }
        $sum = "-";
        foreach ((array) ($d["assets"] ?? array()) as $a) {
            if (($a["name"] ?? "") === $argv[1] && !empty($a["digest"])) { $sum = preg_replace("/^sha256:/", "", $a["digest"]); }
        }
        echo $d["tag_name"], " ", $sum;
    ' "$NAME" 2> /dev/null) || INFO=""
    RTAG="${INFO%% *}"; WANT="${INFO#* }"
    if [ -z "$INFO" ]; then
        RTAG="$TAG"; WANT="-"
        if [ "$TAG" = "latest" ]; then   # the release feed lists pre-releases too
            RTAG=$(wget -q -T 20 -O - "https://github.com/$REPO/releases.atom" 2> /dev/null \
                | grep -o 'releases/tag/[^"<]*' | head -1 | sed 's|releases/tag/||') || true
            [ -n "$RTAG" ] || { echo "Could not find the newest release of $REPO."; exit 1; }
        fi
        echo "GitHub's release API did not answer: the download is not checked."
    fi
    echo "Downloading $NAME from release $RTAG of $REPO"
    wget -q -O "$FILE" "https://github.com/$REPO/releases/download/$RTAG/$NAME" \
        || { echo "Could not download $NAME from release $RTAG."; exit 1; }
    if [ "$WANT" != "-" ]; then
        [ "$(sha256sum "$FILE" | cut -d' ' -f1)" = "$WANT" ] \
            || { echo "$NAME does not match the SHA-256 GitHub lists for it, nothing changed."; exit 1; }
        echo "SHA-256 matches the release."
    fi
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
# a version that is switched off (PHP 7.1 until a move needs it) stays stopped
WAS_ON=1; systemctl is-enabled --quiet "$SERVICE" 2> /dev/null || WAS_ON=0
if [ "$WAS_ON" = 0 ] || systemctl start "$SERVICE"; then
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
