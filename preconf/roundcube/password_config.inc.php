<?php
/*
 * Roundcube password plugin: lets mailbox users change their own password.
 * Stores the same {PLAIN-MD5} format the Sentora panel writes, so the panel,
 * Dovecot and Postfix SMTP AUTH all keep working with the new password.
 */
$config['password_driver'] = 'sql';
$config['password_confirm_current'] = true;
$config['password_minimum_length'] = 8;
$config['password_algorithm'] = 'clear';
$config['password_db_dsn'] = 'mysql://postfix:!POSTFIX_PASSWORD!@localhost/sentora_postfix';
$config['password_query'] = "UPDATE mailbox SET password = CONCAT('{PLAIN-MD5}', MD5(%P)), modified = NOW() WHERE username = %u LIMIT 1";
