#!/bin/bash
#
# Build an extra PHP version for Sentora on Ubuntu 26.04 (amd64) and package it
# the way sentora2027-ub26.04 expects:
#     sentora-php-<major.minor>-ubuntu26.04-amd64.tar.gz
#
# Usage (as root, on an Ubuntu 26.04 machine; a spare VM is best):
#     bash build-sentora-php.sh 8.3.37                      # downloads from php.net
#     bash build-sentora-php.sh 8.3.37 php-8.3.37.tar.xz    # uses a file you downloaded
#
# Then attach the .tar.gz to the sentora-installers-2027 GitHub release for new installs, or see
# README in this folder to update a running server.
#
# PHP 8.1 (a step for old apps on their way to a supported PHP) also gets the add-ons PHP 7.1 and 7.4
# have, each its own file in lib/php/extensions and switched on by bin/sentora-php-addons only when it
# loads: apcu, redis, memcached, imagick, imap. ADDONS=1 builds them for another version too (imap is
# part of PHP up to 8.3 only).
set -euo pipefail

V="${1:-}"
SRC_FILE="${2:-}"
if [[ ! "$V" =~ ^8\.[0-9]+\.[0-9]+$ ]]; then
    echo "Usage: $0 <PHP version, e.g. 8.3.37> [source tarball]"
    exit 2
fi
MM="${V%.*}"
PREFIX="/opt/sentora-php/$MM"
OUT="$(pwd)/sentora-php-$MM-ubuntu26.04-amd64.tar.gz"

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "26.04" || "$(uname -m)" != "x86_64" ]]; then
    echo "This builds for Ubuntu 26.04 amd64 only (this is ${PRETTY_NAME:-unknown} $(uname -m))."
    exit 1
fi

# Build tools and the -dev packages of the libraries PHP links against
if [ "${SKIP_APT:-0}" != "1" ]; then
    apt-get -yqq install build-essential pkg-config autoconf bison re2c wget xz-utils python3 \
        libxml2-dev libcurl4-openssl-dev libonig-dev libzip-dev libicu-dev libsodium-dev \
        libargon2-dev libxslt1-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
        libssl-dev libsqlite3-dev zlib1g-dev libgmp-dev libldap-dev \
        libmemcached-dev libsasl2-dev libmagickwand-dev libc-client2007e-dev libkrb5-dev
fi

WORK=$(mktemp -d /tmp/sentora-php-build.XXXXXX)
trap 'rm -rf "$WORK"' EXIT

