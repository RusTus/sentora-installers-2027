#!/bin/bash
#
# Build PHP 7.4 for Sentora (amd64), a step between PHP 7.1 and 8.x for websites moved from an old
# Sentora server whose apps are upgraded in steps (osTicket 1.14-1.15, Snipe-IT 5.x, Magento 2.3 need 7.3
# or 7.4 on the way to PHP 8):
#     sentora-php-7.4-ubuntu26.04-amd64.tar.gz
#
# PHP 7.4 has had no security fixes since 28 Nov 2022. Sentora installs it switched off, and the PHP
# Versions page says so: switch it on while a site needs it on its way to PHP 8, then off again.
#
# The same recipe as build-sentora-php71.sh: PHP 7.4 does not build against Ubuntu 26.04's OpenSSL 3,
# libxml2 2.14 or ICU, so it carries its own OpenSSL 1.1.1w, curl 7.88.1, libxml2 2.9.14, OpenLDAP
# 2.6.10 (GnuTLS), ICU 64.2 and libxslt 1.1.34, compiled in and kept private to PHP (--exclude-libs,ALL),
# so that Ubuntu's libraries it also uses (oniguruma, libzip, libsodium, libargon2, sqlite, and the
# add-ons' libmemcached, ImageMagick and c-client) never meet them in one process. PHP 7.4 finds most
# libraries with pkg-config; "pkg-config --static" also names what the compiled-in ones need.
#
# Add-ons, each its own file in lib/php/extensions, switched on by bin/sentora-php-addons (run by the
# installer and sentora-php-install) only when it loads: apcu, redis, memcached, imagick and imap.
#
# Usage (as root, on Ubuntu 26.04 amd64; a spare VM is best, it installs compilers):
#     bash build-sentora-php74.sh
# Every source is checked against its published SHA-256 (OpenLDAP publishes SHA3-512; ICU a PGP
# signature, checked when this was written, and the SHA-256 here is of that file; PECL publishes none:
# those are the SHA-256 of the files as downloaded then). Writes the .tar.gz in the current folder.
set -euo pipefail

V=7.4.33
MM=7.4
PREFIX="/opt/sentora-php/$MM"
OUT="$(pwd)/sentora-php-$MM-ubuntu26.04-amd64.tar.gz"

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "26.04" || "$(uname -m)" != "x86_64" ]]; then
    echo "This builds on Ubuntu 26.04 amd64 only (this is ${PRETTY_NAME:-unknown} $(uname -m))."
    exit 1
fi

if [ "${SKIP_APT:-0}" != "1" ]; then
    apt-get -yqq install build-essential pkg-config autoconf bison re2c wget xz-utils perl \
        libpng-dev libjpeg-dev libfreetype-dev libwebp-dev libgmp-dev zlib1g-dev libgnutls28-dev \
        libssl-dev libmemcached-dev libsasl2-dev libmagickwand-dev libc-client2007e-dev libkrb5-dev \
        libonig-dev libzip-dev libsodium-dev libargon2-dev libsqlite3-dev
fi

WORK=$(mktemp -d /tmp/sentora-php74-build.XXXXXX)
# (KEEP_WORK=1 keeps the work folder, to look at or retry a step)
trap '[ "${KEEP_WORK:-0}" = 1 ] || rm -rf "$WORK"' EXIT
DEPS="$WORK/deps"
mkdir -p "$DEPS/lib" "$WORK/src"
# configure looks for --with-ldap's libraries in <dir>/lib/x86_64-linux-gnu (--with-libdir below)
ln -s . "$DEPS/lib/x86_64-linux-gnu"
J=$(nproc)

