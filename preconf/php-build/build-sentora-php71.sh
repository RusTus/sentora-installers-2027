#!/bin/bash
#
# Build PHP 7.1 for Sentora (amd64), ONLY to move old Sentora 2.0.2 websites to Sentora-2027:
#     sentora-php-7.1-ubuntu26.04-amd64.tar.gz
#
# PHP 7.1 has had no security fixes since 1 Dec 2019. Sites should be moved to PHP 8.x as soon as
# they run; Sentora installs this version switched off, and the PHP Versions page says so.
#
# PHP 7.1 does not build against Ubuntu 26.04's own OpenSSL 3, libxml2 2.14 or ICU, so this bundle
# carries its own copies of the libraries that changed (OpenSSL 1.1.1w, curl 7.88.1, libxml2 2.9.14,
# libmcrypt 2.5.8), compiled in. Besides the C library it only needs libraries whose versions are the
# same on Ubuntu 24.04 and 26.04 (zlib, libpng16, libjpeg-turbo, freetype, libwebp, gmp), so it can be
# built on either. No intl extension (it needs an ICU this old PHP can not build against).
#
# Usage (as root, on Ubuntu 24.04 or 26.04 amd64; a spare VM is best, it installs compilers):
#     bash build-sentora-php71.sh
# Every source is checked against its published SHA-256 (curl: its author's signature; libmcrypt,
# from 2007, only has an MD5). Writes the .tar.gz in the current folder.
set -euo pipefail

V=7.1.33
MM=7.1
PREFIX="/opt/sentora-php/$MM"
OUT="$(pwd)/sentora-php-$MM-ubuntu26.04-amd64.tar.gz"

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || ! "${VERSION_ID:-}" =~ ^(24|26)\.04$ || "$(uname -m)" != "x86_64" ]]; then
    echo "This builds on Ubuntu 24.04 or 26.04 amd64 only (this is ${PRETTY_NAME:-unknown} $(uname -m))."
    exit 1
fi

if [ "${SKIP_APT:-0}" != "1" ]; then
    apt-get -yqq install build-essential pkg-config autoconf bison re2c wget xz-utils perl \
        libpng-dev libjpeg-dev libfreetype-dev libwebp-dev libgmp-dev zlib1g-dev
fi

WORK=$(mktemp -d /tmp/sentora-php71-build.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
DEPS="$WORK/deps"
mkdir -p "$DEPS/lib" "$WORK/src"
# PHP 7.1's configure looks for libraries in <dir>/lib/x86_64-linux-gnu (--with-libdir below)
ln -s . "$DEPS/lib/x86_64-linux-gnu"
J=$(nproc)

# name | URL | sha256 (md5: for libmcrypt)
fetch() {
    local file="$1" url="$2" sum="$3" algo="${4:-sha256}"
    echo "Downloading $file"
    wget -nv -O "$WORK/src/$file" "$url"
    if [ "$("${algo}sum" "$WORK/src/$file" | cut -d' ' -f1)" != "$sum" ]; then
        echo "$file does not match its published $algo ($sum). Stopping."
        exit 1
    fi
}
fetch php-$V.tar.xz https://www.php.net/distributions/php-$V.tar.xz bd7c0a9bd5433289ee01fd440af3715309faf583f75832b64fe169c100d52968
fetch openssl-1.1.1w.tar.gz https://www.openssl.org/source/openssl-1.1.1w.tar.gz cf3098950cb4d853ad95c0841f1f9c6d3dc102dccfcacd521d93925208b76ac8
fetch curl-7.88.1.tar.xz https://curl.se/download/curl-7.88.1.tar.xz 1dae31b2a7c1fe269de99c0c31bb488346aab3459b5ffca909d6938249ae415f
fetch libxml2-2.9.14.tar.xz https://download.gnome.org/sources/libxml2/2.9/libxml2-2.9.14.tar.xz 60d74a257d1ccec0475e749cba2f21559e48139efba6ff28224357c7c798dfee
fetch libmcrypt-2.5.8.tar.gz https://downloads.sourceforge.net/project/mcrypt/Libmcrypt/2.5.8/libmcrypt-2.5.8.tar.gz 0821830d930a86a5c69110837c55b7da md5
cd "$WORK/src"
for f in *.tar.*; do tar -xf "$f"; done

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

step "Building libmcrypt 2.5.8" libmcrypt
( cd libmcrypt-2.5.8 && CFLAGS="-O2 -fPIC" ./configure --prefix="$DEPS" --disable-shared --enable-static \
    && make -j"$J" && make install ) > "$WORK/libmcrypt.log" 2>&1 || fail libmcrypt

# PHP 7.1's gd asks <dir>/bin/freetype-config, which freetype stopped shipping: answer from pkg-config
mkdir -p "$DEPS/freetype/bin"
cat > "$DEPS/freetype/bin/freetype-config" <<'EOF_FT'
#!/bin/sh
case "$1" in
    --cflags) pkg-config --cflags freetype2 ;;
    --libs) pkg-config --libs freetype2 ;;
    --version|--ftversion) pkg-config --modversion freetype2 ;;
    *) exit 1 ;;
