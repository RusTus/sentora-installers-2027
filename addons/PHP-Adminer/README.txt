PHP-Adminer for Sentora (Ubuntu 26.04): Adminer 6.3.0 (https://www.adminer.org/,
Apache 2.0 / GPL 2) for each account's own MySQL databases.

Install: Admin > Module Admin > upload PHP-Adminer.zpp (the daemon installs it
within 5 minutes and makes it available to every user group).

How it is locked down
- app/index.php is the only way in (app/.htaccess refuses adminer.php and the
  plugins): it requires a panel login and offers only that account's MySQL users
  (from MySQL Users); any other user name is refused.
- login-servers: only this server's MySQL (localhost); Adminer can not be pointed at
  other machines, and the login form has no Server row.
- The Database field lists the account's own databases, narrowed to those the chosen
  MySQL user may use (MySQL Users); MySQL's own permissions still decide access.
- Any other user name (root, other accounts' users) is refused before Adminer
  connects, so it can not be used to try passwords; a tampered server field is
  ignored.
- Adminer's session is tied to the panel account; no "permanent login" cookie.
- adminer.org is removed from Adminer's security policy: the browser can not
  contact it.
- frames (same origin only), version-noverify, donation (hidden), timeout (30 s per
  SELECT), database-hide (MySQL's own system databases). Adminer's own login
  throttling (per address) stays on.

Style: app/adminer.css and app/adminer-dark.css style Adminer like the Sentora panel, in
the panel's own Light or Dark: the panel's PHP-Adminer page sets a sentora_theme cookie
for app/ before Adminer loads, and adminer-plugins/sentora.php loads adminer.css (plus
adminer-dark.css for Dark). Adminer's own dark theme is never loaded. Adminer's list of
loaded plugins is hidden. The design chooser can still pick any of the other themes.
The page around Adminer (module.zpm, assets/adminer-page.css) matches the panel's other
modules; assets/icon.svg is its sidebar icon.

Plugins: tables-filter, dump-zip, dump-date, import-csv, edit-textarea, enum-option,
row-numbers, designs (21 themes; 6 that load fonts from Google or a
CDN are left out). Not included: plugins that write files to the server, send mail,
log queries, use outside services or allow empty passwords.

Updating Adminer: replace app/adminer.php (and plugins in app/adminer-plugins/).
