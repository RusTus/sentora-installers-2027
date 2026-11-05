#!/bin/bash
# Build GoAccess (web log analyzer) from the bundled source and install it in
# /opt/sentora-goaccess. Usage: build-goaccess.sh <folder containing this script>
set -e
SRC_DIR="${1:-$(cd "$(dirname "$0")" && pwd)}"
BUILD=$(mktemp -d /tmp/goaccess-build.XXXXXX)
trap 'rm -rf "$BUILD"' EXIT
# Keep the archive's file times (-m would make "make" try to rerun autotools)
tar -xzf "$SRC_DIR/goaccess-1.12.tar.gz" -C "$BUILD"
cd "$BUILD"/goaccess-1.12
CFLAGS="-O2 -D_FORTIFY_SOURCE=2 -fstack-protector-strong" ./configure --prefix=/opt/sentora-goaccess --enable-utf8 > "$BUILD/configure.log" 2>&1 \
    || { tail -20 "$BUILD/configure.log"; exit 1; }
make -j"$(nproc)" > "$BUILD/make.log" 2>&1 || { grep -i "error" "$BUILD/make.log" | head -20; exit 1; }
make install > "$BUILD/install.log" 2>&1
echo "$(/opt/sentora-goaccess/bin/goaccess --version | head -1) installed in /opt/sentora-goaccess"
