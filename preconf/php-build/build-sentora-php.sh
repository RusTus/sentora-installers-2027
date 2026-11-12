#!/bin/bash
#
# Build an extra PHP version for Sentora on Ubuntu 26.04 or Debian 13 (amd64) and package it
# the way sentora2027-ub26.04 and sentora2027-deb13 expect:
#     sentora-php-<major.minor>-ubuntu26.04-amd64.tar.gz    (built on Ubuntu 26.04)
#     sentora-php-<major.minor>-debian13-amd64.tar.gz       (built on Debian 13)
# A bundle runs only on the system it was built on (their libraries differ).
#
# Usage (as root, on an Ubuntu 26.04 or Debian 13 machine; a spare VM or a container is best):
#     bash build-sentora-php.sh 8.3.37                      # downloads from php.net
#     bash build-sentora-php.sh 8.3.37 php-8.3.37.tar.xz    # uses a file you downloaded
#
# Then attach the .tar.gz to the sentora-installers-2027 GitHub release for new installs, or see
# README in this folder to update a running server.
#
# PHP 8.1 and newer also get the add-ons PHP 7.1 and 7.4 have, each its own file in lib/php/extensions:
# apcu, redis, memcached, imagick, imap (imap is part of PHP up to 8.3 only). 8.1 keeps the versions it
# was first built with, switched on by bin/sentora-php-addons when they load; 8.2 and newer get today's
# PECL releases, left off until the administrator switches one on in PHP Versions > Extensions.
#
# Every bundle also has Snuffleupagus (the PHP hardening extension, snuffleupagus.sh), switched off:
# the PHP Hardening add-on switches it on, with its rules.
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

V="${1:-}"
SRC_FILE="${2:-}"
if [[ ! "$V" =~ ^8\.[0-9]+\.[0-9]+$ ]]; then
    echo "Usage: $0 <PHP version, e.g. 8.3.37> [source tarball]"
    exit 2
fi
MM="${V%.*}"
PREFIX="/opt/sentora-php/$MM"

. /etc/os-release
if [[ "${ID:-} ${VERSION_ID:-}" == "ubuntu 26.04" ]]; then
    OS_TAG="ubuntu26.04"; OS_NAME="Ubuntu 26.04"
elif [[ "${ID:-} ${VERSION_ID:-}" == "debian 13" ]]; then
    OS_TAG="debian13"; OS_NAME="Debian 13"
elif [[ "${ID:-}" =~ ^(almalinux|rocky)$ && "${VERSION_ID%%.*}" == "10" ]]; then
    OS_TAG="alma10"; OS_NAME="AlmaLinux 10"
elif [[ "${ID:-}" == "fedora" ]]; then
    OS_TAG="fedora${VERSION_ID:-}"; OS_NAME="Fedora ${VERSION_ID:-}"
else
    OS_TAG=""
fi
if [ -z "$OS_TAG" ] || [ "$(uname -m)" != "x86_64" ]; then
    echo "This builds for Ubuntu 26.04, Debian 13, AlmaLinux 10 or Fedora amd64 only (this is ${PRETTY_NAME:-unknown} $(uname -m))."
    exit 1
fi
OUT="$(pwd)/sentora-php-$MM-$OS_TAG-amd64.tar.gz"

# Build tools and the -dev packages of the libraries PHP links against
LIBDIR=lib
if [[ "$OS_TAG" == alma10 || "$OS_TAG" == fedora* ]]; then
    # AlmaLinux keeps its libraries in lib64; the -devel packages are in AppStream, CRB and EPEL
    LIBDIR=lib64
    if [ "${SKIP_APT:-0}" != "1" ]; then
        if [ "$OS_TAG" = alma10 ]; then
            dnf -y -q install epel-release dnf-plugins-core
            dnf config-manager --set-enabled crb
        fi
        dnf -y -q install gcc gcc-c++ make pkgconf-pkg-config autoconf bison re2c wget xz python3 tar openssl git \
            libxml2-devel libcurl-devel oniguruma-devel libzip-devel libicu-devel libsodium-devel \
            libargon2-devel libxslt-devel libpng-devel libjpeg-turbo-devel libwebp-devel freetype-devel \
            openssl-devel sqlite-devel zlib-devel gmp-devel openldap-devel \
            libmemcached-awesome-devel cyrus-sasl-devel ImageMagick-devel krb5-devel
    fi
