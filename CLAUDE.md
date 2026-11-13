# Notes for Claude (and anyone releasing Sentora-2027)

This repository holds the installer, the add-on repository (sentora-repo/) and the feedback server.
Its sister repository, RusTus/sentora-core-2027, is the panel's core (with VERSION); both are
released together. This file holds the rules; the details of each part are in sentora-core-2027's
HANDOFF.md (see "Where things are").

## Version numbers: the year never rolls over

- The version is in `VERSION`: `2027.N`, where N simply counts up by one for every release.
- **2027 stays 2027.** After 2027.99 comes **2027.100**, then 2027.101, 2027.102 and so on. It never
  becomes 2028 (or 2027.9.9, or anything else): the 2027 is the product's name, not a date.
- The tooling handles this: the release workflow sorts versions with `sort -V`, and `sentora-update`
  knows a new release by its SHA-256, not by comparing numbers.

## Releasing (as you see fit: say so first, then wait at least 10 seconds; owner, 2026-11-13)

1. `php .github/scripts/stamp-rustus.php` (in sentora-core-2027).
2. Write the next version into sentora-core-2027's `VERSION` (one more than the last: see the newest `- Release 2027.N`
   line in CHANGELOG.md).
3. In CHANGELOG.md of **both** repositories, add `- Release 2027.N: <what it brings>` above the
   previous release line (feature bullets go above that line too, as they are made).
4. Commit "Release 2027.N" in both repositories, push, and fast-forward `master2027` in both.
5. Run the `release.yml` workflow on `master2027` in both repositories. An ordinary release runs the
   install test only. After a good number of changes since the last full test, ask the owner whether
   this one is a **full test** (the owner's rule, 2026-11-12): then run the installers' release with
   `full_test` on, which also runs the VM test (about 20 minutes more) and publishes only if it passes.
6. Check: both runs succeeded on the release commits; the `v2027.N` release and the `current`
   release ("Sentora-2027.N (current)") exist in both; the core zip's VERSION says 2027.N.

## Publishing an add-on (sentora-repo/)

Each add-on's files are in sentora-installers-2027's `addons/<name>/`, the one place it is changed (the .zpp in
sentora-repo/ is built from it, never edited or zipped by hand).

1. Change the files in `addons/<name>/` and raise `<version>` in its module.xml.
2. Build it: `addons/build.sh <name>` (in sentora-installers-2027). It writes sentora-repo/<name>.zpp (the same files
   always give the same zip), sets `<latestversion>` in sentora-repo/<name>.xml and puts its line in sentora-repo/SHA256SUMS.
   `addons/check.sh` checks every .zpp matches its folder; the **Add-ons** workflow runs it and the add-on tests on every push.
3. Update its row in sentora-repo/README.md (and in the core's README.md), add a CHANGELOG bullet in
   both repositories, commit, push and fast-forward master2027. Repo Browser offers it at once; a
   panel release is not needed for an add-on.

## Standing rules from the owner

- Explain in plain words; check (test) before saying something works.
- Add-on modules (sentora-repo/*.zpp) must install and repair themselves without changes to the core: they work
  by themselves with the panel's own framework (dryden, x_settings, notices, the daemon hooks), and a core
  function newer than the oldest panel in use is only used behind method_exists() with a fallback.
- An add-on works on every supported system (Ubuntu, Debian, AlmaLinux/Rocky/RHEL, Fedora) unless a real
  security reason stops it (owner, 2026-11-12): paths, packages and services from x_settings, not one distribution's.
- Changing the panel's own host name stays an SSH-only command (sentora-change-domain).
- Defaults Sentora sets (php.ini, Apache) must leave room for the administrator's own settings (owner, 2026-11-12): defaults go where the administrator's settings win (php.ini and server-wide Apache, before Apache Config's global and per-domain custom blocks; a site's .user.ini), never as php_admin_value (only the safety fences: open_basedir, temp folders); on existing servers a value changes only while it is still at the old default.
- When something is less secure, it is not done that way.
- Only ask questions when the answer is really needed.
- The panel stays neutral to the Linux distribution: commands, paths, service names, users and
  groups the daemon, modules and tools use come from x_settings (hidden ones: so_usereditable_en =
  'false'), never written into the code. The installer of each distribution sets them. Commands
  that root runs stay hidden (changed with setso over SSH only).

## Pages and how they look

- Before saying a page change is done, look at it: render the page with the panel's real stylesheets
  (`etc/styles/_shared/global-css/bootstrap.min.css`, `etc/styles/Modern/css/_vars-light.css` or `_vars-dark.css`,
  `_modern.css`, then the module's own CSS; an add-on's test can save its pages, as Docker Hosting's run.sh did),
  take screenshots at desktop width and at 390 px (a phone), light and dark, and look at them yourself.
  Tests only show that a page works, not that it looks right.
- Compare with the reworked pages (Apache Config, DNS Config, PHP Versions): tiles and the ring chart where figures
  help, tabs as a bar with the open tab underlined, buttons first on the left, lists as card rows, the theme's
  `--sn-*` colours (so Dark works), nothing wider than a phone.
- The panel's Bootstrap styles a text box only by its type: every `<input>` needs `type="text"` (or its own type).
- Keep a style sheet one ordered file: change a rule where it is, do not pile overrides at the end.

## Where things are

The details of every part (what was built, how it works, what was tested, what the owner is still deciding) are in
**HANDOFF.md** in sentora-core-2027, one heading per part (supported systems, SELinux and AppArmor, the install, VM and
add-on tests, PHP, Docker image, Windows, each add-on, backups, recent releases, "Waiting on the owner"). Read the
section for the part you are about to touch before you change it, and keep that section up to date afterwards; every
change also gets a line in CHANGELOG.md of both repositories. Never start anything under "Waiting on the owner"
without asking.

Supported systems: Ubuntu 26.04, Debian 13, AlmaLinux / Rocky / RHEL 10, Fedora 45 (and Windows through WSL, Docker).
What differs between them lives in hidden x_settings (`sys_platform::Get`, `setso --get <name>`), set by each
installer's "Configuring Sentora" step. SELinux and AppArmor stay on, always.

## Working with the owner

Plain words, short; with every command say which server it runs on (the panel, a Docker Hosting node, the owner's PC); when the owner is testing, give the steps that belong together in a row as one short block, then the next block when they are ready (not everything at once: it gets lost in the chat); start each step with what it is for ("so your PC can find the app: ..."), then how, so the owner can skip what they already have or do it their own way; test before saying something works; commit and
publish as you see fit (the owner's rule since 2026-11-10: they say when to wait); releases as you see fit (the owner's rule
since 2026-11-13), but first say in the chat that you are releasing, then wait at least 10 seconds before
starting it, so the owner can stop it. The owner uses
speech to text: "Centaur", "Centora" = Sentora, "himsa" = hemsaw, "zPanel" sometimes means the panel.

**Several sessions work on these repositories at the same time (the owner's rule):** before
changing anything, `git fetch origin master2027` and bring the branch up to it (fast-forward, or rebase
your own commits on it); do it again just before pushing, and check that everything merges and the tests
still pass. Add-ons merge like any other files (their folders in addons/): after a merge, build the
.zpp again with addons/build.sh (a .zpp itself is never merged). Commit trailers and branch: develop on the session's branch, fast-forward `master2027` after each
push (`git fetch origin master2027 && git merge-base --is-ancestor origin/master2027 HEAD && git
push origin HEAD:refs/heads/master2027`). Never put model names in repository content.
