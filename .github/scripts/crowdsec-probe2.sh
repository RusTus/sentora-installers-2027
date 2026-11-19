#!/bin/bash
# CrowdSec probe, round 2 (2026-11-19): the repository lines and signing key CrowdSec's setup script writes (to be
# written by the node installer itself, key pinned), and whether CrowdSec stays local with the central API
# switched off (no registration, no connection to api.crowdsec.net), the Caddy collection, its log format.
set -u
SYS=$1
say() { echo; echo "=== $*"; }
case "$SYS" in debian13) export DEBIAN_FRONTEND=noninteractive; apt-get -qq update > /dev/null; apt-get -qq install -y curl ca-certificates gnupg > /dev/null ;; *) dnf -q -y install curl gnupg2 > /dev/null 2>&1 ;; esac
curl -fsSL https://install.crowdsec.net -o /tmp/s.sh && bash /tmp/s.sh > /dev/null 2>&1
say "$SYS: the repository it wrote"
cat /etc/apt/sources.list.d/crowdsec_crowdsec.list /etc/yum.repos.d/crowdsec_crowdsec.repo 2> /dev/null
say "$SYS: its signing keys"
for k in /etc/apt/keyrings/crowdsec_crowdsec-archive-keyring.gpg /usr/share/keyrings/crowdsec_crowdsec-archive-keyring.gpg; do [ -f "$k" ] && gpg --show-keys --with-fingerprint "$k" 2>/dev/null | grep -A1 "^pub"; done
curl -fsSL https://packagecloud.io/crowdsec/crowdsec/gpgkey | gpg --show-keys --with-fingerprint 2>/dev/null | grep -B1 -A2 "^pub"
[ "$SYS" != debian13 ] && rpm -q gpg-pubkey --qf '%{name}-%{version}-%{release} %{summary}\n' | grep -i crowdsec
case "$SYS" in debian13) apt-get -qq update > /dev/null; apt-get -qq install -y crowdsec > /dev/null 2>&1 ;; *) dnf -q -y install crowdsec > /dev/null 2>&1 ;; esac
cscli version 2>&1 | head -1
say "$SYS: the Caddy collection"
cscli collections inspect crowdsecurity/caddy 2>&1 | grep -E "^(name|version|description)|parsers|scenarios" -A3 | head -20
cscli collections install crowdsecurity/caddy 2>&1 | tail -2
ls /etc/crowdsec/acquis.d/ 2>/dev/null; cat /etc/crowdsec/acquis.yaml 2>/dev/null | head -20
say "$SYS: local only: central API off"
rm -f /etc/crowdsec/online_api_credentials.yaml
cat > /etc/crowdsec/config.yaml.local <<'YAML'
api:
  server:
    online_client:
YAML
crowdsec -c /etc/crowdsec/config.yaml -t 2>&1 | tail -3; echo "config test: $?"
timeout 25 crowdsec -c /etc/crowdsec/config.yaml > /tmp/run.log 2>&1
grep -iE "central|capi|online|api.crowdsec|push|signal|error|fatal" /tmp/run.log | cut -c1-200 | head -15
echo "connections to CrowdSec's servers (getent on api.crowdsec.net):"; getent hosts api.crowdsec.net
say "$SYS: allowlists"
cscli allowlists --help 2>&1 | head -5
