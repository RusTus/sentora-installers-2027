#!/bin/bash
# Build webalizer from the bundled source and install it as /usr/local/bin/webalizer.
# Usage: build-webalizer.sh <folder containing this script>
set -e
SRC_DIR="${1:-$(cd "$(dirname "$0")" && pwd)}"
BUILD=$(mktemp -d /tmp/webalizer-build.XXXXXX)
trap 'rm -rf "$BUILD"' EXIT
tar -xzf "$SRC_DIR/webalizer_2.23.08.orig.tar.gz" -C "$BUILD"
cd "$BUILD/webalizer-2.23-08"
while read -r p; do
    [ -n "$p" ] && patch -p1 -s < "$SRC_DIR/patches/$p"
done < "$SRC_DIR/patches/series"
# No reverse DNS cache (needs Berkeley DB) and no GeoIP (its databases are discontinued)
./configure --prefix=/usr/local --sysconfdir=/etc/webalizer --with-etcdir=/etc/webalizer \
    --disable-dns --disable-geoip > "$BUILD/configure.log" 2>&1 || { tail -20 "$BUILD/configure.log"; exit 1; }
make > "$BUILD/make.log" 2>&1 || { grep -i "error" "$BUILD/make.log" | head -20; exit 1; }
install -D -m 755 webalizer /usr/local/bin/webalizer
/usr/local/bin/webalizer -V | head -1
