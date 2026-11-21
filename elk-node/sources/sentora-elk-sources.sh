#!/bin/bash
# Sentora Elk node: other sources than the panel (sentora-elk-node fortigate | veeam | m365 | sources).
#
#   sentora-elk-node fortigate ADDRESS   a FortiGate's logs: over TLS on 6516, from ADDRESS only, with the certificate
#                                        made here for it (/root/sentora-fortigate.p12, its password shown once)
#   sentora-elk-node veeam ADDRESS       Veeam Backup & Replication's events: over TLS on 6517, from ADDRESS only
#   sentora-elk-node m365 TENANT-ID APP-ID   Microsoft 365 / Entra: sign-ins, Teams and mail activity, service health,
#                                        read with an app registered in Entra (its secret asked for, never shown)
#   sentora-elk-node veeam-api ADDRESS USER   Veeam's REST API (read only): each machine's newest backup, each job's state
#   sentora-elk-node SOURCE off          stop taking it (what came in stays until the keeping time ends)
#   sentora-elk-node sources             what is on, and when each last sent something
#
# A FortiGate or Veeam server sends syslog; the node's rsyslog takes it (TLS, firewall: that address only) and hands
# each line to its own Logstash pipeline on 127.0.0.1 (5516, 5517). Microsoft 365 is read by the service
# sentora-elk-reader (its own user, read-only app). Each goes to its own data stream (logs-fortigate-*, logs-veeam-*,
# logs-m365-*) with its own Kibana dashboards. Written by RusTus, 2026-11-20. GPL-3.0.
set -u
umask 077
C=/etc/sentora-elk
SRC=$C/sources
RS=/etc/rsyslog.d/sentora-sources
LIB=/usr/local/lib/sentora-elk
[ "$(id -u)" -eq 0 ] || { echo "Run this as root."; exit 1; }
mkdir -p "$SRC"; chmod 700 "$SRC"

say() { printf '%s\n' "$*"; }
ip_ok() { [[ "$1" =~ ^[0-9a-fA-F:.]+$ ]] && python3 -I -c 'import ipaddress,sys; ipaddress.ip_address(sys.argv[1])' "$1" 2> /dev/null; }
es() { curl -sS --cacert $C/http_ca.crt -u "elastic:$(cat $C/elastic.pw)" -H 'Content-Type: application/json' "$@"; }

