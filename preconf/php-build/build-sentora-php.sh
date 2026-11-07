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
        libssl-dev libsqlite3-dev zlib1g-dev libgmp-dev libldap-dev
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
find "$P/bin" "$P/sbin" "$P/lib/php/extensions" -type f -exec strip --strip-unneeded {} + 2> /dev/null || true
cat > "$P/BUILD-INFO" <<EOI
PHP $V for Sentora on Ubuntu 26.04 (amd64)
Built $(date -u +%Y-%m-%d) with build-sentora-php.sh from php-$V.
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

tar -czf "$OUT" -C "$STAGE" opt
echo "Done: $OUT"