# name | URL | sha256 (sha3-512: OpenLDAP)
fetch() {
    local file="$1" url="$2" sum="$3" algo="${4:-sha256}" got
    echo "Downloading $file"
    wget -nv -O "$WORK/src/$file" "$url"
    if [ "$algo" = sha3-512 ]; then got=$(openssl dgst -sha3-512 -r "$WORK/src/$file" | cut -d' ' -f1); else got=$("${algo}sum" "$WORK/src/$file" | cut -d' ' -f1); fi
    if [ "$got" != "$sum" ]; then
        echo "$file does not match its published $algo ($sum). Stopping."
        exit 1
    fi
}
fetch php-$V.tar.xz https://www.php.net/distributions/php-$V.tar.xz 924846abf93bc613815c55dd3f5809377813ac62a9ec4eb3778675b82a27b927
fetch openssl-1.1.1w.tar.gz https://www.openssl.org/source/openssl-1.1.1w.tar.gz cf3098950cb4d853ad95c0841f1f9c6d3dc102dccfcacd521d93925208b76ac8
fetch curl-7.88.1.tar.xz https://curl.se/download/curl-7.88.1.tar.xz 1dae31b2a7c1fe269de99c0c31bb488346aab3459b5ffca909d6938249ae415f
fetch libxml2-2.9.14.tar.xz https://download.gnome.org/sources/libxml2/2.9/libxml2-2.9.14.tar.xz 60d74a257d1ccec0475e749cba2f21559e48139efba6ff28224357c7c798dfee
fetch icu4c-64_2-src.tgz https://github.com/unicode-org/icu/releases/download/release-64-2/icu4c-64_2-src.tgz 627d5d8478e6d96fc8c90fed4851239079a561a6a8b9e48b0892f24e82d31d6c
fetch libxslt-1.1.34.tar.xz https://download.gnome.org/sources/libxslt/1.1/libxslt-1.1.34.tar.xz 28c47db33ab4daefa6232f31ccb3c65260c825151ec86ec461355247f3f56824
# PECL publishes no checksums: these are the SHA-256 of the files as downloaded when this was written
fetch apcu-5.1.21.tgz https://pecl.php.net/get/apcu-5.1.21.tgz 1033530448696ee7cadec85050f6df5135fb1330072ef2a74569392acfecfbc1
fetch redis-5.3.7.tgz https://pecl.php.net/get/redis-5.3.7.tgz b958166ccda4f40bd17c6998f9e2239021ae644467cd8ad5c15def420aad65b0
fetch memcached-3.2.0.tgz https://pecl.php.net/get/memcached-3.2.0.tgz 2a41143a7b29f4a962a3805b77aa207a99e4566e2d314ce98a051cd24d6e9636
fetch imagick-3.7.0.tgz https://pecl.php.net/get/imagick-3.7.0.tgz 5a364354109029d224bcbb2e82e15b248be9b641227f45e63425c06531792d3e
fetch openldap-2.6.10.tgz https://www.openldap.org/software/download/OpenLDAP/openldap-release/openldap-2.6.10.tgz 2f87787885410ba2337da7f4201293bd023ad9d34e1e133a5cc1caac4550abd1c1069ddeb3605aac99f3c7bcc9d9b69d6be87e2b722097d018613e698d76efde sha3-512
cd "$WORK/src"
for f in *.tar.* *.tgz; do tar -xf "$f"; done

step() { echo "$1 (log: $WORK/$2.log)"; }
fail() { echo "FAILED, the end of $WORK/$1.log:"; tail -30 "$WORK/$1.log"; exit 1; }

step "Building OpenSSL 1.1.1w" openssl
( cd openssl-1.1.1w && ./config --prefix="$DEPS" --openssldir=/usr/lib/ssl no-shared no-tests -fPIC \
    && make -j"$J" && make install_sw ) > "$WORK/openssl.log" 2>&1 || fail openssl

step "Building curl 7.88.1" curl
( cd curl-7.88.1 && PKG_CONFIG_PATH="$DEPS/lib/pkgconfig" LIBS="-ldl -lpthread" ./configure --prefix="$DEPS" \
    --disable-shared --enable-static --with-pic --with-openssl="$DEPS" \
    --with-ca-bundle=/etc/ssl/certs/ca-certificates.crt --with-ca-path=/etc/ssl/certs \
    --without-libpsl --without-brotli --without-zstd --without-nghttp2 --without-libidn2 --without-librtmp \
    --without-libssh2 --disable-ldap --disable-ldaps --disable-manual \
    && make -j"$J" && make install ) > "$WORK/curl.log" 2>&1 || fail curl

step "Building libxml2 2.9.14" libxml2
( cd libxml2-2.9.14 && ./configure --prefix="$DEPS" --disable-shared --enable-static --with-pic \
    --without-python --without-lzma --without-icu --with-zlib \
    && make -j"$J" && make install ) > "$WORK/libxml2.log" 2>&1 || fail libxml2

step "Building OpenLDAP 2.6.10 (client library, GnuTLS)" openldap
( cd openldap-2.6.10 && CFLAGS="-O2 -fPIC" ./configure --prefix="$DEPS" --disable-shared --enable-static --with-pic \
    --disable-slapd --with-tls=gnutls --without-cyrus-sasl --without-argon2 \
    && make -j"$J" depend && make -j"$J" -C include && make -j"$J" -C libraries \
    && make -C include install && make -C libraries install ) > "$WORK/openldap.log" 2>&1 || fail openldap