esac
EOF_FT
chmod 755 "$DEPS/freetype/bin/freetype-config"

step "Configuring PHP $V" configure
cd "php-$V"
export CFLAGS="-O2 -g0 -fPIC -fcommon" CXXFLAGS="-O2 -g0 -fPIC"
# the static OpenSSL needs these at the end of every link
export LIBS="-ldl -lpthread" PKG_CONFIG_PATH="$DEPS/lib/pkgconfig"
./configure --prefix="$PREFIX" --sysconfdir="$PREFIX/etc" --localstatedir=/var \
    --with-config-file-path="$PREFIX/etc" --with-config-file-scan-dir="$PREFIX/etc/conf.d" \
    --with-libdir=lib/x86_64-linux-gnu \
    --disable-cgi --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data \
    --without-pear --disable-phpdbg \
    --with-openssl="$DEPS" --with-curl="$DEPS" --with-libxml-dir="$DEPS" --with-mcrypt="$DEPS" \
    --with-zlib --with-zlib-dir=/usr --enable-mbstring --enable-bcmath --enable-calendar --enable-exif --enable-ftp \
    --enable-sockets --enable-soap --with-gettext --with-gmp --with-iconv \
    --with-gd --with-jpeg-dir=/usr --with-png-dir=/usr --with-freetype-dir="$DEPS/freetype" --with-webp-dir=/usr \
    --enable-zip --enable-mysqlnd --with-mysql-sock=/var/run/mysqld/mysqld.sock \
    --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-sqlite --with-sqlite3 --enable-opcache \
    > "$WORK/configure.log" 2>&1 || fail configure

step "Compiling PHP $V (this takes a while)" make
make -j"$J" > "$WORK/make.log" 2>&1 || fail make

STAGE="$WORK/stage"
make install INSTALL_ROOT="$STAGE" > "$WORK/install.log" 2>&1 || fail install
P="$STAGE$PREFIX"
cp php.ini-production "$P/lib/php.ini-production"
rm -rf "$P/php/man"
find "$P/bin" "$P/sbin" "$P/lib/php/extensions" -type f -exec strip --strip-unneeded {} + 2> /dev/null || true
cat > "$P/BUILD-INFO" <<EOI
PHP $V for Sentora (amd64) - ONLY for moving old Sentora 2.0.2 websites. No security fixes since 1 Dec 2019.
Built $(date -u +%Y-%m-%d) on $PRETTY_NAME with build-sentora-php71.sh from php-$V.
Compiled in: OpenSSL 1.1.1w, curl 7.88.1, libxml2 2.9.14, libmcrypt 2.5.8.
Configure options: see bin/php-config --configure-options
EOI

# It must run and load the extensions old sites need, and link nothing that differs on Ubuntu 26.04
"$P/sbin/php-fpm" -v | head -1
MODULES=$("$P/bin/php" -n -m)
MISSING=""
for ext in mysqli pdo_mysql mysqlnd gd curl openssl mbstring xml dom simplexml soap zip mcrypt json sockets gmp bcmath; do
    grep -qix "$ext" <<< "$MODULES" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || { echo "Missing extensions:$MISSING"; exit 1; }
LINKED=$(ldd "$P/sbin/php-fpm" | awk '{print $1}' | sort -u | tr '\n' ' ')
for lib in libssl libcrypto libcurl libxml2 libicu libmcrypt; do
    case " $LINKED" in *" $lib"*) echo "php-fpm links the system's $lib: it would not run on Ubuntu 26.04. Stopping."; exit 1 ;; esac
done
echo "Links: $LINKED"

tar -czf "$OUT" -C "$STAGE" opt
echo "Done: $OUT"
