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
# (the cloud image has auditd off: SELinux's refusals would go only to the kernel log)
systemctl enable --now auditd > /dev/null 2>&1; sleep 2; systemctl is-active auditd
echo "kernel command line: $(cat /proc/cmdline)"; auditctl -s 2> /dev/null | grep -E '^(enabled|failure|backlog)'
auditctl -e 1 > /dev/null 2>&1; auditctl -s 2> /dev/null | grep '^enabled'
say "container-selinux: the template container_t is made with"
IF=/usr/share/selinux/devel/include/services/container.if
grep -n "^template(\`container_domain_template'" -A16 $IF
grep -n "^template(\`container_manage_files_template'" -A80 $IF | head -90
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
    sed -i -e '/attribute container_domain;/d' -e '/^typeattribute sentora_runsc_t container_domain/d' sentora_runsc.te
    make -f /usr/share/selinux/devel/Makefile sentora_runsc.pp 2>&1 | tail -5; echo "(built without container_domain)"; fi
semodule -i sentora_runsc.pp && echo loaded
restorecon -v /usr/local/bin/runsc /usr/local/bin/runsc-sentora
ls -Z /usr/local/bin/runsc /usr/local/bin/runsc-sentora
cd /
# (SELinux's "do not log" rules off: every refusal shows)
semodule -DB && echo "dontaudit rules off"

say "what sentora_runsc_t may do that container_t may not (the rules, compared)"
sesearch -A -s sentora_runsc_t 2> /dev/null | sed 's/sentora_runsc_t/DOMAIN/g' | sort -u > "$O/rules-runsc.txt"
sesearch -A -s container_t 2> /dev/null | sed 's/container_t /DOMAIN /g; s/container_t:/DOMAIN:/g' | sort -u > "$O/rules-container.txt"
wc -l < "$O/rules-runsc.txt"; wc -l < "$O/rules-container.txt"
comm -23 "$O/rules-runsc.txt" "$O/rules-container.txt" | tee "$O/rules-only-runsc.txt" | head -60
echo "(container_t only, first 15:)"; comm -13 "$O/rules-runsc.txt" "$O/rules-container.txt" | head -15

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
ps -eo label,user,pid,ppid,args | grep -E "runsc|conmon|pasta|podman|exe" | grep -v grep | cut -c1-220
asu $U podman inspect web --format '{{.ProcessLabel}} {{.MountLabel}} {{.OCIRuntime}}'
ls -dZ /run/user/$(id -u $U)/runsc* /run/user/$(id -u $U)/containers 2> /dev/null
avc_since > "$O/avc-C.txt"; echo "SELinux notes: $(grep -c '^type=AVC' "$O/avc-C.txt")"

say "D: a second customer's app at the same time (its own categories?)"
mark
asu $U2 podman --runtime /usr/local/bin/runsc-sentora run -d --name web2 -p 127.0.0.1:18081:8080 busybox sh -c 'echo two > /tmp/i; httpd -f -p 8080 -h /tmp' 2>&1 | tail -2
sleep 5; curl -s --max-time 5 http://127.0.0.1:18081/i || echo "no answer"
ps -eo label,user,args | grep sentora_runsc_t | grep -v grep | awk '{print $1, $2}' | sort | uniq -c
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

say "ENFORCING: the domain with permissive taken out"
cd /root/pol && sed -i 's/^permissive sentora_runsc_t;/# (enforcing)/' sentora_runsc.te && make -f /usr/share/selinux/devel/Makefile sentora_runsc.pp > /dev/null 2>&1 && semodule -i sentora_runsc.pp && echo "enforcing module loaded"; semodule -DB; cd /
semodule -l | grep sentora; seinfo --permissive 2> /dev/null | grep -i sentora || echo "sentora_runsc_t is not permissive"
mark
asu $U podman --runtime /usr/local/bin/runsc-sentora run --rm busybox sh -c 'echo hello-enforcing' 2>&1 | tail -3
asu $U podman --runtime /usr/local/bin/runsc-sentora run -d --name web -p 127.0.0.1:18080:8080 -v vol1:/data busybox sh -c 'cat /data/f > /tmp/i; httpd -f -p 8080 -h /tmp' 2>&1 | tail -1
asu $U2 podman --runtime /usr/local/bin/runsc-sentora run -d --name web2 -p 127.0.0.1:18081:8080 busybox sh -c 'echo two > /tmp/i; httpd -f -p 8080 -h /tmp' 2>&1 | tail -1
sleep 5
echo "web: $(curl -s --max-time 5 http://127.0.0.1:18080/i || echo no answer)  web2: $(curl -s --max-time 5 http://127.0.0.1:18081/i || echo no answer)"
ps -eo label,user,args | grep -E 'sentora_runsc_t|conmon' | grep -v grep | awk '{print $1, $2, $3}' | sort | uniq -c
asu $U podman stop -t 5 web > /dev/null 2>&1; asu $U podman start web > /dev/null 2>&1; sleep 4
echo "after a restart: $(curl -s --max-time 5 http://127.0.0.1:18080/i || echo no answer)"
asu $U podman exec web sh -c 'echo exec-works' 2>&1 | tail -1
asu $U podman logs web 2>&1 | tail -2
asu $U podman rm -f web > /dev/null 2>&1; asu $U2 podman rm -f web2 > /dev/null 2>&1
avc_since > "$O/avc-enforcing.txt"; echo "SELinux refusals while enforcing: $(grep -c '^type=AVC' "$O/avc-enforcing.txt")"
grep '^type=AVC' "$O/avc-enforcing.txt" | cut -c1-300 | head -20

