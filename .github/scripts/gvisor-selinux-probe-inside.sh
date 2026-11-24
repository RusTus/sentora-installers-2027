#!/bin/bash
# The inside part of gvisor-selinux-probe.sh: run as root on the AlmaLinux 10 machine (SELinux enforcing).
set -u
P=/root/probe; O=$P/out; mkdir -p "$O"
say() { printf '\n=== %s  (%s)\n' "$*" "$(date +%T)"; }
mark() { date '+%m/%d/%Y %H:%M:%S' > "$O/mark"; sleep 1; }
avc_since() { ausearch -m AVC,USER_AVC,SELINUX_ERR -ts "$(cat "$O/mark")" -i 2> /dev/null; }
U=gva; U2=gvb

say "system"
. /etc/os-release; echo "$PRETTY_NAME, kernel $(uname -r), $(getenforce)"
dnf -y -q install podman container-selinux selinux-policy-devel policycoreutils-python-utils setools-console passt zstd python3 audit > /dev/null 2>&1
rpm -q podman container-selinux selinux-policy conmon crun passt
say "container-selinux: the template container_t is made with"
IF=/usr/share/selinux/devel/include/services/container.if
grep -n "^template(\`container_domain_template'" -A45 $IF | head -60
grep -n "container_domain_template(\|typeattribute container_t\|^type container_t\|mcs_constrained(container_t)" /usr/share/selinux/devel/include/services/container.* 2> /dev/null | head
seinfo -a container_domain -x 2> /dev/null | head -20
seinfo -a container_net_domain -x 2> /dev/null | head -5

say "runsc"
ARCH=$(uname -m); URL=https://storage.googleapis.com/gvisor/releases/release/latest/$ARCH
( cd /tmp && curl -fsSLO "$URL/gvisor.tar.zstd" && curl -fsSLO "$URL/gvisor.tar.zstd.sha512" && sha512sum -c gvisor.tar.zstd.sha512 > /dev/null && tar --zstd -xf gvisor.tar.zstd -C /usr/local/bin ) || echo "runsc download FAILED"
/usr/local/bin/runsc --version | head -1
install -m 755 $P/runsc-sentora /usr/local/bin/runsc-sentora

