#!/bin/bash
# Builds an add-on's .zpp for Repo Browser from its folder here (addons/<name>/), the one place an add-on
# is changed. The zip always comes out the same for the same files (fixed dates, fixed order, fixed
# permissions), so a build on any computer gives the same SHA-256.
#
#   addons/build.sh <name> [<name> ...]     e.g. addons/build.sh docker_hosting
#
# For each name it writes sentora-repo/<name>.zpp, sets <latestversion> in sentora-repo/<name>.xml to the
# <version> in the add-on's module.xml, and puts the zip's line in sentora-repo/SHA256SUMS. Raise the
# version in module.xml first (Repo Browser offers an update only for a higher number).
# addons/check.sh then confirms every .zpp matches its folder (the "Add-ons" workflow runs it on every push).
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
die() { echo "STOPPED: $*" >&2; exit 1; }
command -v zip > /dev/null || die "zip is not installed (apt install zip / dnf install zip)."
[ $# -gt 0 ] || die "which add-on? addons/build.sh <name> (the folders in addons/)"

build() {
    local n=$1 src="$REPO/addons/$1" tmp ver out
    [[ "$n" =~ ^[A-Za-z0-9_-]+$ ]] && [ -f "$src/module.xml" ] || die "no add-on folder addons/$n (with its module.xml)"
    [ -f "$REPO/sentora-repo/$n.xml" ] || die "sentora-repo/$n.xml is missing (Repo Browser's entry for it)"
    ver=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$src/module.xml" | head -1)
    [[ "$ver" =~ ^[0-9]+$ ]] || die "addons/$n/module.xml has no <version> number"
    tmp=$(mktemp -d)
    cp -a "$src" "$tmp/m"
    (
        cd "$tmp/m"
        find . -type d -exec chmod 755 {} +
        find . -type f -perm -u+x -exec chmod 755 {} +
        find . -type f ! -perm -u+x -exec chmod 644 {} +
        find . -exec touch -h -d '2026-01-01 00:00:00 UTC' {} +
        # module.xml and module.zpm first, then every folder and file by name (folders before what is in them)
        { printf 'module.xml\nmodule.zpm\n'; find . -mindepth 1 ! -path ./module.xml ! -path ./module.zpm | sed 's#^\./##' | while IFS= read -r p; do [ -d "$p" ] && echo "$p/" || echo "$p"; done | LC_ALL=C sort; } \
            | TZ=UTC zip -qX "$tmp/out.zpp" -@
    )
    out="$REPO/sentora-repo/$n.zpp"
    mv "$tmp/out.zpp" "$out"
    rm -rf "$tmp"
    sed -i "s:<latestversion>[^<]*</latestversion>:<latestversion>$ver</latestversion>:" "$REPO/sentora-repo/$n.xml"
    (
        cd "$REPO/sentora-repo"
        { grep -v "  $n\.zpp\$" SHA256SUMS || true; sha256sum "$n.zpp"; } | LC_ALL=C sort -k2 > SHA256SUMS.new
        mv SHA256SUMS.new SHA256SUMS
    )
    echo "built sentora-repo/$n.zpp (version $ver, $(unzip -Z1 "$out" | grep -vc '/$') files, sha256 $(sha256sum "$out" | cut -c1-16)...)"
}
for n in "$@"; do build "${n%/}"; done