say "PROOF: a process in the domain (as if it got out of gVisor) next to one in container_t, same files"
# (test-only module: root may start a program in either domain, to try what the domain lets it reach)
mkdir -p /root/t && cd /root/t
cat > sentora_runsc_try.te <<'TE'
policy_module(sentora_runsc_try, 1.0)
gen_require(`
	type unconfined_t, sentora_runsc_t, sentora_runsc_exec_t, container_t, container_file_t;
')
allow unconfined_t { sentora_runsc_t container_t }:process transition;
allow { sentora_runsc_t container_t } unconfined_t:fd use;
allow { sentora_runsc_t container_t } unconfined_t:fifo_file rw_fifo_file_perms;
allow { sentora_runsc_t container_t } unconfined_t:process sigchld;
allow { sentora_runsc_t container_t } user_devpts_t:chr_file rw_chr_file_perms;
TE
sed -i 's/type unconfined_t, /type unconfined_t, user_devpts_t, /' sentora_runsc_try.te
make -f /usr/share/selinux/devel/Makefile sentora_runsc_try.pp > /dev/null 2>&1 && semodule -i sentora_runsc_try.pp && echo "try module loaded"; semodule -DB
cp /usr/bin/busybox /root/t/bb 2> /dev/null || { dnf -y -q install busybox > /dev/null 2>&1; cp /usr/bin/busybox /root/t/bb 2> /dev/null; }
[ -x /root/t/bb ] || { asu $U podman create --name bbx busybox > /dev/null; asu $U podman cp bbx:/bin/busybox /home/$U/bb; cp /home/$U/bb /root/t/bb; asu $U podman rm bbx > /dev/null; }
mkdir -p /var/tmp/sentora-try/r /var/tmp/sentora-try/c && cp /root/t/bb /var/tmp/sentora-try/r/busybox && cp /root/t/bb /var/tmp/sentora-try/c/busybox
chcon -t sentora_runsc_exec_t /var/tmp/sentora-try/r/busybox; chcon -t container_file_t -l s0 /var/tmp/sentora-try/c/busybox
echo "app A's own file" > /var/tmp/sentora-try/a.txt; chcon -t container_file_t -l s0:c1,c2 /var/tmp/sentora-try/a.txt
echo "app B's file" > /var/tmp/sentora-try/b.txt; chcon -t container_file_t -l s0:c3,c4 /var/tmp/sentora-try/b.txt
echo "customer home" > /home/$U2/secret.txt; restorecon /home/$U2/secret.txt
mkdir -p /etc/sentora-docker-node && echo "node key" > /etc/sentora-docker-node/node.key && chmod 600 /etc/sentora-docker-node/node.key; restorecon -R /etc/sentora-docker-node
ls -Z /var/tmp/sentora-try/ /home/$U2/secret.txt /etc/sentora-docker-node/node.key
try() { # try <label> <what> <busybox args...>
    local lab=$1 what=$2; shift 2
    local r1 r2
    r1=$(runcon "system_u:system_r:sentora_runsc_t:$lab" /var/tmp/sentora-try/r/busybox "$@" 2>&1 | head -1)
    r2=$(runcon "system_u:system_r:container_t:$lab" /var/tmp/sentora-try/c/busybox "$@" 2>&1 | head -1)
    printf '%-46s | sentora_runsc_t: %-40.40s | container_t: %.40s\n' "$what" "$r1" "$r2"
}
mark
try s0:c1,c2 "read its own app's file"              cat /var/tmp/sentora-try/a.txt
try s0:c1,c2 "read ANOTHER app's file"              cat /var/tmp/sentora-try/b.txt
try s0:c1,c2 "write ANOTHER app's file"             sh -c 'echo x >> /var/tmp/sentora-try/b.txt && echo written'
try s0:c1,c2 "read a customer's home"               cat /home/$U2/secret.txt
try s0:c1,c2 "read /etc/shadow"                     cat /etc/shadow
try s0:c1,c2 "read the node's key"                  cat /etc/sentora-docker-node/node.key
try s0:c1,c2 "write /etc"                           sh -c 'echo x > /etc/sentora-try && echo written'
try s0:c1,c2 "list /root"                           ls /root
try s0:c1,c2 "read /proc/1/environ (init)"          cat /proc/1/environ
try s0:c1,c2 "load a kernel module"                 insmod /nonexistent.ko
try s0:c1,c2 "talk to the system bus"               sh -c 'echo > /run/dbus/system_bus_socket && echo connected'
try s0:c1,c2 "mount a tmpfs"                        mount -t tmpfs none /mnt
try s0:c1,c2 "ptrace (attach to pid 1)"             sh -c 'cat /proc/1/mem > /dev/null && echo read-mem'
avc_since > "$O/avc-proof.txt"
echo "(refusals logged during the proof: $(grep -c '^type=AVC' "$O/avc-proof.txt"); it tried /etc/shadow and more, so there must be some)"
grep '^type=AVC' "$O/avc-proof.txt" | grep sentora_runsc_t | cut -c1-260 | head -12
semodule -r sentora_runsc_try > /dev/null 2>&1; semodule -B

say "the kernel's own log of SELinux (in case audit missed any)"
journalctl -k --no-pager 2> /dev/null | grep -iE 'avc:|selinux' | grep -v 'avc:  received' | tail -20
