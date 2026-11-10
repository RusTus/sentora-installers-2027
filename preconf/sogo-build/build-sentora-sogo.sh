#!/bin/bash
#
# Build SOGo (webmail groupware: calendar, contacts, ActiveSync) for Sentora on AlmaLinux 10 / Rocky 10,
# where the system has no SOGo package, and package it the way sentora2027-alma10 expects:
#     sentora-sogo-alma10-amd64.tar.gz    (everything under /opt/sentora-sogo)
# Debian and Ubuntu use their own SOGo packages; this is only for systems without one.
#
# Built from the free sources, in this order, all into /opt/sentora-sogo (nothing of the system's
# changes): libobjc2 (the Objective-C runtime; AlmaLinux 10 has no gcc-objc, so clang builds it),
# GNUstep make and base, libwbxml (ActiveSync), ytnef (Outlook attachments), SOPE and SOGo. Run by GitHub Actions (workflow
# "SOGo bundle"); nothing is ever compiled on a customer's server.
#
# Usage (as root, on an AlmaLinux 10 machine or container):
#     bash build-sentora-sogo.sh [SOGo version, e.g. 5.12.13]   (empty: the newest release)
# Versions of the parts below can be changed with the environment: LIBOBJC2, GNUSTEP_MAKE,
# GNUSTEP_BASE, LIBWBXML, YTNEF.
set -euo pipefail

SOGO="${1:-}"
LIBOBJC2="${LIBOBJC2:-v2.3}"
GNUSTEP_MAKE="${GNUSTEP_MAKE:-make-2_9_3}"
GNUSTEP_BASE="${GNUSTEP_BASE:-base-1_31_1}"
# (Fedora's newer clang needs GNUstep base's development branch: base 1.31.1 does not build with it)
[[ "$(. /etc/os-release; echo "$ID")" == fedora && -z "${GNUSTEP_BASE_SET:-}" ]] && GNUSTEP_BASE=master
LIBWBXML="${LIBWBXML:-libwbxml-0.11.10}"
YTNEF="${YTNEF:-v2.1.2}"
PREFIX=/opt/sentora-sogo
OUT="${OUT:-$PWD}"
WORK="${WORK:-/tmp/sogo-build}"
JOBS=$(nproc)

. /etc/os-release
if [[ "${ID:-}" =~ ^(almalinux|rocky)$ && "${VERSION_ID%%.*}" == "10" ]]; then
    OS_TAG=alma10
elif [[ "${ID:-}" == "fedora" ]]; then
    OS_TAG="fedora${VERSION_ID:-}"
else
    echo "This builds SOGo for AlmaLinux 10 / Rocky 10 or Fedora (this is ${PRETTY_NAME:-unknown})."; exit 2
