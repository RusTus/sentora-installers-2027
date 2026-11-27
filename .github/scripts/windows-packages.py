#!/usr/bin/env python3
"""The pinned downloads of the native Windows installer ($Pkg in windows/server/install-zpanel-stack.ps1), one per line:
FILE<TAB>SHA256<TAB>URL[<TAB>URL...] (the first URL is the package's own, then its fallbacks)."""
import re, sys, os
src = open(sys.argv[1] if len(sys.argv) > 1 else 'windows/server/install-zpanel-stack.ps1', encoding='utf-8').read()
start = src.index('$Pkg = @{')
body = src[start:src.index('\n}\n', start)]
# (each entry starts on its own line: "    Name = @{ ...", and runs to the next one)
parts = re.split(r"\n    \w+\s*=\s*@\{", body)[1:]
for blk in parts:
    url = re.search(r"Url\s*=\s*'([^']+)'", blk)
    sha = re.search(r"Sha\s*=\s*'([0-9A-Fa-f]{64})'", blk)
    if not url or not sha:
        continue
    more = re.search(r"More\s*=\s*@\(([^)]*)\)", blk)
    urls = [url.group(1)] + (re.findall(r"'([^']+)'", more.group(1)) if more else [])
    name = os.path.basename(url.group(1).split('?')[0])
    print('\t'.join([name, sha.group(1).upper()] + urls))
