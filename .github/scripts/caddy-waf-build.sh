#!/bin/bash
# Caddy with the Coraza web firewall for the Docker nodes (Docker Hosting's "Web firewall", 2026-11-16): built
# here, one file per processor (Caddy is one static program, the same on every supported Linux), with the
# versions named in the workflow (or the newest, printed, to be pinned). The node installer pins the files'
# SHA-256 in git, so a file changed on the release is refused.
set -euo pipefail
latest() { curl -fsSL "https://api.github.com/repos/$1/releases/latest" | python3 -c 'import json,sys; print(json.load(sys.stdin)["tag_name"])'; }
CADDY=${CADDY_VERSION:-}; CORAZA=${CORAZA_VERSION:-}; XCADDY=${XCADDY_VERSION:-}
[ -n "$CADDY" ] || CADDY=$(latest caddyserver/caddy)
[ -n "$CORAZA" ] || CORAZA=$(latest corazawaf/coraza-caddy)
[ -n "$XCADDY" ] || XCADDY=$(latest caddyserver/xcaddy)
echo "Caddy $CADDY, coraza-caddy $CORAZA, xcaddy $XCADDY, $(go version)"
go install "github.com/caddyserver/xcaddy/cmd/xcaddy@$XCADDY"
mkdir -p dist
for arch in amd64 arm64; do
    CGO_ENABLED=0 GOOS=linux GOARCH=$arch "$(go env GOPATH)/bin/xcaddy" build "$CADDY" --with "github.com/corazawaf/coraza-caddy/v2@$CORAZA" --output "dist/caddy-sentora-$arch"
done
./dist/caddy-sentora-amd64 version
./dist/caddy-sentora-amd64 list-modules | grep -x 'http.handlers.waf'
(cd dist && sha256sum caddy-sentora-* > SHA256SUMS && cat SHA256SUMS)
printf 'CADDY=%s\nCORAZA=%s\nXCADDY=%s\nGO=%s\n' "$CADDY" "$CORAZA" "$XCADDY" "$(go env GOVERSION)" > dist/versions.txt
cat dist/versions.txt
echo "tag=caddy-waf-${CADDY#v}-coraza-${CORAZA#v}" >> "${GITHUB_OUTPUT:-/dev/null}"