if [ -z "$SRC_FILE" ]; then
    SRC_FILE="$WORK/php-$V.tar.xz"
    echo "Downloading php-$V.tar.xz from php.net"
    wget -nv -O "$SRC_FILE" "https://www.php.net/distributions/php-$V.tar.xz"
    # Check the download against the SHA-256 that php.net publishes for it
    WANT=$(wget -qO- "https://www.php.net/releases/index.php?json&version=$V" | python3 -c '
import json, sys
d = json.load(sys.stdin)
print(next((f["sha256"] for f in d.get("source", []) if f.get("filename", "").endswith(".tar.xz")), ""))')
    GOT=$(sha256sum "$SRC_FILE" | cut -d' ' -f1)
    if [[ -z "$WANT" || "$WANT" != "$GOT" ]]; then
        echo "SHA-256 of php-$V.tar.xz does not match php.net (expected '$WANT', got '$GOT'). Stopping."
        exit 1
    fi
    echo "SHA-256 verified."
fi

echo "Unpacking $SRC_FILE"
tar -xf "$SRC_FILE" -C "$WORK"
SRC=$(find "$WORK" -mindepth 1 -maxdepth 1 -type d -name 'php-*' | head -1)
cd "$SRC"
# Release tarballs ship ./configure; a git tag export needs it generated first
[ -x ./configure ] || ./buildconf --force

echo "Configuring PHP $V (log: $WORK/configure.log)"
export CFLAGS="-O2 -g0" CXXFLAGS="-O2 -g0 -std=gnu++17"
./configure --prefix="$PREFIX" --sysconfdir="$PREFIX/etc" --localstatedir=/var \
    --with-config-file-path="$PREFIX/etc" --with-config-file-scan-dir="$PREFIX/etc/conf.d" \
    --disable-cgi --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data \
    --without-pear --disable-phpdbg \
    --with-openssl --with-zlib --with-curl --enable-mbstring --enable-intl --enable-bcmath --enable-calendar \
    --enable-exif --enable-ftp --enable-sockets --enable-soap --with-gettext --with-gmp \
    --enable-gd --with-jpeg --with-webp --with-freetype \
    --with-zip --with-sodium --with-password-argon2 --with-xsl \
    --enable-mysqlnd --with-mysql-sock=/var/run/mysqld/mysqld.sock --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd \
    --with-pdo-sqlite --with-sqlite3 --enable-opcache \
    --with-ldap > "$WORK/configure.log" 2>&1 \
    || { tail -25 "$WORK/configure.log"; exit 1; }

echo "Compiling (this takes a while; log: $WORK/make.log)"
make -j"$(nproc)" > "$WORK/make.log" 2>&1 || { grep -n "error" "$WORK/make.log" | head -25; exit 1; }

STAGE="$WORK/stage"
make install INSTALL_ROOT="$STAGE" > "$WORK/install.log" 2>&1 || { tail -25 "$WORK/install.log"; exit 1; }
P="$STAGE$PREFIX"
cp php.ini-production "$P/lib/php.ini-production"
rm -rf "$P/php/man"

# --- add-ons (PHP 8.1, or ADDONS=1): PECL extensions and imap on Ubuntu's own libraries ------------
ADDONS="${ADDONS:-$([ "$MM" = 8.1 ] && echo 1 || echo 0)}"
ADDON_LIST=""
if [ "$ADDONS" = 1 ]; then
    TOOLS="$WORK/tools"; mkdir -p "$TOOLS" "$WORK/pecl"
    for t in phpize php-config; do sed "s|$PREFIX|$P|g" "$P/bin/$t" > "$TOOLS/$t"; chmod 755 "$TOOLS/$t"; done
    # PECL publishes no checksums: these are the SHA-256 of the files as downloaded when this was written
    while read -r f sum; do
        wget -nv -O "$WORK/pecl/$f" "https://pecl.php.net/get/$f"
        [ "$(sha256sum "$WORK/pecl/$f" | cut -d' ' -f1)" = "$sum" ] || { echo "$f does not match its SHA-256. Stopping."; exit 1; }
        tar -xf "$WORK/pecl/$f" -C "$WORK/pecl"
    done <<'EOF_PECL'
apcu-5.1.21.tgz 1033530448696ee7cadec85050f6df5135fb1330072ef2a74569392acfecfbc1
redis-5.3.7.tgz b958166ccda4f40bd17c6998f9e2239021ae644467cd8ad5c15def420aad65b0
memcached-3.2.0.tgz 2a41143a7b29f4a962a3805b77aa207a99e4566e2d314ce98a051cd24d6e9636
imagick-3.7.0.tgz 5a364354109029d224bcbb2e82e15b248be9b641227f45e63425c06531792d3e
EOF_PECL
    addon() {   # name, source folder, configure options...
        local name="$1" dir="$2"; shift 2
        echo "Building the $name add-on (log: $WORK/addon-$name.log)"
        ( cd "$dir" && "$TOOLS/phpize" && ./configure --with-php-config="$TOOLS/php-config" "$@" && make -j"$(nproc)" && make install ) \
            > "$WORK/addon-$name.log" 2>&1 || { tail -25 "$WORK/addon-$name.log"; exit 1; }
        ADDON_LIST="$ADDON_LIST $name"
    }
    addon apcu "$WORK/pecl/apcu-5.1.21" --enable-apcu
    addon redis "$WORK/pecl/redis-5.3.7" --enable-redis
    addon memcached "$WORK/pecl/memcached-3.2.0" --with-libmemcached-dir=/usr --with-zlib-dir=/usr --disable-memcached-sasl
    addon imagick "$WORK/pecl/imagick-3.7.0" --with-imagick=/usr
    # (imap left PHP in 8.4; c-client is in /usr/lib, OpenSSL found through pkg-config)
    [ -d "$SRC/ext/imap" ] && addon imap "$SRC/ext/imap" --with-imap=/usr --with-kerberos --with-imap-ssl --with-libdir=lib
    cat > "$P/bin/sentora-php-addons" <<'EOF_ADDONS'
#!/bin/bash
# Switch on this PHP's add-on extensions (apcu, redis, memcached, imagick, imap) that load on this
# server, after installing the Ubuntu libraries they use; one that does not load stays off, so PHP
# itself always starts. Run as root by the installer and sentora-php-install; safe to run again.
P=$(cd "$(dirname "$0")/.." && pwd)
apt-get -qq -y install libmemcached11t64 libmemcachedutil2t64 libhashkit2t64 libsasl2-2 \
    libmagickwand-7.q16-10 libc-client2007e > /dev/null 2>&1 || true
EXT=$("$P/bin/php-config" --extension-dir)
mkdir -p "$P/etc/conf.d"
for e in apcu redis memcached imagick imap; do
    ini="$P/etc/conf.d/30-$e.ini"
    if [ -f "$EXT/$e.so" ] && "$P/bin/php" -n -d extension_dir="$EXT" -d extension="$e.so" \
        -r "exit(extension_loaded('$e') ? 0 : 1);" > /dev/null 2>&1; then
        echo "extension=$e.so" > "$ini"
        echo "  $e: on"
    else
        rm -f "$ini"
        [ -f "$EXT/$e.so" ] && echo "  $e: off (it does not load on this server)"
    fi
done
exit 0
EOF_ADDONS
    chmod 755 "$P/bin/sentora-php-addons"
    cd "$SRC"
fi
find "$P/bin" "$P/sbin" "$P/lib/php/extensions" -type f -exec strip --strip-unneeded {} + 2> /dev/null || true
cat > "$P/BUILD-INFO" <<EOI
PHP $V for Sentora on Ubuntu 26.04 (amd64)
Built $(date -u +%Y-%m-%d) with build-sentora-php.sh from php-$V.${ADDON_LIST:+
Add-ons (bin/sentora-php-addons switches them on):$ADDON_LIST}
Configure options: see bin/php-config --configure-options
EOI

# Quick check before packaging: it must run and load the extensions sites need
"$P/sbin/php-fpm" -v | head -1
# (list read once: "php -m | grep -q" can die of SIGPIPE, which pipefail counts as failure)
MODULES=$("$P/bin/php" -n -m)
MISSING=""
# (ldap: sites that log users in against Active Directory, such as Snipe-IT)
for ext in mysqli pdo_mysql gd curl mbstring intl xml zip sodium ldap; do
    grep -qx "$ext" <<< "$MODULES" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || { echo "Missing extensions:$MISSING"; exit 1; }
for e in $ADDON_LIST; do
    "$P/bin/php" -n -d extension_dir="$("$TOOLS/php-config" --extension-dir)" -d extension="$e.so" -r "exit(extension_loaded('$e') ? 0 : 1);" \
        || { echo "The $e add-on does not load. Stopping."; exit 1; }
done
[ -z "$ADDON_LIST" ] || echo "Add-ons load:$ADDON_LIST"

tar -czf "$OUT" -C "$STAGE" opt
echo "Done: $OUT"
