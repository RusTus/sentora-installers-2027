# Sentora 2027 as a Docker image: Debian 13 with systemd, and Sentora's own Debian 13 installer.
# Nothing of Sentora is installed in the image itself: the installer runs the first time a container
# starts (sentora-docker-firstboot), so every container gets its own passwords, keys and certificate.
#
# Built and tested by .github/workflows/docker-image.yml (Actions > Docker image), published as
# ghcr.io/rustus/sentora2027. Build it yourself, from the repository's top folder:
#   docker build -f docker/Dockerfile -t sentora2027 .
FROM debian:13

ENV container=docker
RUN apt-get update -qq \
 && DEBIAN_FRONTEND=noninteractive apt-get install -yqq --no-install-recommends \
      systemd systemd-sysv dbus tzdata ca-certificates curl wget procps iproute2 \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 # (a container has no consoles to log in on, no hardware to set up, and can't mount its own /tmp)
 && systemctl mask getty.target console-getty.service systemd-firstboot.service \
      systemd-udevd.service systemd-udev-trigger.service systemd-modules-load.service \
      sys-kernel-debug.mount sys-kernel-tracing.mount tmp.mount > /dev/null 2>&1 || true

COPY sentora2027-deb13 /usr/local/share/sentora/sentora2027-deb13
COPY docker/sentora-docker-firstboot /usr/local/sbin/sentora-docker-firstboot
COPY docker/sentora-docker-firstboot.service /etc/systemd/system/sentora-docker-firstboot.service
RUN chmod 755 /usr/local/sbin/sentora-docker-firstboot \
 && chmod 644 /usr/local/share/sentora/sentora2027-deb13 /etc/systemd/system/sentora-docker-firstboot.service \
 && systemctl enable sentora-docker-firstboot.service

# web, FTP (passive 60000-65535), mail, DNS, ManageSieve
EXPOSE 80 443 21 25 465 587 110 143 993 995 4190 53/tcp 53/udp

LABEL org.opencontainers.image.title="Sentora 2027" \
      org.opencontainers.image.description="Sentora 2027, the open-source web hosting control panel, on Debian 13. Installs itself on the first start." \
      org.opencontainers.image.source="https://github.com/RusTus/sentora-installers-2027" \
      org.opencontainers.image.url="https://sentorareloaded2027.github.io" \
      org.opencontainers.image.licenses="GPL-3.0"

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