fi
if [ -z "$SOGO" ]; then
    SOGO=$(git ls-remote --tags https://github.com/Alinto/sogo | grep -oE 'SOGo-[0-9]+\.[0-9]+\.[0-9]+$' | sed 's/SOGo-//' | sort -V | tail -1)
fi
[[ "$SOGO" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "No SOGo version ($SOGO)"; exit 2; }
echo "== SOGo $SOGO (SOPE $SOGO), libobjc2 $LIBOBJC2, $GNUSTEP_MAKE, $GNUSTEP_BASE, $LIBWBXML"

step() { printf '\n==== %s  (%s)\n' "$1" "$(date '+%H:%M:%S')"; }

step "Build tools and libraries ($OS_TAG)"
if [ "$OS_TAG" = alma10 ]; then
    dnf -y -q install epel-release dnf-plugins-core > /dev/null
    dnf config-manager --set-enabled crb
fi
dnf -y -q install clang lld cmake make git tar gzip xz which findutils patch diffutils pkgconf-pkg-config \
    gnutls-devel libffi-devel libxml2-devel libxslt-devel libicu-devel libcurl-devel openldap-devel \
    mariadb-connector-c-devel libmemcached-awesome-devel libzip-devel libsodium-devel liboath-devel \
    openssl-devel zlib-devel expat-devel libuuid-devel python3 autoconf automake libtool > /dev/null

export CC=clang CXX=clang++
# GNUstep, SOPE and SOGo are older C/Objective-C: what newer clang turns into errors stays a warning
# for them (newer Fedora clang refuses even GNUstep base's own configure check otherwise)
RELAX="-Wno-error=incompatible-function-pointer-types -Wno-error=incompatible-pointer-types -Wno-error=int-conversion -Wno-error=implicit-function-declaration -Wno-error=implicit-int -Wno-error=return-type"
export CFLAGS="-g -O2 $RELAX" OBJCFLAGS="-g -O2 $RELAX"
export PATH="$PREFIX/bin:$PATH"
export LD_LIBRARY_PATH="$PREFIX/lib64:$PREFIX/lib"
export PKG_CONFIG_PATH="$PREFIX/lib64/pkgconfig:$PREFIX/lib/pkgconfig"
rm -rf "$WORK" "$PREFIX"; mkdir -p "$WORK" "$PREFIX"; cd "$WORK"
fetch() { git -c advice.detachedHead=false clone -q --depth 1 --branch "$2" --recurse-submodules "https://github.com/$1" "$3"; }

step "libobjc2 $LIBOBJC2"
fetch gnustep/libobjc2 "$LIBOBJC2" libobjc2
cmake -S libobjc2 -B libobjc2/build -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="$PREFIX" \
    -DCMAKE_INSTALL_LIBDIR=lib64 -DTESTS=OFF -DCMAKE_C_COMPILER=clang -DCMAKE_CXX_COMPILER=clang++ > /dev/null
cmake --build libobjc2/build -j"$JOBS" > /dev/null
cmake --install libobjc2/build > /dev/null

step "GNUstep make ($GNUSTEP_MAKE)"
fetch gnustep/tools-make "$GNUSTEP_MAKE" tools-make
( cd tools-make && ./configure --prefix="$PREFIX" --with-layout=fhs --with-library-combo=ng-gnu-gnu \
    --with-libdir=lib64 --with-objc-lib-flag="-L$PREFIX/lib64 -lobjc" --enable-objc-arc --disable-strict-v2-mode > /dev/null \
    && make install > /dev/null )
# (GNUstep's own script reads variables that may not be set)
set +u
# shellcheck disable=SC1091
. "$PREFIX/share/GNUstep/Makefiles/GNUstep.sh"
set -u

step "GNUstep base ($GNUSTEP_BASE)"
fetch gnustep/libs-base "$GNUSTEP_BASE" libs-base
( cd libs-base && ./configure --disable-importing-config-file --with-installation-domain=SYSTEM > /dev/null \
    && make -j"$JOBS" > /dev/null && make install > /dev/null )

step "libwbxml ($LIBWBXML)"
fetch libwbxml/libwbxml "$LIBWBXML" libwbxml
cmake -S libwbxml -B libwbxml/build -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="$PREFIX" \
    -DCMAKE_INSTALL_LIBDIR=lib64 -DLIB_SUFFIX=64 -DBUILD_DOCUMENTATION=OFF -DENABLE_UNIT_TEST=OFF > /dev/null
cmake --build libwbxml/build -j"$JOBS" > /dev/null
cmake --install libwbxml/build > /dev/null

step "ytnef ($YTNEF)"
fetch Yeraze/ytnef "$YTNEF" ytnef
( cd ytnef && autoreconf -fi > /dev/null 2>&1 && ./configure --prefix="$PREFIX" --libdir="$PREFIX/lib64" > /dev/null \
    && make -j"$JOBS" > /dev/null && make install > /dev/null )

step "SOPE $SOGO"
fetch Alinto/sope "SOPE-$SOGO" sope
( cd sope && ./configure --with-gnustep --enable-strip > /dev/null && make -j"$JOBS" CC="clang $RELAX" > /dev/null && make install CC="clang $RELAX" > /dev/null )

step "SOGo $SOGO (with ActiveSync)"
fetch Alinto/sogo "SOGo-$SOGO" sogo
# OpenSSL 4 (Fedora) no longer lets programs read inside ASN1_STRING: its own accessors do the same and
# exist in OpenSSL 3 too, so every system builds the same code
sed -i -e 's|p = cap->value.sequence->data;|p = ASN1_STRING_get0_data(cap->value.sequence);|' \
       -e 's|ASN1_item_d2i(NULL, &p, cap->value.sequence->length,|ASN1_item_d2i(NULL, \&p, ASN1_STRING_length(cap->value.sequence),|' \
    sogo/SoObjects/Mailer/NSData+SMIME.m
( cd sogo && LDFLAGS="-L$PREFIX/lib64" CPPFLAGS="-I$PREFIX/include" ./configure --enable-mfa --enable-sodium --enable-strip > /dev/null \
    && make CC="clang $RELAX" > /dev/null && make install CC="clang $RELAX" > /dev/null \
    && make -C ActiveSync -j"$JOBS" CC="clang $RELAX -L$PREFIX/lib64" > /dev/null \
    && make -C ActiveSync install CC="clang $RELAX -L$PREFIX/lib64" > /dev/null )

step "Packaging"
# SOGo's backup script (the same one Debian's package installs as sogo-backup)
install -m 755 sogo/Scripts/sogo-backup.sh "$PREFIX/sbin/sogo-backup"
# SOGo's programs need only their library path (the installer's service and tool wrappers set it)
cat > "$PREFIX/sogo-env.sh" <<EOF
# SOGo's environment (for a shell: . $PREFIX/sogo-env.sh): Sentora's own build in $PREFIX
export LD_LIBRARY_PATH=$PREFIX/lib64:$PREFIX/lib\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}
export PATH=$PREFIX/sbin:$PREFIX/bin:\$PATH
EOF
SOGOD="$PREFIX/sbin/sogod"
# it must start: every library it and ActiveSync need is there (none "not found")
for f in "$SOGOD" "$PREFIX/sbin/sogo-tool" "$PREFIX/lib64/GNUstep/SOGo/ActiveSync.SOGo/ActiveSync"; do
    [ -e "$f" ] || { echo "$f was not built"; exit 1; }
    if LD_LIBRARY_PATH="$PREFIX/lib64:$PREFIX/lib" ldd "$f" | grep 'not found'; then echo "$f: libraries missing"; exit 1; fi
done
# the system libraries it links to: the installer installs whichever of them a server is missing
# (by file, so dnf picks the package that has it, e.g. libcurl or libcurl-minimal)
# (ldd fails for the scripts among them: that is expected)
{ find "$PREFIX" -type f \( -perm -u+x -o -name '*.so*' \) -print0 \
    | xargs -0 -r env LD_LIBRARY_PATH="$PREFIX/lib64:$PREFIX/lib" ldd 2> /dev/null || true; } \
    | awk '$2 == "=>" && $3 ~ /^\// {print $3}' | grep -v "^$PREFIX/" | sed 's|^/lib64/|/usr/lib64/|' | sort -u > "$PREFIX/RUNTIME-LIBS"
echo "System libraries it needs: $(wc -l < "$PREFIX/RUNTIME-LIBS")"
cat > "$PREFIX/BUILD-INFO" <<EOF
system=$OS_TAG
sogo=$SOGO
libobjc2=$LIBOBJC2
gnustep-make=$GNUSTEP_MAKE
gnustep-base=$GNUSTEP_BASE
libwbxml=$LIBWBXML
ytnef=$YTNEF
built=$(date -u '+%Y-%m-%d %H:%M UTC')
EOF
TAR="$OUT/sentora-sogo-$OS_TAG-amd64.tar.gz"
tar -C / -czf "$TAR" "${PREFIX#/}"
sha256sum "$TAR" | tee "$TAR.sha256"
cat "$PREFIX/BUILD-INFO"
echo "Done: $TAR ($(du -h "$TAR" | cut -f1))"
