#!/usr/bin/env python3
"""Sentora-2027 security watch (run weekly by .github/workflows/security-watch.yml).

Looks, and changes nothing:
  1. CISA's Known Exploited Vulnerabilities list (KEV): holes being used in real attacks, added in the
     last DAYS days, in software a Sentora server runs (Linux kernel, OpenSSH, PHP, Apache, Postfix,
     Dovecot, ProFTPD, BIND, MariaDB, OpenSSL, sudo, Roundcube, phpMyAdmin, SOGo, Matomo ...).
  2. Software Sentora ships itself (which the system's own updates do not update): Sentora's PHP
     builds, Roundcube, phpMyAdmin, Matomo, SOGo (AlmaLinux/Fedora build), OWASP CRS (ModSecurity's
     rules) and Snuffleupagus, each against its newest release.

Each finding has a key. Keys listed in .github/security-watch-ack.txt (seen, and being dealt with or
not needed) are shown but do not count; any other finding makes the run fail, so GitHub emails the
owner, and the run's summary page lists what to do. A check that could not run (a site down) fails
the run too, so the watch can not go quiet unnoticed.

  python3 .github/scripts/security-watch.py [--days N]
Environment: GITHUB_TOKEN (GitHub API, optional), GITHUB_STEP_SUMMARY (the run's summary page).

Written by RusTus, 2026-11-10
"""
import json
import os
import re
import sys
import urllib.request
from datetime import datetime, timedelta, timezone

CORE = 'RusTus/sentora-core-2027'
INST = 'RusTus/sentora-installers-2027'
HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
KEV_URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json'

# KEV: what a Sentora server runs (matched in the vendor and product names, lower case)
KEV_WATCH = [
    ('linux', 'kernel'), ('openbsd', 'openssh'), ('', 'openssh'), ('php', 'php'), ('apache', 'http server'),
    ('', 'postfix'), ('', 'dovecot'), ('', 'proftpd'), ('isc', 'bind'), ('', 'mariadb'), ('oracle', 'mysql'),
    ('', 'openssl'), ('', 'sudo'), ('gnu', 'glibc'), ('', 'polkit'), ('', 'systemd'), ('', 'roundcube'),
    ('', 'phpmyadmin'), ('', 'sogo'), ('', 'matomo'), ('', 'modsecurity'), ('', 'spamassassin'),
    ('', 'clamav'), ('', 'fail2ban'), ('', 'memcached'), ('', 'wordpress'),
]


def fetch(url, accept=None):
    req = urllib.request.Request(url, headers={'User-Agent': 'sentora-security-watch'})
    if accept:
        req.add_header('Accept', accept)
    if url.startswith('https://api.github.com/') and os.environ.get('GITHUB_TOKEN'):
        req.add_header('Authorization', 'Bearer ' + os.environ['GITHUB_TOKEN'])
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read().decode('utf-8', 'replace')


def gh(path):
    return json.loads(fetch('https://api.github.com/' + path, 'application/vnd.github+json'))


def raw(repo, path):
    return fetch('https://raw.githubusercontent.com/%s/master2027/%s' % (repo, path))


def ver(v):
    """A version as numbers, for comparing (1.7.10 > 1.7.9)."""
    return tuple(int(x) for x in re.findall(r'\d+', v or '')[:4])


def newest_release(repo, prefix='', same_line=None):
    """The newest non-draft, non-prerelease version of a GitHub repository (optionally only the
    releases of one line, e.g. '4.25'), with its date."""
    best = None
    for page in (1, 2):
        for r in gh('repos/%s/releases?per_page=100&page=%d' % (repo, page)):
            if r.get('draft') or r.get('prerelease'):
                continue
            tag = r.get('tag_name') or ''
            m = re.search(r'(\d+\.\d+(?:\.\d+)?)', tag[len(prefix):] if tag.startswith(prefix) else tag)
            if not m:
                continue
            v = m.group(1)
            if same_line and not (v == same_line or v.startswith(same_line + '.')):
                continue
            if best is None or ver(v) > ver(best[0]):
                best = (v, r.get('published_at') or '')
    return best


class Watch:
    def __init__(self, ack):
        self.ack = ack
        self.findings = []   # (key, title, what to do)
        self.ok = []         # lines: checked, nothing to do
        self.errors = []     # checks that could not be done

    def find(self, key, title, todo):
        self.findings.append((key, title, todo))

    def check(self, name, fn):
        try:
            fn()
        except Exception as e:  # one source down must not hide the others
            self.errors.append('%s: could not be checked (%s)' % (name, str(e)[:200]))


def read_ack():
    keys = {}
    try:
        for line in open(os.path.join(ROOT, '.github', 'security-watch-ack.txt'), encoding='utf-8'):
            line = line.strip()
            if line and not line.startswith('#'):
                key, _, note = line.partition('#')
                keys[key.strip()] = note.strip()
    except FileNotFoundError:
        pass
    return keys


# ----- 1. CISA KEV --------------------------------------------------------------------------------