step "Building ICU 64.2 (for intl)" icu
# (no Python: the source carries its data ready-made, and ICU 64's Python helper needs distutils,
# which Python 3.12 and later no longer have)
( cd icu/source && ac_cv_prog_PYTHON= CFLAGS="-O2 -fPIC" CXXFLAGS="-O2 -fPIC -std=c++11" ./configure --prefix="$DEPS" \
    --disable-shared --enable-static --with-data-packaging=static --enable-icu-config \
    --disable-tests --disable-samples --disable-extras \
    && make -j"$J" && make install ) > "$WORK/icu.log" 2>&1 || fail icu

step "Building libxslt 1.1.34 (for xsl)" libxslt
# (its configure asks "xml2-config --libs print", which this libxml2 does not take: tell it directly)
( cd libxslt-1.1.34 && LIBXML_CFLAGS="-I$DEPS/include/libxml2" LIBXML_LIBS="-L$DEPS/lib -lxml2 -lz -lm" \
    ./configure --prefix="$DEPS" --disable-shared --enable-static --with-pic \
    --with-libxml-prefix="$DEPS" --without-python --without-crypto --without-debugger \
    && make -j"$J" && make install ) > "$WORK/libxslt.log" 2>&1 || fail libxslt

step "Configuring PHP $V" configure
cd "php-$V"
# (no intl patch: PHP 7.4 names its MessageFormatAdapter methods php..., so they do not clash with ICU's)
export CFLAGS="-O2 -g0 -fPIC -fcommon -std=gnu17 -fpermissive"
export CXXFLAGS="-O2 -g0 -fPIC -std=c++11"
# the static OpenSSL and ICU need these at the end of every link
export LIBS="-ldl -lpthread -lgnutls -lstdc++ -lm" PKG_CONFIG_PATH="$DEPS/lib/pkgconfig" PATH="$DEPS/bin:$PATH"
# (the compiled-in libraries are static: --static adds what they need in turn, such as OpenSSL for curl)
export PKG_CONFIG="pkg-config --static"
# the compiled-in libraries stay private to PHP (see the top)
export LDFLAGS="-Wl,--exclude-libs,ALL"
./configure --prefix="$PREFIX" --sysconfdir="$PREFIX/etc" --localstatedir=/var \
    --with-config-file-path="$PREFIX/etc" --with-config-file-scan-dir="$PREFIX/etc/conf.d" \
    --with-libdir=lib/x86_64-linux-gnu \
    --disable-cgi --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data \
    --without-pear --disable-phpdbg \
    --with-openssl --with-curl --with-zlib --enable-mbstring --enable-bcmath --enable-calendar --enable-exif --enable-ftp \
    --enable-sockets --enable-soap --with-gettext --with-gmp --with-iconv --with-ldap="$DEPS" \
    --enable-gd --with-jpeg --with-webp --with-freetype \
    --with-zip --with-sodium --with-password-argon2 \
    --enable-mysqlnd --with-mysql-sock=/var/run/mysqld/mysqld.sock \
    --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-sqlite --with-sqlite3 --enable-opcache \
    --enable-intl --with-xsl \
    > "$WORK/configure.log" 2>&1 || fail configure

step "Compiling PHP $V (this takes a while)" make
make -j"$J" > "$WORK/make.log" 2>&1 || fail make

STAGE="$WORK/stage"
make install INSTALL_ROOT="$STAGE" > "$WORK/install.log" 2>&1 || fail install
P="$STAGE$PREFIX"
cp php.ini-production "$P/lib/php.ini-production"
rm -rf "$P/php/man"

