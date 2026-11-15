#!/bin/bash
# A probe, not a test (Actions > Trivy probe, 2026-11-15): which Trivy release to pin for Docker Hosting's image
# check, and how the catalog's images score today (the rule "block critical holes that have a fix" must not refuse
# the apps customers use most). Trivy's own releases were attacked in March 2026: the probe lists the releases
# with their dates, and checks the chosen one against its published checksums.
set -u
say() { echo; echo "=== $*"; }
say "Trivy releases (newest first)"
curl -fsSL "https://api.github.com/repos/aquasecurity/trivy/releases?per_page=15" \
  | python3 -c 'import json,sys; [print(r["tag_name"], r["published_at"], "pre" if r["prerelease"] else "", "draft" if r["draft"] else "") for r in json.load(sys.stdin)]'
V=${TRIVY_VERSION:-$(curl -fsSL https://api.github.com/repos/aquasecurity/trivy/releases/latest | python3 -c 'import json,sys; print(json.load(sys.stdin)["tag_name"])')}
V=${V#v}
say "Chosen: $V"
d=$(mktemp -d); cd "$d" || exit 1
for f in "trivy_${V}_Linux-64bit.tar.gz" "trivy_${V}_Linux-ARM64.tar.gz" "trivy_${V}_checksums.txt"; do
    curl -fsSLO "https://github.com/aquasecurity/trivy/releases/download/v${V}/$f" || echo "no $f"
done
grep -E "Linux-(64bit|ARM64)\.tar\.gz$" "trivy_${V}_checksums.txt"
sha256sum -c --ignore-missing "trivy_${V}_checksums.txt"
tar -xzf "trivy_${V}_Linux-64bit.tar.gz" trivy && ./trivy --version
say "The vulnerability database (where it comes from, how big)"
time ./trivy image --download-db-only --cache-dir "$d/cache" 2>&1 | tail -3
du -sh "$d/cache/db"
for img in docker.io/louislam/uptime-kuma:1 docker.io/gitea/gitea:1 docker.io/vaultwarden/server:latest docker.io/library/nextcloud:stable-apache ghcr.io/sissbruecker/linkding:1 docker.io/library/nginx:1; do
    say "$img"
    podman pull -q "$img" > /dev/null 2>&1 || { echo "pull failed"; continue; }
    podman image save -o img.tar "$img" > /dev/null 2>&1; ls -la img.tar | awk '{print "tar:", $5}'
    /usr/bin/time -f "time %es, memory %MkB" ./trivy image --input img.tar --cache-dir "$d/cache" --skip-db-update --scanners vuln --format json --quiet -o out.json 2>&1 | tail -2
    python3 - out.json <<'PY'
import json, sys, collections
d = json.load(open(sys.argv[1]))
c = collections.Counter(); fix = collections.Counter(); top = []
for r in d.get("Results") or []:
    for v in r.get("Vulnerabilities") or []:
        s = v.get("Severity", "UNKNOWN"); c[s] += 1
        if v.get("FixedVersion"):
            fix[s] += 1
            if s == "CRITICAL" and len(top) < 6: top.append(f'{v.get("PkgName")} {v.get("InstalledVersion")} -> {v.get("FixedVersion")} {v.get("VulnerabilityID")} ({r.get("Type")})')
for s in ("CRITICAL", "HIGH", "MEDIUM", "LOW"):
    print(f"  {s:8} {c[s]:4}  with a fix: {fix[s]}")
for t in top: print("   ", t)
PY
    rm -f img.tar out.json; podman rmi -f "$img" > /dev/null 2>&1
done