def check_kev(w, days):
    data = json.loads(fetch(KEV_URL))
    since = (datetime.now(timezone.utc) - timedelta(days=days)).date()
    seen = 0
    for v in data.get('vulnerabilities', []):
        try:
            added = datetime.strptime(v.get('dateAdded', ''), '%Y-%m-%d').date()
        except ValueError:
            continue
        if added < since:
            continue
        seen += 1
        vendor, product = (v.get('vendorProject') or '').lower(), (v.get('product') or '').lower()
        if not any((not a or a in vendor) and b in (vendor + ' ' + product) for a, b in KEV_WATCH):
            continue
        cve = v.get('cveID', '?')
        sites = 'wordpress' in product
        w.find(cve, '%s: %s %s, on CISA\'s exploited list since %s (due %s)%s' % (
            cve, v.get('vendorProject', ''), v.get('product', ''), v.get('dateAdded', ''), v.get('dueDate', ''),
            ' (hosted sites, not the server)' if sites else ''),
            ('Tell clients running it to update their sites.' if sites else
             'Check whether each system\'s updates fix it; if not, add a stopgap to the sentora2027-patch-* scripts. '
             + (v.get('shortDescription') or '')[:300]))
    w.ok.append('CISA KEV: %d entr%s added in the last %d days, catalog %s' % (
        seen, 'y' if seen == 1 else 'ies', days, data.get('catalogVersion', '?')))


# ----- 2. what Sentora ships ----------------------------------------------------------------------

def release_asset(name):
    for a in gh('repos/%s/releases/tags/current' % INST).get('assets', []):
        if a.get('name') == name:
            return a
    return None


def check_php(w):
    a = release_asset('php-bundles.json')
    if not a:
        raise RuntimeError('php-bundles.json is not on the current release')
    data = json.loads(fetch('https://api.github.com/repos/%s/releases/assets/%d' % (INST, a['id']), 'application/octet-stream'))
    latest = {}
    for system, builds in sorted(data.get('bundles', {}).items()):
        for mm, b in sorted(builds.items(), key=lambda x: ver(x[0])):
            if mm not in latest:
                latest[mm] = ''
                if ver(mm) >= (8, 2):   # (older lines get no releases from PHP any more)
                    j = json.loads(fetch('https://www.php.net/releases/index.php?json&version=' + mm))
                    if not (isinstance(j, dict) and re.match(r'^\d+\.\d+\.\d+', str(j.get('version', '')))):
                        raise RuntimeError('php.net gave no version for %s' % mm)
                    latest[mm] = j['version']
            have, new = b.get('version', ''), latest[mm]
            if not new:
                continue
            if ver(new) > ver(have):
                w.find('php-%s-%s' % (new, system), 'PHP %s is out; Sentora\'s %s build is %s' % (new, system, have),
                       'Run Actions > PHP bundles (system %s, versions %s); then the panel offers the update.' % (system, mm))
    eol = sorted({mm for builds in data.get('bundles', {}).values() for mm in builds if ver(mm) < (8, 2)}, key=ver)
    w.ok.append('PHP builds: compared with php.net for every system' + ('; end of life (no more fixes from PHP): ' + ', '.join(eol) if eol else ''))


def core_version(path, pattern):
    m = re.search(pattern, raw(CORE, path))
    if not m:
        raise RuntimeError('version not found in %s' % path)
    return m.group(1)


def compare(w, name, have, new, key, todo):
    if new and ver(new) > ver(have):
        w.find(key, '%s %s is out; Sentora ships %s' % (name, new, have), todo)
    else:
        w.ok.append('%s: Sentora ships %s, the newest is %s' % (name, have, new or '?'))


def check_roundcube(w):
    have = core_version('etc/apps/webmail/program/include/iniset.php', r"RCMAIL_VERSION', '([^']+)'")
    line = '.'.join(have.split('.')[:2])
    same = newest_release('roundcube/roundcubemail', same_line=line)
    compare(w, 'Roundcube (webmail)', have, same[0] if same else '', 'roundcube-%s' % (same[0] if same else ''),
            'Update etc/apps/webmail in sentora-core-2027 (Roundcube\'s release notes say whether it is a security fix), then release.')
    top = newest_release('roundcube/roundcubemail')
    if top and same and ver(top[0])[:2] > ver(same[0])[:2]:
        w.ok.append('Roundcube: a newer line is out too (%s); the %s line still gets fixes' % (top[0], line))


def check_phpmyadmin(w):
    have = core_version('etc/apps/phpmyadmin/libraries/classes/Version.php', r"VERSION = '([0-9][^']*)'")
    new = json.loads(fetch('https://www.phpmyadmin.net/home_page/version.json')).get('version', '')
    compare(w, 'phpMyAdmin', have, new, 'phpmyadmin-' + new,
            'Update etc/apps/phpmyadmin in sentora-core-2027 (see phpmyadmin.net/security), then release.')


