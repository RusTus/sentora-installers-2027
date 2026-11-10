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

## Where we are (handoff, 2026-11-10, after release 2027.68)

Read this first in a new session; the CHANGELOG has every change in detail.

**Supported systems (each has its installer on the `current` release):** Ubuntu 26.04
(`sentora2027-ub26.04`), Debian 13 (`sentora2027-deb13`, MariaDB 11.8, PHP 8.4), AlmaLinux 10 and
Rocky 10 (`sentora2027-alma10`, MariaDB 10.11, PHP 8.3, Dovecot 2.3 with `preconf/dovecot2-23`,
SELinux enforcing, SOGo from Sentora's own build). `sentora2027-install` (on the release) picks the right
installer for the system it runs on. What differs between systems lives in hidden x_settings read through
`dryden/sys/platform.class.php` (`sys_platform::Get`, shell tools: `setso --get <name>`); each
installer sets its values in its "Configuring Sentora" step. Keep the core free of distribution
names (see the standing rule above).

**Tested:** Debian 13 on the owner's VM (post-install check 0 FAIL, smoke test 26/26). AlmaLinux 10.2
on the owner's VM with SELinux enforcing (release 2027.65: post-install check 81 OK / 0 FAIL, smoke
test 25/26 with SOGo skipped, no SELinux denials since boot). Debian and AlmaLinux also in Docker
containers with systemd. **SELinux:** labels are set in the AlmaLinux installer's SELinux step
(semanage fcontext, restorecon, booleans); what labels can not fix goes in
`preconf/selinux/sentora.cil` (installers repo, loaded with `semodule -i`; one rule so far: BIND may
enter /var/sentora/logs). Some refusals are not logged (dontaudit): when a service fails with
"permission denied" and `ausearch` shows nothing, check its rules with `sesearch` (an AlmaLinux 10
container with selinux-policy-targeted, -targeted-extra from CRB, and setools-console). Never
switch SELinux off ("less secure, not done").

**PHP bundles:** built on GitHub by Actions > PHP bundles (system ubuntu26.04, debian13 or alma10;
versions empty = usual set, `8.4` = newest from php.net, or exact; `list_only` rewrites the list).
Each run writes `php-bundles.json` on the release (system, version, SHA-256 of every build). The
panel checks it daily (`dryden/sys/phpbundles.class.php`): a notice and an Update button in PHP
Versions when a newer build is ready; nothing is ever compiled on a server (owner's decision).

**Install tests (since 2027.68):** both release workflows first run `install-test.yml` (installers
repo): Ubuntu 26.04, Debian 13 and AlmaLinux 10 are installed from master2027 of both repositories in
containers with systemd, restarted, and must pass sentora-post-install-check (no FAIL) and
sentora-smoke-test; nothing is published otherwise (`skip_tests` only for a broken test system). The
script is `.github/scripts/install-test.sh` (also runs on a test machine: TEST_CA, CGROUP_DIR, TEST_SOGO,
TEST_KEEP). Actions > Install test runs it alone; each run keeps its results (logs, checks) 14 days.
On the runner the host's AppArmor profiles are unloaded (they confined ProFTPD inside the containers).

**SOGo on AlmaLinux:** built by Actions > SOGo bundle (`preconf/sogo-build/`: libobjc2 with clang,
GNUstep make/base, libwbxml, ytnef, SOPE, SOGo + ActiveSync) into /opt/sentora-sogo, published as
`sentora-sogo-alma10-amd64.tar.gz` with a list of the system libraries it needs (RUNTIME-LIBS; the
installer installs the missing ones). Service `sogo` (unit written by the installer, only
LD_LIBRARY_PATH), tool wrappers in /usr/local/sbin (setting sogo_tool_dir). New SOGo release: run the
workflow (version empty = newest), then the install test.

**Server Monitor process list:** a root service `sentora-procstat` (installed and kept running by the
server_monitor daemon hook) writes /var/lib/sentora/monitor/procs.json; the page uses it while fresh
(SELinux hides other processes from the panel, and nothing in SELinux was widened for it).

**Next (the owner's list):** Red Hat Enterprise Linux (likely close to the AlmaLinux installer), then a
WSL version for Windows. Debian VM confirmed 2027.65 (smoke 26/26).

**Payment Plans (add-on, sentora-repo/payment_plans.zpp, 104):** billing belongs to each seller
(administrator or reseller); the panel's administrator does not manage it. Each seller turns it on or
off (off skips the payment check). The page opens with the seller's packages; each package has its own
page where its price is set (prices in the module's x_pp_prices; the panel's package tables are only read:
the owner's rule, nothing of the core or its tables changes for an add-on). Processors are files in the
module's `code/processors/` (PayPal, Stripe; `pp_pay::Processors()` lists them), and every payment is
checked by `pp_pay::Apply`. New clients on priced packages are billed automatically. Figures at the top
of the seller's page; every step noted in `x_pp_log`. Security-reviewed in 103 (see the CHANGELOG). Not yet tried against real MySQL or the real
PayPal/Stripe test modes; no webhooks yet, so a refund or chargeback after payment is not noticed. The module is only kept as the .zpp: unzip it to change it. Its tests:
`tests/payment_plans/run.sh` in sentora-installers-2027 (stand-in panel, fake PayPal and Stripe; run
before publishing a change; `run.sh <unzipped module folder>` tests a working copy, without it the .zpp).

**Ideas the owner is still thinking about (do not start without asking):** Hosting for the add-on repository and the feedback server was deferred by the owner.

**Working with the owner:** plain words, short; test before saying something works; commit and
publish as you see fit (the owner's rule since 2026-11-10: they say when to wait); a panel release only when
the owner asks or has said "release as you see fit". The owner uses
speech to text: "Centaur", "Centora" = Sentora, "himsa" = hemsaw, "zPanel" sometimes means the panel.
**Several sessions work on these repositories at the same time (the owner's rule):** before
changing anything, `git fetch origin master2027` and bring the branch up to it (fast-forward, or rebase
your own commits on it); do it again just before pushing, and check that everything merges and the tests
still pass. A .zpp add-on is a zip and can not be merged: when two sessions changed the same add-on,
unzip both, merge the files, and build it again. Commit trailers and branch: develop on the session's branch, fast-forward `master2027` after each
push (`git fetch origin master2027 && git merge-base --is-ancestor origin/master2027 HEAD && git
push origin HEAD:refs/heads/master2027`). Never put model names in repository content.
