# Notes for Claude (and anyone releasing Sentora-2027)

This repository holds the installer, the add-on repository (sentora-repo/) and the feedback server.
Its sister repository, RusTus/sentora-core-2027, is the panel's core (with VERSION); both are
released together.

## Version numbers: the year never rolls over

- The version is in `VERSION`: `2027.N`, where N simply counts up by one for every release.
- **2027 stays 2027.** After 2027.99 comes **2027.100**, then 2027.101, 2027.102 and so on. It never
  becomes 2028 (or 2027.9.9, or anything else): the 2027 is the product's name, not a date.
- The tooling handles this: the release workflow sorts versions with `sort -V`, and `sentora-update`
  knows a new release by its SHA-256, not by comparing numbers.

## Releasing (only when the owner asks)

1. `php .github/scripts/stamp-rustus.php` (in sentora-core-2027).
2. Write the next version into sentora-core-2027's `VERSION` (one more than the last: see the newest `- Release 2027.N`
   line in CHANGELOG.md).
3. In CHANGELOG.md of **both** repositories, add `- Release 2027.N: <what it brings>` above the
   previous release line (feature bullets go above that line too, as they are made).
4. Commit "Release 2027.N" in both repositories, push, and fast-forward `master2027` in both.
5. Run the `release.yml` workflow on `master2027` in both repositories.
6. Check: both runs succeeded on the release commits; the `v2027.N` release and the `current`
   release ("Sentora-2027.N (current)") exist in both; the core zip's VERSION says 2027.N.

## Publishing an add-on (sentora-repo/)

1. Raise `<version>` in the module's own module.xml.
2. Zip it from inside the module's folder: `zip -qrX <name>.zpp module.xml module.zpm code assets deploy hooks`.
3. Copy the .zpp into sentora-repo/, set `<latestversion>` in sentora-repo/<name>.xml to the same
   number, and replace its line in sentora-repo/SHA256SUMS (`sha256sum`, then sort the file).
4. Update its row in sentora-repo/README.md (and in the core's README.md), add a CHANGELOG bullet in
   both repositories, commit, push and fast-forward master2027. Repo Browser offers it at once; a
   panel release is only needed when the owner asks.

## Standing rules from the owner

- Explain in plain words; check (test) before saying something works.
- Add-on modules (sentora-repo/*.zpp) must install and repair themselves without changes to the core.
- Changing the panel's own host name stays an SSH-only command (sentora-change-domain).
- When something is less secure, it is not done that way.
- Only ask questions when the answer is really needed.
- The panel stays neutral to the Linux distribution: commands, paths, service names, users and
  groups the daemon, modules and tools use come from x_settings (hidden ones: so_usereditable_en =
  'false'), never written into the code. The installer of each distribution sets them. Commands
  that root runs stay hidden (changed with setso over SSH only).

## Where we are (handoff, 2026-11-10, after release 2027.63)

Read this first in a new session; the CHANGELOG has every change in detail.

**Supported systems (each has its installer on the `current` release):** Ubuntu 26.04
(`sentora2027-ub26.04`), Debian 13 (`sentora2027-deb13`, MariaDB 11.8, PHP 8.4), AlmaLinux 10 and
Rocky 10 (`sentora2027-alma10`, MariaDB 10.11, PHP 8.3, Dovecot 2.3 with `preconf/dovecot2-23`,
SELinux enforcing, no SOGo). What differs between systems lives in hidden x_settings read through
`dryden/sys/platform.class.php` (`sys_platform::Get`, shell tools: `setso --get <name>`); each
installer sets its values in its "Configuring Sentora" step. Keep the core free of distribution
names (see the standing rule above).

**Tested:** Debian 13 on the owner's VM (post-install check 0 FAIL, smoke test 26/26). Debian and
AlmaLinux in Docker containers with systemd (post-install check 0 FAIL, smoke test passes).
**Not yet tested on a real server:** AlmaLinux 10 with SELinux enforcing (containers have no
SELinux). The owner will install it on a VM; collect denials with
`ausearch -m avc -ts boot | audit2why` and turn them into a small Sentora SELinux policy module
(never switch SELinux off: "less secure, not done").

**PHP bundles:** built on GitHub by Actions > PHP bundles (system ubuntu26.04, debian13 or alma10;
versions empty = usual set, `8.4` = newest from php.net, or exact; `list_only` rewrites the list).
Each run writes `php-bundles.json` on the release (system, version, SHA-256 of every build). The
panel checks it daily (`dryden/sys/phpbundles.class.php`): a notice and an Update button in PHP
Versions when a newer build is ready; nothing is ever compiled on a server (owner's decision).

**Next, agreed with the owner:**
1. Automatic install tests on GitHub Actions: on each release, install on Ubuntu 26.04, Debian 13
   and AlmaLinux 10 (containers with systemd), run sentora-post-install-check and
   sentora-smoke-test, and only publish when they pass. (Containers need `--privileged`, cgroup2
   and systemd as PID 1; see how the sessions tested: a Dockerfile with systemd, `CMD /sbin/init`.)
2. When the owner sends AlmaLinux VM results: fix what SELinux blocks (policy module), then
   `sentora-update` + `sentora-smoke-test` on the owner's Debian VM to confirm the 2027.62/63 core.

**Ideas the owner is still thinking about (do not start without asking):** a SELinux policy module
(after the VM test), a calendar/contacts alternative for AlmaLinux (no SOGo there), showing each
panel's system on the feedback server's stats page, one install command that picks the right
installer. Hosting for the add-on repository and the feedback server was deferred by the owner.

**Working with the owner:** plain words, short; test before saying something works; release only
when the owner asks or has said "release as you see fit" (they did for this work). The owner uses
speech to text: "Centaur", "Centora" = Sentora, "himsa" = hemsaw, "zPanel" sometimes means the panel.
Commit trailers and branch: develop on the session's branch, fast-forward `master2027` after each
push (`git fetch origin master2027 && git merge-base --is-ancestor origin/master2027 HEAD && git
push origin HEAD:refs/heads/master2027`). Never put model names in repository content.
