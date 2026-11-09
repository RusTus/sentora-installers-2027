<?php
// Sentora-2027 feedback server settings: copy to config.php (in this inc/ folder) and change.
return array(
    // the stats page's password, as a hash: php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
    'password_hash' => '',
    // the database (SQLite): the data/ folder, which the web server must be able to write (its .htaccess refuses every request)
    'database' => __DIR__ . '/../data/feedback.sqlite',
    // reports one address may send in an hour (a panel sends one a day)
    'per_hour' => 12,
    // reports kept per server (one a day: about a year)
    'keep' => 400,
    // behind a proxy (Cloudflare, nginx...): the header that holds the sender's address, such as 'HTTP_X_FORWARDED_FOR'
    'ip_header' => '',
    // the country of each server, from its public address (asked once a month per address); '' for none
    'geo' => 'https://api.country.is/',
    // the add-on repository this server stands in front of (public/repo: it counts the downloads)
    'repo_upstream' => 'https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/sentora-repo',
    // seconds its lists (packages.txt, SHA256SUMS, the .xml files) are kept before they are asked again
    'repo_cache' => 300,
);
