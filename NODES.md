# Sentora nodes: one way to install a node

A **node** is a server of its own that extends the panel: a Docker node (Docker Hosting add-on), the
backup node (Backup Config), and whatever node types add-ons bring later. Each node type has its own
installer, but every installer does the same steps, so the person setting one up always does the same
three things:

1. **On the new server**, run that node type's installer. It asks for the panel's address, fetches the
   panel's public key for that node type, and shows **the panel's code** (`XXXX-XXXX`). The person
   answers `y` only when it is the code on the panel's page. Now the node knows it talks to this panel.
2. The installer sets the node up and ends with **the node's key**: the node type's prefix, then four
   groups of four (`SB1-7VYE-H938-VH5U-BVF8`), made from the node's own key. A command on the node
   shows it again (`sentora-backup-node key`).
3. **On the panel**, "Add the ... node": the address and that key. The panel checks the key against the
   key the node really answers with, and only then trusts it, for good (it pins the key; another key is
   refused later).

Nothing secret is typed or pasted: the panel's code and the node's key are fingerprints, and each side
checks the other's by itself. Codes are base32 without the letters people mix up (no I, O, 0 or 1).

| Node type | Installer | Panel's page | Prefix | Connection |
|---|---|---|---|---|
| Backup node | backup-node/sentora2027-backup-node | Module Admin > Backup Config > Off-server backup | SB1 | SSH (the panel calls the node; the node's SSH key) |
| Docker node | docker-node/sentora2027-docker-node | Docker Hosting > Nodes and apps | (its own) | HTTPS (the node calls the panel; signed and sealed messages with the add-on's own keys) |

## The panel's side: `sys_nodes` (core, since 2027.86)

`dryden/sys/nodes.class.php`. An add-on uses it behind `class_exists('sys_nodes')`, with a fallback (or
a "needs panel 2027.86" message) for older panels.

- `sys_nodes::PanelCode($type, $publicKey)`: the panel's code for one node type (show it on your page).
  `$type` is your node type's name (`sentora-backup`, `myaddon`), the same string your installer uses.
- `sys_nodes::SshKeyCode($line)`, `NodeKey($prefix, $code)`, `ParseKey($prefix, $typed)`: a node's key
  from an SSH public key line, shown and read back.
- `sys_nodes::ScanSshKey($host, $port)`: the key a server answers with (root: run it in your daemon hook).
- `sys_nodes::SshFingerprint($line)`: SSH's own `SHA256:...` fingerprint, to pin with `StrictHostKeyChecking=yes`.

The panel serves its public key for your node type at an address of your module with no login (like
`modules/backup_admin/code/node.php?key=1`): only the public key, nothing else.

Pairing runs in your module's daemon hook (root), not in the page: the page stores the address and the
key typed, the hook scans, compares `SshKeyCode(scan) === ParseKey(prefix, typed)`, then pins the
fingerprint and says how it went on the page. (Backup Config's hook: `modules/backup_admin/hooks/OnDaemonRun.hook.php`.)

## The node's side: the shell lines

The same codes in bash with coreutils only. Copy this block into your installer unchanged (the backup
node test checks that this copy and the backup node installer's are the same):

```bash
# BEGIN node codes
b32hex() { # hex-digits letters: the first letters of base32 of the hex
    local hex=$1 n=$2 a=ABCDEFGHJKLMNPQRSTUVWXYZ23456789 out='' half v i
    for half in 0 10; do
        [ ${#out} -ge "$n" ] && break
        v=$((16#${hex:$half:10}))
        for i in 7 6 5 4 3 2 1 0; do out=$out${a:$(( (v >> (i * 5)) & 31 )):1}; done
    done
    echo "${out:0:$n}"
}
panel_code() { # node-type public-key: the panel's code, XXXX-XXXX
    local c; c=$(b32hex "$(printf '%s|%s' "$1" "$2" | sha256sum | cut -c1-20)" 8); echo "${c:0:4}-${c:4:4}"
}
ssh_key_code() { # public-key-file: the 16 letters of a node's key
    b32hex "$(awk '{print $2}' "$1" | base64 -d | sha256sum | cut -c1-20)" 16
}
node_key() { # prefix public-key-file: PREFIX-XXXX-XXXX-XXXX-XXXX
    local c; c=$(ssh_key_code "$2"); echo "$1-${c:0:4}-${c:4:4}-${c:8:4}-${c:12:4}"
}
fetch_panel() { # url: the panel's answer (a self-signed panel is fine: the person checks the code)
    local out
    if command -v curl > /dev/null 2>&1; then
        out=$(curl -fsS --max-time 20 --proto =https,http "$1" 2> /dev/null) || out=$(curl -fsSk --max-time 20 --proto =https,http "$1" 2> /dev/null) || return 1
    elif command -v wget > /dev/null 2>&1; then
        out=$(wget -qO- -T 20 "$1" 2> /dev/null) || out=$(wget -qO- -T 20 --no-check-certificate "$1" 2> /dev/null) || return 1
    else
        return 1
    fi
    printf '%s' "$out"
}
# END node codes
```

## A template installer

```bash
#!/usr/bin/env bash
# Sentora <Your> Node installer. Run as root on a server of its own.
set -u
TYPE=myaddon PREFIX=MY1 PANEL='' PANELCODE=''
while [ $# -gt 0 ]; do
    case "$1" in
        --panel) PANEL=${2:-}; shift ;;
        --panel-code) PANELCODE=${2:-}; shift ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done
die() { echo "STOPPED: $*"; exit 1; }
[ "$(id -u)" -eq 0 ] || die "Run this as root."
# (the block above goes here)

# 1. the panel, and its code checked
[ -n "$PANEL" ] || read -r -p "The panel's address: " PANEL
case "$PANEL" in https://*|http://*) ;; *) PANEL="https://$PANEL" ;; esac
PANEL=${PANEL%/}
[[ "$PANEL" =~ ^https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?$ ]] || die "That is not a panel address."
KEY=$(fetch_panel "$PANEL/modules/myaddon/code/node.php?key=1" | head -1 | tr -d '\r')
[ -n "$KEY" ] || die "Could not get the key from $PANEL."
CODE=$(panel_code "$TYPE" "$KEY")
if [ -n "$PANELCODE" ]; then
    [ "$(printf '%s' "$PANELCODE" | tr a-z A-Z)" = "$CODE" ] || die "The panel's code is $CODE, not $PANELCODE. Nothing was changed."
else
    echo "The panel's code: $CODE  (the panel's page shows it)"
    read -r -p "Is it the same code? (y/n) " ans
    [ "$ans" = y ] || die "Not the same code: nothing was changed."
fi

# 2. set the node up: packages from the system's own package manager, services, a firewall with only
#    what the node needs open; keep the panel's key so only this panel gets in
#    (SSH: restrict,command="<a gate of your own>" in an authorized_keys file only root can change)

# 3. the node's key
echo "Add it in the panel: address $(hostname -I | awk '{print $1}'), key $(node_key "$PREFIX" /etc/ssh/ssh_host_ed25519_key.pub)"
```

## Rules a node keeps

- It works on every supported system (Ubuntu 26.04, Debian 13, AlmaLinux / Rocky / RHEL 10, Fedora 45):
  one `case` on `/etc/os-release` sets the packages, services and paths.
- SELinux and AppArmor stay on; the firewall is on with only what the node needs.
- It refuses to install on a Sentora panel server.
- Nothing is trusted before the codes are checked; nothing is sent to a node before its key is pinned.
- It has a test in `.github/scripts/` that installs it on each system in a container (see
  `backup-node-test.sh`) and a workflow that runs it on every change.
