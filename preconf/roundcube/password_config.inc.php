<?php
/*
 * Roundcube password plugin: lets mailbox users change their own password.
 * Stores the same {SHA512-CRYPT} format the Sentora panel writes (salted SHA-512
 * crypt, 50,000 rounds), so Dovecot, Postfix SMTP AUTH and SOGo all keep working
 * with the new password.
 */
$config['password_driver'] = 'sql';
$config['password_confirm_current'] = true;
$config['password_minimum_length'] = 8;
$config['password_algorithm'] = 'sha512-crypt';
$config['password_algorithm_prefix'] = true; // {SHA512-CRYPT} in front, as Dovecot reads it
$config['password_crypt_rounds'] = 50000;
$config['password_db_dsn'] = 'mysql://postfix:!POSTFIX_PASSWORD!@localhost/sentora_postfix';
$config['password_query'] = "UPDATE mailbox SET password = %P, modified = NOW() WHERE username = %u LIMIT 1";
