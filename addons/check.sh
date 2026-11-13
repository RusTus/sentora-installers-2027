#!/bin/bash
# Checks that what Repo Browser offers (sentora-repo/*.zpp) is what is in addons/<name>/: the same files with
# the same contents, module.xml's <version> = sentora-repo/<name>.xml's <latestversion>, and the zip's line in
# sentora-repo/SHA256SUMS right. Every .zpp has its folder and every folder its .zpp. Prints what differs and
# how to fix it (usually: addons/build.sh <name>). The "Add-ons" workflow runs it on every push.
#   addons/check.sh [<name> ...]     (no names: all)
set -uo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
FAILS=0
bad() { echo "  FAIL $*"; FAILS=$((FAILS + 1)); }
cd "$REPO" || exit 2
if [ $# -gt 0 ]; then NAMES=("${@%/}"); else
    NAMES=()
    for d in addons/*/; do NAMES+=("$(basename "$d")"); done
    for z in sentora-repo/*.zpp; do n=$(basename "$z" .zpp); [ -d "addons/$n" ] || bad "$z has no folder addons/$n (its source)"; done
fi
for n in "${NAMES[@]}"; do
    z="sentora-repo/$n.zpp" src="addons/$n" f0=$FAILS
    if [ ! -f "$z" ]; then bad "addons/$n has no $z: addons/build.sh $n"; continue; fi
    t=$(mktemp -d)
    unzip -q "$z" -d "$t" || { bad "$z: not a zip"; rm -rf "$t"; continue; }
    d=$(diff -r "$src" "$t" 2>&1)
    if [ -n "$d" ]; then bad "$z is not what is in $src (addons/build.sh $n):"; echo "$d" | head -20 | sed 's/^/         /'; fi
    rm -rf "$t"
    v=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$src/module.xml" | head -1)
    l=$(sed -n 's:.*<latestversion>\([^<]*\)</latestversion>.*:\1:p' "sentora-repo/$n.xml" 2> /dev/null | head -1)
    [ -n "$v" ] && [ "$v" = "$l" ] || bad "$n: module.xml says version ${v:-?}, sentora-repo/$n.xml says ${l:-?} (addons/build.sh $n sets it)"
    s=$(sha256sum "$z" | cut -d' ' -f1)
    grep -qx "$s  $n.zpp" sentora-repo/SHA256SUMS || bad "$n: its line in sentora-repo/SHA256SUMS is not its SHA-256 (addons/build.sh $n puts it right)"
    [ $FAILS -eq $f0 ] && echo "  ok   $n $v"
done
[ "$(LC_ALL=C sort -k2 sentora-repo/SHA256SUMS)" = "$(cat sentora-repo/SHA256SUMS)" ] || bad "sentora-repo/SHA256SUMS is not sorted by name"
[ $FAILS -eq 0 ] && echo "ADD-ONS: every .zpp matches its folder" || { echo "ADD-ONS: $FAILS FAILED"; exit 1; }