# --- add-ons: shared extensions on Ubuntu's own libraries ------------------------------------------
# phpize and php-config of the staged PHP (they name the final folder, which is not there yet)
TOOLS="$WORK/tools"; mkdir -p "$TOOLS"
for t in phpize php-config; do sed "s|$PREFIX|$P|g" "$P/bin/$t" > "$TOOLS/$t"; chmod 755 "$TOOLS/$t"; done
addon() {   # name, source folder, configure options...
    local name="$1" dir="$2"; shift 2
    step "Building the $name add-on" "addon-$name"
    ( cd "$dir" && env -u PKG_CONFIG_PATH -u LIBS -u LDFLAGS PATH="/usr/sbin:/usr/bin:/sbin:/bin" \
        CFLAGS="-O2 -g0 -fPIC -fcommon -std=gnu17" CXXFLAGS="-O2 -g0 -fPIC" bash -c '
        "$0/phpize" && ./configure --with-php-config="$0/php-config" "$@" && make -j"'"$J"'" && make install' "$TOOLS" "$@" \
    ) > "$WORK/addon-$name.log" 2>&1 || fail "addon-$name"
}
cd "$WORK/src"
addon apcu apcu-5.1.21 --enable-apcu
addon redis redis-5.3.7 --enable-redis
addon memcached memcached-3.2.0 --with-libmemcached-dir=/usr --with-zlib-dir=/usr --disable-memcached-sasl
addon imagick imagick-3.7.0 --with-imagick=/usr
# (c-client is in /usr/lib; OpenSSL, found through pkg-config, in /usr/lib/x86_64-linux-gnu)
addon imap "php-$V/ext/imap" --with-imap=/usr --with-kerberos --with-imap-ssl --with-libdir=lib
cd "$WORK/src/php-$V"

# switches on the add-ons that load on the server (run by the installer and sentora-php-install)
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
find "$P/bin" "$P/sbin" "$P/lib/php/extensions" -type f -exec strip --strip-unneeded {} + 2> /dev/null || true
cat > "$P/BUILD-INFO" <<EOI
PHP $V for Sentora (amd64) - a step for moving old websites on to PHP 8. No security fixes since 28 Nov 2022.
Built $(date -u +%Y-%m-%d) on $PRETTY_NAME with build-sentora-php74.sh from php-$V.
Compiled in: OpenSSL 1.1.1w, curl 7.88.1, libxml2 2.9.14, OpenLDAP 2.6.10 (GnuTLS), ICU 64.2, libxslt 1.1.34.
Add-ons (bin/sentora-php-addons switches them on): apcu 5.1.21, redis 5.3.7, memcached 3.2.0, imagick 3.7.0, imap (Ubuntu's c-client).
Configure options: see bin/php-config --configure-options
EOI

# It must run and load the extensions old sites need, and link nothing that differs on Ubuntu 26.04
"$P/sbin/php-fpm" -v | head -1
MODULES=$("$P/bin/php" -n -m)
MISSING=""
for ext in mysqli pdo_mysql mysqlnd gd curl openssl mbstring xml dom simplexml soap zip json sockets gmp bcmath ldap fileinfo intl xsl sodium; do
    grep -qix "$ext" <<< "$MODULES" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || { echo "Missing extensions:$MISSING"; exit 1; }
LINKED=$(ldd "$P/sbin/php-fpm" | awk '{print $1}' | sort -u | tr '\n' ' ')
for lib in libssl libcrypto libcurl libxml2 libicu libxslt libexslt libldap liblber; do
    case " $LINKED" in *" $lib"*) echo "php-fpm links the system's $lib: it would not run on Ubuntu 26.04. Stopping."; exit 1 ;; esac
done
echo "Links: $LINKED"
# the compiled-in libraries must stay private (--exclude-libs), or an add-on's Ubuntu library could
# call into them
# (the list goes to a file first: with pipefail, a search that finds nothing, the good case, stops the script)
nm -D --defined-only "$P/sbin/php-fpm" | awk '{print $3}' > "$WORK/exported.txt"
LEAK=$(grep -E '^(SSL_|EVP_|OPENSSL_|CRYPTO_|xml[A-Z]|xslt|exslt|curl_(easy|multi|global|share|slist|version)|ldap_|ber_|mcrypt_|u[a-z]*_.*_64$)' "$WORK/exported.txt" | head -5 || true)
[ -z "$LEAK" ] || { echo "php-fpm still offers compiled-in library functions to others ($LEAK). Stopping."; exit 1; }
grep -qx 'zend_parse_parameters' "$WORK/exported.txt" || { echo "php-fpm offers no PHP functions to extensions. Stopping."; exit 1; }
EXT_DIR=$("$TOOLS/php-config" --extension-dir)
for e in apcu redis memcached imagick imap; do
    "$P/bin/php" -n -d extension_dir="$EXT_DIR" -d extension="$e.so" -r "exit(extension_loaded('$e') ? 0 : 1);" \
        || { echo "The $e add-on does not load. Stopping."; exit 1; }
done
echo "Add-ons load: apcu redis memcached imagick imap"

tar -czf "$OUT" -C "$STAGE" opt
echo "Done: $OUT"
