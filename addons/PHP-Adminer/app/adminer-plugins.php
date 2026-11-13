<?php
// PHP-Adminer for Sentora: the plugins in use (adminer-plugins/), first to last
$designs = array();
foreach (glob('designs/*/*.css') as $file) {
	$designs[$file] = basename(dirname($file));
}
ksort($designs);

// this server's MySQL only: Adminer can not be pointed at any other machine
$server = 'localhost';
$serverName = defined('SENTORA_ADMINER_SERVER') ? SENTORA_ADMINER_SERVER : 'MySQL on this server'; // (index.php)

return array(
	new AdminerSentora(isset($GLOBALS['sentora_adminer_users']) ? $GLOBALS['sentora_adminer_users'] : array(), $server, $serverName,
		isset($GLOBALS['sentora_adminer_dbs']) ? $GLOBALS['sentora_adminer_dbs'] : array()),
	new AdminerLoginServers(array($serverName => array('server' => $server, 'driver' => 'server'))),
	new AdminerVersionNoverify,                        // no update checks to adminer.org
	new AdminerFrames(true),                           // may be framed by the panel only
	new AdminerDatabaseHide(array('information_schema', 'mysql', 'performance_schema', 'sys')),
	new AdminerTimeout(30),                            // a SELECT stops after 30 seconds
	new AdminerDonation(''),                           // no donation link
	new AdminerTablesFilter,
	new AdminerDumpZip,
	new AdminerDumpDate,
	new AdminerImportCsv,
	new AdminerEditTextarea,
	new AdminerEnumOption,
	new AdminerRowNumbers,
	new AdminerDesigns($designs),
);
