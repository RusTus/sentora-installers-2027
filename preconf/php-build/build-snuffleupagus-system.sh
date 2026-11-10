#!/bin/bash
#
# Build Snuffleupagus (the PHP hardening extension, snuffleupagus.sh) for the system's own PHP on
# Ubuntu 26.04, Debian 13 or AlmaLinux 10 (amd64), as one file:
#     sentora-snuffleupagus-<version>-php<major.minor>-<system>-amd64.so
# The PHP bundles carry their own copy; this one is for the PHP the system installs. It stays good
# through the system's PHP security updates (same major.minor): a new system PHP version needs a new
# build. The PHP Hardening add-on downloads it (checked against php-bundles.json) and switches it on.
#
# Usage (as root, on that system; a container is best): bash build-snuffleupagus-system.sh
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
. "$HERE/snuffleupagus.sh"

. /etc/os-release
if [[ "${ID:-} ${VERSION_ID:-}" == "ubuntu 26.04" ]]; then
    OS_TAG="ubuntu26.04"
elif [[ "${ID:-} ${VERSION_ID:-}" == "debian 13" ]]; then
    OS_TAG="debian13"
elif [[ "${ID:-}" =~ ^(almalinux|rocky)$ && "${VERSION_ID%%.*}" == "10" ]]; then
    OS_TAG="alma10"
elif [[ "${ID:-}" == "fedora" ]]; then
    OS_TAG="fedora${VERSION_ID:-}"
else
    OS_TAG=""
fi
if [ -z "$OS_TAG" ] || [ "$(uname -m)" != "x86_64" ]; then
    echo "This builds for Ubuntu 26.04, Debian 13 or AlmaLinux 10 amd64 only (this is ${PRETTY_NAME:-unknown} $(uname -m))."
    exit 1
fi

# The system's PHP and its development files (phpize, php-config, headers)
if [ "${SKIP_APT:-0}" != "1" ]; then
    if [[ "$OS_TAG" == fedora* ]]; then
        dnf -y -q install php-cli php-devel gcc make git
    elif [ "$OS_TAG" = alma10 ]; then
        dnf -y -q install dnf-plugins-core
        dnf config-manager --set-enabled crb
        dnf -y -q install php-cli php-devel gcc make git
    else
        apt-get -qq update
        DEBIAN_FRONTEND=noninteractive apt-get -yqq install php-cli php-dev build-essential git
    fi
fi

MM=$(php-config --version | cut -d. -f1,2)
OUT="$(pwd)/sentora-snuffleupagus-$SP_VERSION-php$MM-$OS_TAG-amd64.so"
WORK=$(mktemp -d /tmp/sentora-sp-build.XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# Installed into a folder of its own (not the system's extension folder): a php-config that says so
mkdir -p "$WORK/ext"
cat > "$WORK/php-config" <<EOF
#!/bin/bash
[ "\${1:-}" = --extension-dir ] && { echo "$WORK/ext"; exit 0; }
exec "$(command -v php-config)" "\$@"
EOF
chmod 755 "$WORK/php-config"

echo "System PHP $MM ($(php -r 'echo PHP_VERSION;')) on $OS_TAG"
sp_build "$WORK" "$(command -v phpize)" "$WORK/php-config" || exit 1
strip --strip-unneeded "$WORK/ext/snuffleupagus.so" 2> /dev/null || true
sp_check "$(command -v php)" "$WORK/ext" || { echo "Snuffleupagus does not load into the system's PHP. Stopping."; exit 1; }
cp "$WORK/ext/snuffleupagus.so" "$OUT"
sha256sum "$OUT"
echo "Done: $OUT"
