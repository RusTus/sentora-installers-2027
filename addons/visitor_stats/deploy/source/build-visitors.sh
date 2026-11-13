#!/bin/bash
# Build Visitors (web log analyzer) from the bundled source and install it as
# /usr/local/bin/visitors. Usage: build-visitors.sh <folder containing this script>
set -e
SRC_DIR="${1:-$(cd "$(dirname "$0")" && pwd)}"
BUILD=$(mktemp -d /tmp/visitors-build.XXXXXX)
trap 'rm -rf "$BUILD"' EXIT
tar -xzmf "$SRC_DIR/visitors_0.7.orig.tar.gz" -C "$BUILD"
cd "$BUILD"/visitors_0.7
while read -r p; do
    [ -n "$p" ] && patch -p1 -s < "$SRC_DIR/patches/$p"
done < "$SRC_DIR/patches/series"
make CFLAGS="-O2 -Wall -D_FORTIFY_SOURCE=2 -fstack-protector-strong" > "$BUILD/make.log" 2>&1 \
    || { grep -i "error" "$BUILD/make.log" | head -20; exit 1; }
install -D -m 755 visitors /usr/local/bin/visitors
echo "Visitors $(grep -o 'VI_VERSION_STR "[^"]*"' visitors.c | cut -d'"' -f2) installed in /usr/local/bin/visitors"