elif [ "${SKIP_APT:-0}" != "1" ]; then
    apt-get -yqq install build-essential pkg-config autoconf bison re2c wget xz-utils python3 git \
        libxml2-dev libcurl4-openssl-dev libonig-dev libzip-dev libicu-dev libsodium-dev \
        libargon2-dev libxslt1-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
        libssl-dev libsqlite3-dev zlib1g-dev libgmp-dev libldap-dev \
        libmemcached-dev libsasl2-dev libmagickwand-dev libkrb5-dev
    # c-client (the imap add-on) is in Ubuntu only: Debian 13 dropped it
    [ "$OS_TAG" = debian13 ] || apt-get -yqq install libc-client2007e-dev
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
    --with-ldap --with-libdir="$LIBDIR" > "$WORK/configure.log" 2>&1 \
    || { tail -25 "$WORK/configure.log"; exit 1; }

echo "Compiling (this takes a while; log: $WORK/make.log)"
make -j"$(nproc)" > "$WORK/make.log" 2>&1 || { grep -n "error" "$WORK/make.log" | head -25; exit 1; }

STAGE="$WORK/stage"
make install INSTALL_ROOT="$STAGE" > "$WORK/install.log" 2>&1 || { tail -25 "$WORK/install.log"; exit 1; }
P="$STAGE$PREFIX"
cp php.ini-production "$P/lib/php.ini-production"
rm -rf "$P/php/man"

# --- Snuffleupagus (every bundle; off until the PHP Hardening add-on switches it on) --------------
. "$HERE/snuffleupagus.sh"
SPT="$WORK/sp-tools"; mkdir -p "$SPT"
for t in phpize php-config; do sed "s|$PREFIX|$P|g" "$P/bin/$t" > "$SPT/$t"; chmod 755 "$SPT/$t"; done
sp_build "$WORK" "$SPT/phpize" "$SPT/php-config" || exit 1
cd "$SRC"

# --- add-ons (PHP 8.1 and newer, or ADDONS=1): PECL extensions and imap on the system's own libraries
# PHP 8.1 keeps the versions it was first built with (switched on by sentora-php-addons); 8.2 and
# newer get today's and leave them off: the administrator switches each on in PHP Versions > Extensions
ADDONS="${ADDONS:-$([ "$(printf '%s\n8.1\n' "$MM" | sort -V | head -1)" = 8.1 ] && echo 1 || echo 0)}"
if [ "$MM" = 8.1 ]; then
    PECL_SET='apcu-5.1.21.tgz 1033530448696ee7cadec85050f6df5135fb1330072ef2a74569392acfecfbc1
redis-5.3.7.tgz b958166ccda4f40bd17c6998f9e2239021ae644467cd8ad5c15def420aad65b0
memcached-3.2.0.tgz 2a41143a7b29f4a962a3805b77aa207a99e4566e2d314ce98a051cd24d6e9636
imagick-3.7.0.tgz 5a364354109029d224bcbb2e82e15b248be9b641227f45e63425c06531792d3e'
    AUTO_ON=1
else
    PECL_SET='apcu-5.1.28.tgz ca9c1820810a168786f8048a4c3f8c9e3fd941407ad1553259fb2e30b5f057bf
redis-6.3.0.tgz 0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5
memcached-3.4.0.tgz c163434eb0da97c8f45c7ad41d979d381f8b81c49402b1b90b063987fb37972e
imagick-3.8.1.tgz 3a3587c0a524c17d0dad9673a160b90cd776e836838474e173b549ed864352ee'
    AUTO_ON=0