# rsyslog's own user and group (Ubuntu drops to syslog:syslog after start)
rs_group() {
    local g
    g=$(grep -hE '^\s*\$PrivDropToGroup\s' /etc/rsyslog.conf /etc/rsyslog.d/*.conf 2> /dev/null | awk '{print $2}' | tail -1)
    [ -n "$g" ] && getent group "$g" > /dev/null && echo "$g" || echo root
}

# Logstash: Sentora's pipeline, and one per source that is on
pipelines() {
    mkdir -p /etc/logstash/sources.d; chown root:logstash /etc/logstash/sources.d; chmod 750 /etc/logstash/sources.d
    {
        echo "# Sentora Elk node: one pipeline for the panel, one per other source (sentora-elk-node sources)."
        echo "- pipeline.id: sentora"
        echo "  path.config: \"/etc/logstash/conf.d/sentora.conf\""
        local s
        for s in fortigate veeam; do
            if [ -s "$SRC/$s" ] && [ -f "$C/files/$s.conf" ]; then
                install -m 640 -o root -g logstash "$C/files/$s.conf" "/etc/logstash/sources.d/$s.conf"
                echo "- pipeline.id: $s"
                echo "  path.config: \"/etc/logstash/sources.d/$s.conf\""
            else
                rm -f "/etc/logstash/sources.d/$s.conf"
            fi
        done
    } > /etc/logstash/pipelines.yml.new
    chown root:logstash /etc/logstash/pipelines.yml.new; chmod 640 /etc/logstash/pipelines.yml.new
    mv /etc/logstash/pipelines.yml.new /etc/logstash/pipelines.yml
}

# rsyslog: TLS inputs for the syslog sources that are on (and nothing when none is)
rsyslog_conf() {
    local f=/etc/rsyslog.d/40-sentora-sources.conf any=0 s
    for s in fortigate veeam; do [ -s "$SRC/$s" ] && any=1; done
    if [ "$any" = 0 ]; then
        [ -f "$f" ] && { rm -f "$f"; systemctl restart rsyslog > /dev/null 2>&1; }
        return 0
    fi
    command -v rsyslogd > /dev/null && dpkg -s rsyslog-gnutls > /dev/null 2>&1 || \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q rsyslog rsyslog-gnutls > /dev/null 2>&1 || { say "Could not install rsyslog's TLS part."; return 1; }
    local g; g=$(rs_group)
    mkdir -p "$RS"; chown root:"$g" "$RS"; chmod 750 "$RS"
    install -m 640 -o root -g "$g" $C/ca.crt "$RS/ca.crt"
    cat $C/node.crt $C/ca.crt > "$RS/node.crt.new"; install -m 640 -o root -g "$g" "$RS/node.crt.new" "$RS/node.crt"; rm -f "$RS/node.crt.new"
    install -m 640 -o root -g "$g" $C/node.key "$RS/node.key"
    # (Veeam's Windows checks the name it connects to: the certificate with this node's addresses, Kibana's)
    cat $C/web.crt $C/ca.crt > "$RS/web.crt.new"; install -m 640 -o root -g "$g" "$RS/web.crt.new" "$RS/web.crt"; rm -f "$RS/web.crt.new"
    install -m 640 -o root -g "$g" $C/web.key "$RS/web.key"
    local imtcp='module(load="imtcp")'
    # (loaded by another file already? only a line that is not a comment counts: Ubuntu ships it commented out)
    grep -lqsE '^[[:space:]]*(module\(load="imtcp"|\$ModLoad[[:space:]]+imtcp)' /etc/rsyslog.conf $(ls /etc/rsyslog.d/*.conf 2> /dev/null | grep -v 40-sentora-sources) 2> /dev/null && imtcp='# (imtcp is loaded by another file)'
    {
        echo "# Sentora Elk node: other sources over TLS (sentora-elk-node sources). Made anew by that command."
        echo "$imtcp"
        echo 'template(name="sentora_src_json" type="list" option.jsonf="on") {'
        echo '    property(outname="message" name="rawmsg" format="jsonf")'
        echo '    property(outname="from" name="fromhost-ip" format="jsonf")'
        echo '}'
        if [ -s "$SRC/fortigate" ]; then
            # (the FortiGate shows the certificate made for it: signed by this node's authority, named sentora-fortigate)
            echo "input(type=\"imtcp\" port=\"6516\" name=\"fortigate\" ruleset=\"sentora_fortigate\" MaxSessions=\"20\""
            echo "      StreamDriver.Name=\"gtls\" StreamDriver.Mode=\"1\" StreamDriver.AuthMode=\"x509/name\" PermittedPeer=[\"sentora-fortigate\"]"
            echo "      StreamDriver.CAFile=\"$RS/ca.crt\" StreamDriver.CertFile=\"$RS/node.crt\" StreamDriver.KeyFile=\"$RS/node.key\")"
            echo "ruleset(name=\"sentora_fortigate\") {"
            echo "    if \$fromhost-ip == \"$(cat "$SRC/fortigate")\" then {"
            echo '        action(type="omfwd" target="127.0.0.1" port="5516" protocol="tcp" template="sentora_src_json"'
            echo '               queue.type="LinkedList" queue.filename="sentora_fortigate" queue.maxDiskSpace="1g" queue.saveOnShutdown="on" action.resumeRetryCount="-1")'
            echo '    }'
            echo '    stop'
            echo '}'
        fi
        if [ -s "$SRC/veeam" ]; then
            # (Veeam offers TLS without a certificate of its own: its address only, here and in the firewall)
            echo "input(type=\"imtcp\" port=\"6517\" name=\"veeam\" ruleset=\"sentora_veeam\" MaxSessions=\"20\""
            echo "      StreamDriver.Name=\"gtls\" StreamDriver.Mode=\"1\" StreamDriver.AuthMode=\"anon\""
            echo "      StreamDriver.CAFile=\"$RS/ca.crt\" StreamDriver.CertFile=\"$RS/web.crt\" StreamDriver.KeyFile=\"$RS/web.key\")"
            echo "ruleset(name=\"sentora_veeam\") {"
            echo "    if \$fromhost-ip == \"$(cat "$SRC/veeam")\" then {"
            echo '        action(type="omfwd" target="127.0.0.1" port="5517" protocol="tcp" template="sentora_src_json"'
            echo '               queue.type="LinkedList" queue.filename="sentora_veeam" queue.maxDiskSpace="1g" queue.saveOnShutdown="on" action.resumeRetryCount="-1")'
            echo '    }'
            echo '    stop'
            echo '}'
        fi
    } > "$f.new"
    chmod 644 "$f.new"; mv "$f.new" "$f"
    if ! rsyslogd -N1 > $C/rsyslog-check.txt 2>&1; then
        rm -f "$f"; systemctl restart rsyslog > /dev/null 2>&1
        say "rsyslog refused the settings (see $C/rsyslog-check.txt); nothing was changed."; return 1
    fi
    systemctl enable rsyslog > /dev/null 2>&1; systemctl restart rsyslog
}

# the reader (Microsoft 365): on while it has something to read
reader() {
    if [ -s "$SRC/m365" ] || [ -s "$SRC/veeam-api" ]; then
        systemctl enable sentora-elk-reader > /dev/null 2>&1; systemctl restart sentora-elk-reader
    else
        systemctl disable --now sentora-elk-reader > /dev/null 2>&1
    fi
}

# which sources are on, for the alerts service (the panel shows it): on or off only, no addresses
publish() {
    local s out='{'
    for s in fortigate veeam m365; do [ -s "$SRC/$s" ] && out="$out\"$s\": \"on\", " || out="$out\"$s\": \"off\", "; done
    out="${out%, }}"
    [ -d /etc/sentora-elk-alerts ] && { printf '%s\n' "$out" > /etc/sentora-elk-alerts/sources.json; chown root:sentora-elk-alerts /etc/sentora-elk-alerts/sources.json; chmod 640 /etc/sentora-elk-alerts/sources.json; }
}

apply() {
    publish
    pipelines
    rsyslog_conf || return 1
    /usr/local/sbin/sentora-elk-node firewall > /dev/null || say "The firewall rules were not made again: sentora-elk-node firewall"
    systemctl restart logstash
    reader
}

case "${1:-sources}" in
    fortigate)
        a=${2:-}
        if [ "$a" = off ]; then rm -f "$SRC/fortigate"; apply; say "The FortiGate's logs are no longer taken."; exit 0; fi
        ip_ok "$a" || { say "sentora-elk-node fortigate ADDRESS   (the FortiGate's address as this node sees it)"; exit 2; }
        echo "$a" > "$SRC/fortigate"
        # its certificate (a client certificate signed by this node's authority), for the FortiGate to show
        if [ ! -s "$SRC/fortigate.crt" ]; then
            openssl req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$SRC/fortigate.key" -out "$SRC/fortigate.csr" -subj "/CN=sentora-fortigate" 2> /dev/null
            printf 'basicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=clientAuth\nsubjectAltName=DNS:sentora-fortigate\n' > "$SRC/fortigate.ext"
            openssl x509 -req -in "$SRC/fortigate.csr" -CA $C/ca.crt -CAkey $C/ca.key -CAcreateserial -out "$SRC/fortigate.crt" -days 3650 -extfile "$SRC/fortigate.ext" 2> /dev/null \
                || { say "Could not make the FortiGate's certificate."; exit 1; }
            rm -f "$SRC/fortigate.csr" "$SRC/fortigate.ext"
        fi
        p=$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | cut -c1-16)
        openssl pkcs12 -export -inkey "$SRC/fortigate.key" -in "$SRC/fortigate.crt" -certfile $C/ca.crt -name sentora-fortigate -passout "pass:$p" -out /root/sentora-fortigate.p12 2> /dev/null
        install -m 644 $C/ca.crt /root/sentora-elk-ca.crt; chmod 600 /root/sentora-fortigate.p12
        apply || exit 1
        me=$(hostname -I 2> /dev/null | awk '{print $1}')
        say "The node takes the FortiGate's logs from $a on port 6516 (TLS, its certificate only)."
        say ""
        say "On your PC, copy two files from this node (they are in /root): sentora-fortigate.p12 and sentora-elk-ca.crt"
        say "  The certificate's import password (shown only now): $p"
        say ""
        say "On the FortiGate (web page): System > Certificates > Create/Import > Certificate > Import Certificate:"
        say "  1. Type PKCS #12 Certificate: sentora-fortigate.p12, the password above, name it sentora-fortigate."
        say "  2. Create/Import > CA Certificate > File: sentora-elk-ca.crt (it becomes CA_Cert_1 or similar)."
        say "Then on the FortiGate's CLI:"
        say "  config log syslogd setting"
        say "    set status enable"
        say "    set server \"$me\""
        say "    set mode reliable"
        say "    set port 6516"
        say "    set enc-algorithm high"
        say "    set certificate \"sentora-fortigate\""
        say "  end"
        say "  config log syslogd filter"
        say "    set severity information"
        say "    set forward-traffic enable"
        say "  end"
        say "For the sites people visit: a web filter profile on the LAN-to-internet policy (Security Profiles > Web Filter;"
        say "\"monitor\" logs every site without blocking), and \"Log Allowed Traffic: All Sessions\" on that policy." ;;
    veeam)
        a=${2:-}
        if [ "$a" = off ]; then rm -f "$SRC/veeam"; apply; say "Veeam's events are no longer taken."; exit 0; fi
        ip_ok "$a" || { say "sentora-elk-node veeam ADDRESS   (the Veeam Backup & Replication server's address as this node sees it)"; exit 2; }
        echo "$a" > "$SRC/veeam"
        install -m 644 $C/ca.crt /root/sentora-elk-ca.crt
        apply || exit 1
        me=$(hostname -I 2> /dev/null | awk '{print $1}')
        say "The node takes Veeam's events from $a on port 6517 (TLS)."
        say ""
        say "On the Veeam server: copy /root/sentora-elk-ca.crt from this node and import it into the Windows certificate store"
        say "  (Local Computer > Trusted Root Certification Authorities), so Veeam trusts this node's certificate."
        say "In the Veeam console: menu > Options > Event Forwarding > Syslog server: Add"
        say "  Server: $me   Port: 6517   Transport: TLS   (Veeam Backup & Replication 12.1 or newer)"
        say "  (the node's certificate there carries the address $me; use that address, not another name)" ;;
    m365)
        t=${2:-}; a=${3:-}
        if [ "$t" = off ]; then rm -f "$SRC/m365" "$SRC/m365.secret"; install -m 640 -o root -g sentora-elk-reader /dev/null /etc/sentora-elk-reader/m365.json 2> /dev/null; publish; reader; say "Microsoft 365 is no longer read."; exit 0; fi
        re='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
        [[ "$t" =~ $re ]] && [[ "$a" =~ $re ]] || { say "sentora-elk-node m365 TENANT-ID APP-ID   (both from Entra: the app's Overview page)"; exit 2; }
        read -r -s -p "The app's client secret (Value, not ID): " sec; echo
        [ -n "$sec" ] && [ ${#sec} -le 200 ] && [[ "$sec" != *[[:space:]\"\\]* ]] || { say "That is not a client secret."; exit 2; }
        printf '{"tenant": "%s", "app": "%s", "secret": "%s"}\n' "$t" "$a" "$sec" > /etc/sentora-elk-reader/m365.json.new
        chown root:sentora-elk-reader /etc/sentora-elk-reader/m365.json.new; chmod 640 /etc/sentora-elk-reader/m365.json.new
        mv /etc/sentora-elk-reader/m365.json.new /etc/sentora-elk-reader/m365.json
        echo "$t" > "$SRC/m365"
        publish
        reader
        sleep 5
        runuser -u sentora-elk-reader -- /usr/bin/python3 -I /usr/local/lib/sentora-elk-reader/sentora-elk-reader.py check ;;
    veeam-api)
        a=${2:-}; u=${3:-}
        if [ "$a" = off ]; then rm -f "$SRC/veeam-api"; install -m 640 -o root -g sentora-elk-reader /dev/null /etc/sentora-elk-reader/veeam.json; publish; reader; say "Veeam's API is no longer read."; exit 0; fi
        ip_ok "$a" && [[ "$u" =~ ^[A-Za-z0-9._@\\-]{1,128}$ ]] || { say "sentora-elk-node veeam-api VEEAM-SERVER-ADDRESS USER   (a Veeam user with the Veeam Backup Viewer role is enough)"; exit 2; }
        # Veeam's API has its own certificate: its fingerprint is shown once, to check, and then only that one is accepted
        fp=$(timeout 15 openssl s_client -connect "$a:9419" -servername "$a" < /dev/null 2> /dev/null | openssl x509 -outform DER 2> /dev/null | sha256sum | cut -d' ' -f1)
        [ ${#fp} = 64 ] && [ "$fp" != e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855 ] || { say "Veeam's API does not answer on $a:9419 (is the Veeam Backup & Replication server there, port 9419 open?)."; exit 1; }
        say "Veeam's API certificate on $a:9419, SHA-256:"
        say "  $(echo "$fp" | sed 's/../&:/g; s/:$//' | tr a-f A-F)"
        say "  (On the Veeam server: the console's menu > Options > Security > certificate > View; its thumbprint there is SHA-1, so"
        say "   compare in PowerShell: Get-ChildItem Cert:\\LocalMachine\\My | ForEach-Object { \$_.GetCertHashString('SHA256') })"
        read -r -p "Is it Veeam's? Type yes to accept it: " ans
        [ "$ans" = yes ] || { say "Not accepted; nothing was changed."; exit 1; }
        read -r -s -p "The password of $u: " vpw; echo
        [ -n "$vpw" ] && [ ${#vpw} -le 200 ] && [[ "$vpw" != *[\"\\]* ]] || { say "That password can not be used here (no quotes or backslashes)."; exit 2; }
        python3 -I -c 'import json,sys; print(json.dumps({"host": sys.argv[1], "port": 9419, "user": sys.argv[2], "password": sys.argv[3], "sha256": sys.argv[4]}))' "$a" "$u" "$vpw" "$fp" > /etc/sentora-elk-reader/veeam.json.new
        chown root:sentora-elk-reader /etc/sentora-elk-reader/veeam.json.new; chmod 640 /etc/sentora-elk-reader/veeam.json.new
        mv /etc/sentora-elk-reader/veeam.json.new /etc/sentora-elk-reader/veeam.json
        echo "$a" > "$SRC/veeam-api"
        publish
        reader
        sleep 3
        runuser -u sentora-elk-reader -- /usr/bin/python3 -I /usr/local/lib/sentora-elk-reader/sentora-elk-reader.py check | grep -E "^veeam" ;;
    sources)
        for s in fortigate veeam m365; do
            if [ -s "$SRC/$s" ]; then
                ds=logs-$s-default
                last=$(es "https://127.0.0.1:9200/$ds/_search?size=1&sort=@timestamp:desc&filter_path=hits.hits._source.@timestamp" 2> /dev/null | sed -n 's/.*"@timestamp":"\([^"]*\)".*/\1/p')
                printf '%-10s on (%s)   last: %s\n' "$s" "$(cat "$SRC/$s")" "${last:-nothing yet}"
            else
                printf '%-10s off\n' "$s"
            fi
        done
        if [ -s "$SRC/veeam-api" ]; then printf '%-10s on (%s)   backup coverage, read every hour\n' "veeam-api" "$(cat "$SRC/veeam-api")"; else printf '%-10s off\n' "veeam-api"; fi ;;
    refresh)
        # (the installer's --update: the newest pipelines and settings for what is on)
        apply > /dev/null ;;
    *) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