say "the policy module"
mkdir -p /root/pol && cp $P/selinux/* /root/pol/ && cd /root/pol
if make -f /usr/share/selinux/devel/Makefile sentora_runsc.pp > "$O/make.txt" 2>&1; then echo "built"; else echo "BUILD FAILED"; cat "$O/make.txt"
    # (fall back: without container-selinux's attributes, to learn the rest anyway)
    sed -i -e '/attribute container_domain;/d' -e '/attribute container_net_domain;/d' -e '/^typeattribute sentora_runsc_t container_domain/d' sentora_runsc.te
    make -f /usr/share/selinux/devel/Makefile sentora_runsc.pp 2>&1 | tail -5; echo "(built without container_domain)"; fi
semodule -i sentora_runsc.pp && echo loaded
restorecon -v /usr/local/bin/runsc /usr/local/bin/runsc-sentora
ls -Z /usr/local/bin/runsc /usr/local/bin/runsc-sentora
cd /

say "two customers (rootless Podman, as the node makes them)"
for u in $U $U2; do useradd -m -s /bin/bash $u 2> /dev/null; loginctl enable-linger $u; done
sleep 3
asu() { local u=$1; shift; local uid; uid=$(id -u $u); ( cd /home/$u && runuser -u $u -- env -i HOME=/home/$u PATH=/usr/local/bin:/usr/bin:/bin XDG_RUNTIME_DIR=/run/user/$uid DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$uid/bus "$@" ); }
for u in $U $U2; do asu $u podman pull -q docker.io/library/busybox:latest > /dev/null && echo "$u: pulled"; done

say "A: runsc straight (no wrapper): what does it say about SELinux?"
mark
asu $U podman --runtime /usr/local/bin/runsc --runtime-flag ignore-cgroups --runtime-flag network=host run --rm busybox echo hello-A 2>&1 | tail -4

say "B: the wrapper: hello from gVisor under SELinux (domain permissive: learning)"
mark
asu $U podman --runtime /usr/local/bin/runsc-sentora run --rm busybox sh -c 'echo hello-B; uname -r; dmesg 2>/dev/null | head -2' 2>&1 | tail -5
avc_since > "$O/avc-B.txt"; echo "SELinux notes: $(grep -c '^type=AVC' "$O/avc-B.txt")"

say "C: a long-running app with a published port; who runs as what"
mark
asu $U podman --runtime /usr/local/bin/runsc-sentora run -d --name web -p 127.0.0.1:18080:8080 busybox sh -c 'echo hello-from-gvisor-under-selinux > /tmp/i; httpd -f -p 8080 -h /tmp' 2>&1 | tail -2
sleep 5
curl -s --max-time 5 http://127.0.0.1:18080/i || echo "no answer"
ps -eZ -o label,user,pid,ppid,args | grep -E "runsc|conmon|pasta|podman|exe" | grep -v grep | cut -c1-220
asu $U podman inspect web --format '{{.ProcessLabel}} {{.MountLabel}} {{.OCIRuntime}}'
ls -dZ /run/user/$(id -u $U)/runsc* /run/user/$(id -u $U)/containers 2> /dev/null
avc_since > "$O/avc-C.txt"; echo "SELinux notes: $(grep -c '^type=AVC' "$O/avc-C.txt")"

say "D: a second customer's app at the same time (its own categories?)"
mark
asu $U2 podman --runtime /usr/local/bin/runsc-sentora run -d --name web2 -p 127.0.0.1:18081:8080 busybox sh -c 'echo two > /tmp/i; httpd -f -p 8080 -h /tmp' 2>&1 | tail -2
sleep 5; curl -s --max-time 5 http://127.0.0.1:18081/i || echo "no answer"
ps -eZ -o label,user,args | grep sentora_runsc_t | grep -v grep | awk '{print $1, $2}' | sort | uniq -c
avc_since > "$O/avc-D.txt"

say "E: stop, start again, remove"
mark
asu $U podman stop -t 5 web 2>&1 | tail -1; asu $U podman start web 2>&1 | tail -1; sleep 4
curl -s --max-time 5 http://127.0.0.1:18080/i || echo "no answer after restart"
asu $U podman rm -f web 2>&1 | tail -1; asu $U2 podman rm -f web2 2>&1 | tail -1
avc_since > "$O/avc-E.txt"

say "F: a volume (as Docker Hosting's apps have)"
mark
asu $U podman volume create vol1 > /dev/null
asu $U podman --runtime /usr/local/bin/runsc-sentora run --rm -v vol1:/data busybox sh -c 'echo kept > /data/f; cat /data/f; ls -la /data' 2>&1 | tail -3
avc_since > "$O/avc-F.txt"

say "everything sentora_runsc_t needed (permissive), as rules"
ausearch -m AVC -i 2> /dev/null | grep 'scontext=[^ ]*sentora_runsc_t' > "$O/avc-runsc.txt"
grep -c . "$O/avc-runsc.txt"
ausearch -m AVC 2> /dev/null | grep 'sentora_runsc_t' | audit2allow 2>&1 | tee "$O/audit2allow.txt"
say "with the policy's own interfaces"
ausearch -m AVC 2> /dev/null | grep 'sentora_runsc_t' | audit2allow -R 2>&1 | tee "$O/audit2allow-R.txt" | head -80
say "refusals NOT from the new domain (others: these are real denials)"
ausearch -m AVC,USER_AVC,SELINUX_ERR -i 2> /dev/null | grep -E '^type=(AVC|USER_AVC|SELINUX_ERR)' | grep -v 'sentora_runsc_t:' | cut -c1-400 | head -30