fi
ADDON_LIST=""
if [ "$ADDONS" = 1 ]; then
    TOOLS="$WORK/tools"; mkdir -p "$TOOLS" "$WORK/pecl"
    for t in phpize php-config; do sed "s|$PREFIX|$P|g" "$P/bin/$t" > "$TOOLS/$t"; chmod 755 "$TOOLS/$t"; done
    # PECL publishes no checksums: these are the SHA-256 of the files as downloaded when this was written
    while read -r f sum; do
        wget -nv -O "$WORK/pecl/$f" "https://pecl.php.net/get/$f"
        [ "$(sha256sum "$WORK/pecl/$f" | cut -d' ' -f1)" = "$sum" ] || { echo "$f does not match its SHA-256. Stopping."; exit 1; }
        tar -xf "$WORK/pecl/$f" -C "$WORK/pecl"
    done <<< "$PECL_SET"
    addon() {   # name, source folder, configure options...
        local name="$1" dir="$2"; shift 2
        echo "Building the $name add-on (log: $WORK/addon-$name.log)"
        ( cd "$dir" && "$TOOLS/phpize" && ./configure --with-php-config="$TOOLS/php-config" "$@" && make -j"$(nproc)" && make install ) \
            > "$WORK/addon-$name.log" 2>&1 || { tail -25 "$WORK/addon-$name.log"; exit 1; }
        ADDON_LIST="$ADDON_LIST $name"
    }
    pdir() { ls -d "$WORK/pecl/$1"-[0-9]* | head -1; }
    addon apcu "$(pdir apcu)" --enable-apcu
    addon redis "$(pdir redis)" --enable-redis
    addon memcached "$(pdir memcached)" --with-libmemcached-dir=/usr --with-zlib-dir=/usr --disable-memcached-sasl
    addon imagick "$(pdir imagick)" --with-imagick=/usr
    # (imap left PHP in 8.4; c-client is in /usr/lib, OpenSSL found through pkg-config)
    [ -f "$SRC/ext/imap/config.m4" ] && [ -e /usr/include/c-client/c-client.h ] && addon imap "$SRC/ext/imap" --with-imap=/usr --with-kerberos --with-imap-ssl --with-libdir=lib
    cat > "$P/bin/sentora-php-addons" <<EOF_HEAD
#!/bin/bash
# (1: this script switches the add-ons on; 0: PHP Versions > Extensions does, when asked)
AUTO_ON=$AUTO_ON
EOF_HEAD
    cat >> "$P/bin/sentora-php-addons" <<'EOF_ADDONS'
# Switch on this PHP's add-on extensions (apcu, redis, memcached, imagick, imap) that load on this
# server, after installing the system libraries they use; one that does not load stays off, so PHP
# itself always starts. Run as root by the installer and sentora-php-install; safe to run again.
P=$(cd "$(dirname "$0")/.." && pwd)
if command -v apt-get > /dev/null 2>&1; then
    apt-get -qq -y install libmemcached11t64 libmemcachedutil2t64 libhashkit2t64 libsasl2-2 \
        libmagickwand-7.q16-10 > /dev/null 2>&1 || true
    # (c-client, for imap: Ubuntu only)
    apt-get -qq -y install libc-client2007e > /dev/null 2>&1 || true
else
    dnf -y -q install libmemcached-awesome cyrus-sasl-lib ImageMagick-libs > /dev/null 2>&1 || true
fi
EXT=$("$P/bin/php-config" --extension-dir)
mkdir -p "$P/etc/conf.d"
for e in apcu redis memcached imagick imap; do
    ini="$P/etc/conf.d/30-$e.ini"
    if [ -f "$EXT/$e.so" ] && [ "$AUTO_ON" != 1 ]; then
        echo "  $e: available (switch it on in PHP Versions > Extensions)"
    elif [ -f "$EXT/$e.so" ] && "$P/bin/php" -n -d extension_dir="$EXT" -d extension="$e.so" \
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
PHP $V for Sentora on $OS_NAME (amd64)
Built $(date -u +%Y-%m-%d) with build-sentora-php.sh from php-$V.${ADDON_LIST:+
Add-ons ($([ "${AUTO_ON:-1}" = 1 ] && echo 'bin/sentora-php-addons switches them on' || echo 'off: switched on in PHP Versions > Extensions')):$ADDON_LIST}
Snuffleupagus $SP_VERSION: lib/php/extensions, off (the PHP Hardening add-on switches it on).
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
sp_check "$P/bin/php" "$("$SPT/php-config" --extension-dir)" || { echo "Snuffleupagus does not load. Stopping."; exit 1; }
echo "Snuffleupagus $SP_VERSION loads (off)."

tar -czf "$OUT" -C "$STAGE" opt
echo "Done: $OUT"
