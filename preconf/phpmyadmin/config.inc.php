<?php
/**
 * phpMyAdmin 5.2 configuration for Sentora
 * The installer replaces SENTORA with a random 32 character secret.
 */
$cfg['blowfish_secret'] = 'SENTORA';

$i = 0;
$i++;
$cfg['Servers'][$i]['auth_type'] = 'cookie';
// localhost connects over the MySQL socket
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|sys|performance_schema)$';

// Writable folders outside the read-only application tree
$cfg['TempDir'] = '/var/sentora/temp/';
$cfg['UploadDir'] = '/var/sentora/temp/';
$cfg['SaveDir'] = '/var/sentora/temp/';

// Databases and passwords are managed in the Sentora panel
$cfg['ShowCreateDb'] = false;
$cfg['ShowChgPassword'] = false;
$cfg['AllowUserDropDatabase'] = false;
$cfg['PmaNoRelation_DisableWarning'] = true;
$cfg['ShowServerInfo'] = false;
$cfg['VersionCheck'] = false;
$cfg['SendErrorReports'] = 'never';
