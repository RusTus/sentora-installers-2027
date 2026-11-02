<?php
/*
 * Roundcube 1.7 configuration for Sentora.
 * The installer fills in the database password and the des_key.
 * Every other setting uses Roundcube's defaults (config/defaults.inc.php).
 */
$config = [];

// Database
$config['db_dsnw'] = 'mysql://roundcube:!ROUNDCUBE_PASSWORD!@localhost/sentora_roundcube';

// Dovecot on this server
$config['imap_host'] = 'localhost:143';

// Postfix on this server: submission port with STARTTLS, logging in with the
// mailbox's own address and password. The certificate is self-signed and the
// connection never leaves this machine, so its name is not verified.
$config['smtp_host'] = 'tls://localhost:587';
$config['smtp_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
];
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';

// Mailbox names are case sensitive in Sentora, keep them as typed
$config['login_lc'] = 0;

// Encryption key for stored IMAP passwords (24 characters)
$config['des_key'] = '!ROUNDCUBE_DESKEY!';

$config['product_name'] = 'Sentora Webmail';
$config['plugins'] = ['managesieve', 'markasjunk', 'emoticons', 'password'];
$config['skin'] = 'elastic';

// Folders matching Dovecot's namespace and its trash plugin settings
$config['drafts_mbox'] = 'Drafts';
// Same folder the server-side spam filter (globalfilter.sieve) files into
$config['junk_mbox'] = 'Spam';
$config['sent_mbox'] = 'Sent';
$config['trash_mbox'] = 'Trash';
$config['create_default_folders'] = true;

$config['show_images'] = 2;
$config['htmleditor'] = 1;
$config['draft_autosave'] = 120;
$config['display_next'] = false;

// Logs and temporary files outside the application folder
$config['log_dir'] = '/var/sentora/logs/roundcube/';
$config['temp_dir'] = '/var/sentora/temp/roundcube/';

$config['enable_installer'] = false;