def check_matomo(w):
    have = core_version('etc/apps/matomo/core/Version.php', r"VERSION = '([0-9][^']*)'")
    new = fetch('https://api.matomo.org/1.0/getLatestVersion/').strip()
    if not re.match(r'^\d+\.\d+', new):
        raise RuntimeError('unexpected answer: ' + new[:60])
    compare(w, 'Matomo', have, new, 'matomo-' + new,
            'Update etc/apps/matomo in sentora-core-2027 (see matomo.org/security), then release.')


def check_crs(w):
    have = re.search(r'OWASP CRS ([0-9.]+)', raw(CORE, 'etc/modsecurity/coreruleset/VERSION')).group(1)
    line = '.'.join(have.split('.')[:2])
    same = newest_release('coreruleset/coreruleset', 'v', same_line=line)
    compare(w, 'OWASP CRS (ModSecurity rules, %s line)' % line, have, same[0] if same else '', 'crs-%s' % (same[0] if same else ''),
            'Run .github/scripts/update-crs.sh <version> <commit> in sentora-core-2027 (the commit from the release page), then release.')
    top = newest_release('coreruleset/coreruleset', 'v')
    if top and ver(top[0])[:2] > ver(have)[:2]:
        w.ok.append('OWASP CRS: a newer line is out (%s); Sentora stays on the LTS line %s until it is chosen' % (top[0], line))


def check_snuffleupagus(w):
    m = re.search(r'^SP_VERSION=(\S+)', open(os.path.join(ROOT, 'preconf', 'php-build', 'snuffleupagus.sh')).read(), re.M)
    have = m.group(1)
    top = newest_release('jvoisin/snuffleupagus', 'v')
    compare(w, 'Snuffleupagus', have, top[0] if top else '', 'snuffleupagus-%s' % (top[0] if top else ''),
            'Set SP_VERSION and SP_COMMIT in preconf/php-build/snuffleupagus.sh, then run Actions > PHP bundles for every system.')


def check_sogo(w):
    tags = gh('repos/Alinto/sogo/tags?per_page=100')
    best = None
    for t in tags:
        m = re.match(r'^SOGo-(\d+\.\d+\.\d+)$', t.get('name', ''))
        if m and (best is None or ver(m.group(1)) > ver(best[0])):
            best = (m.group(1), t['commit']['url'])
    if not best:
        raise RuntimeError('no SOGo release tags found')
    when = gh(best[1].replace('https://api.github.com/', ''))['commit']['committer']['date']
    for system in ('alma10', 'fedora45'):
        a = release_asset('sentora-sogo-%s-amd64.tar.gz' % system)
        if not a:
            continue
        if a.get('updated_at', '') < when:
            w.find('sogo-%s-%s' % (best[0], system), 'SOGo %s is out (%s); Sentora\'s %s build is from %s' % (
                best[0], when[:10], system, a.get('updated_at', '')[:10]),
                'Run Actions > SOGo bundle (system %s, version empty = newest), then Actions > Install test.' % system)
        else:
            w.ok.append('SOGo (%s build): built %s, after SOGo %s' % (system, a.get('updated_at', '')[:10], best[0]))


# ----- report -------------------------------------------------------------------------------------

def main():
    days = 10
    if '--days' in sys.argv:
        days = int(sys.argv[sys.argv.index('--days') + 1])
    w = Watch(read_ack())
    w.check('CISA KEV', lambda: check_kev(w, days))
    w.check('PHP builds', lambda: check_php(w))
    w.check('Roundcube', lambda: check_roundcube(w))
    w.check('phpMyAdmin', lambda: check_phpmyadmin(w))
    w.check('Matomo', lambda: check_matomo(w))
    w.check('SOGo', lambda: check_sogo(w))
    w.check('OWASP CRS', lambda: check_crs(w))
    w.check('Snuffleupagus', lambda: check_snuffleupagus(w))

    new = [f for f in w.findings if f[0] not in w.ack]
    old = [f for f in w.findings if f[0] in w.ack]
    out = ['# Sentora security watch, %s' % datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC'), '']
    out.append('**%s**' % ('%d thing(s) need attention' % len(new) if new else 'Nothing new needs attention'))
    out.append('')
    if new:
        out.append('## Needs attention')
        for key, title, todo in new:
            out += ['- **%s**  ' % title, '  %s  ' % todo, '  (seen and being handled? add `%s` to .github/security-watch-ack.txt)' % key]
        out.append('')
    if old:
        out.append('## Already acknowledged')
        for key, title, todo in old:
            out.append('- %s (%s)' % (title, w.ack[key] or 'acknowledged'))
        out.append('')
    if w.errors:
        out.append('## Could not be checked this time')
        out += ['- ' + e for e in w.errors]
        out.append('')
    out.append('## Checked, nothing to do')
    out += ['- ' + o for o in w.ok]
    text = '\n'.join(out) + '\n'
    print(text)
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a', encoding='utf-8') as f:
            f.write(text)
    for key, title, _ in new:
        print('::error::%s' % title)
    for e in w.errors:
        print('::warning::%s' % e)
    # (a check that could not run fails the run too: a watch that went quiet must not look like "all fine")
    return 1 if new or w.errors else 0


if __name__ == '__main__':
    sys.exit(main())
