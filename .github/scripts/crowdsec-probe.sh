#!/bin/bash
# A probe, not a test (Actions > CrowdSec probe, 2026-11-19): where CrowdSec and its nftables firewall blocker come
# from on each supported system (its own packages, EPEL, or CrowdSec's own repository), which versions, and what
# the package does by itself at install: whether it registers with CrowdSec's central servers (sharing), which
# collections it picks, whether the blocker starts. Decides how the Docker node installs it ("local by default,
# sharing an administrator's switch", owner 2026-11-19).
set -u
SYS=$1
say() { echo; echo "=== $*"; }
say "$SYS: from the system's own sources"
case "$SYS" in
    debian13|ubuntu26.04)
        export DEBIAN_FRONTEND=noninteractive
        apt-get -qq update > /dev/null; apt-get -qq install -y curl ca-certificates gnupg nftables > /dev/null
        apt-cache policy crowdsec crowdsec-firewall-bouncer crowdsec-firewall-bouncer-nftables 2>&1 | grep -E "^[a-z]|Candidate"
        ;;
    *)
        dnf -q -y install curl nftables > /dev/null 2>&1
        dnf -q info crowdsec crowdsec-firewall-bouncer-nftables 2>&1 | grep -E "^(Name|Version|Repository)" || echo "  not in the system's repositories"
        if [ "$SYS" != fedora45 ]; then dnf -q -y install epel-release > /dev/null 2>&1 && { echo "  with EPEL:"; dnf -q info crowdsec crowdsec-firewall-bouncer-nftables 2>&1 | grep -E "^(Name|Version|Repository)" || echo "  not in EPEL"; }; fi
        ;;
esac
say "$SYS: CrowdSec's own repository (what its setup script would add)"
curl -fsSL https://install.crowdsec.net -o /tmp/cs-install.sh && head -c 0 /tmp/cs-install.sh && grep -oE "packagecloud[^\"' ]*|https://[a-z.]*crowdsec[a-z./-]*" /tmp/cs-install.sh | sort -u | head -8
bash /tmp/cs-install.sh > /tmp/cs-repo.log 2>&1; echo "  setup script exit: $?"; tail -3 /tmp/cs-repo.log
case "$SYS" in
    debian13|ubuntu26.04) apt-get -qq update > /dev/null; apt-cache policy crowdsec crowdsec-firewall-bouncer-nftables 2>&1 | grep -E "^[a-z]|Candidate"
        apt-get -qq install -y crowdsec > /tmp/cs.log 2>&1; echo "  install crowdsec: $?"; apt-get -qq install -y crowdsec-firewall-bouncer-nftables >> /tmp/cs.log 2>&1; echo "  install bouncer: $?" ;;
    *) dnf -q info crowdsec crowdsec-firewall-bouncer-nftables 2>&1 | grep -E "^(Name|Version|Repository)"
        dnf -q -y install crowdsec > /tmp/cs.log 2>&1; echo "  install crowdsec: $?"; dnf -q -y install crowdsec-firewall-bouncer-nftables >> /tmp/cs.log 2>&1; echo "  install bouncer: $?" ;;
esac
tail -5 /tmp/cs.log
say "$SYS: what the package did by itself"
cscli version 2>&1 | head -3
echo "  online_client (sharing) in config.yaml:"; grep -A3 "online_client" /etc/crowdsec/config.yaml | sed 's/^/    /'
ls -la /etc/crowdsec/online_api_credentials.yaml 2>&1 | sed 's/^/  /'; grep -c "login" /etc/crowdsec/online_api_credentials.yaml 2>/dev/null | sed 's/^/  credentials lines: /'
echo "  collections:"; cscli collections list -o raw 2>/dev/null | cut -d, -f1,2 | sed 's/^/    /' | head -20
echo "  console/usage options:"; grep -E "share_|console" -A0 /etc/crowdsec/console.yaml 2>/dev/null | sed 's/^/    /'
echo "  bouncer config:"; grep -E "^(mode|api_url|deny_action|nftables)" /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml 2>/dev/null | sed 's/^/    /'
echo "  hub collections that exist for us:"; cscli collections list -a -o raw 2>/dev/null | grep -iE "caddy|sshd|linux|coraza|appsec|base-http" | cut -d, -f1 | sed 's/^/    /'
