<?php
// Sentora-2027 feedback server settings: copy to config.php and change.
return array(
    // the stats page's password, as a hash: php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
    'password_hash' => '',
    // the database (SQLite): a folder the web server can write, outside the website
    'database' => __DIR__ . '/data/feedback.sqlite',
    // reports one address may send in an hour (a panel sends one a day)
    'per_hour' => 12,
    // reports kept per server (one a day: about a year)
    'keep' => 400,
    // behind a proxy (Cloudflare, nginx...): the header that holds the sender's address, such as 'HTTP_X_FORWARDED_FOR'
    'ip_header' => '',
    // the country of each server, from its public address (asked once a month per address); '' for none
    'geo' => 'https://api.country.is/',
);
