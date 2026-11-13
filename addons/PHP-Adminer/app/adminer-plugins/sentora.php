<?php

/** Sentora: only the logged-in panel account's own MySQL users may log in; no
* "permanent login" cookie; extra security headers; the panel's look in its Light or Dark.
* Set up by ../index.php. Updated by RusTus, 2026-11-08
*/
class AdminerSentora extends Adminer\Plugin {
	protected $users, $server, $serverName, $userDbs;

	const NOT_YOURS = 'Log in with one of your own MySQL users (Database Management > MySQL Users in the panel).';

	/**
	* @param list<string> $users the MySQL user names of the panel account
	* @param string $server the only MySQL server Adminer may connect to
	* @param string $serverName its name in the login form (login-servers)
	* @param array<string, list<string>> $userDbs each MySQL user's databases (MySQL Users)
	*/
	function __construct(array $users, $server, $serverName, array $userDbs = array()) {
		$this->users = $users;
		$this->userDbs = $userDbs;
		$this->server = $server;
		$this->serverName = $serverName;
		// The login form can only name this server; anything else sent is replaced
		if (isset($_POST['auth']) && is_array($_POST['auth'])) {
			$_POST['auth']['server'] = $serverName;
		}
		// No "permanent login": credentials are never kept in a cookie
		if (isset($_POST['auth']['permanent'])) {
			unset($_POST['auth']['permanent']);
		}
	}

	function head($dark = null) {
		// Adminer draws the "Permanent login" box itself; it does nothing here, so hide it
		echo Adminer\script("document.addEventListener('DOMContentLoaded', function () { var e = document.querySelector('[name=\"auth[permanent]\"]'); if (e) { e.closest('label').remove(); } });");
	}

	/**
	* Adminer asks for the connection details before it connects: a user name that is
	* not one of the account's own gets the login page with an error and no connection
	* is made (so Adminer can not be used to try passwords for root or anyone else's
	* users); allowed users always connect to this server, whatever the form sent.
	*/
	function credentials() {
		$user = isset($_GET['username']) ? (string) $_GET['username'] : '';
		if (Adminer\SERVER !== $this->serverName) {
			$none = array();
			Adminer\auth_error('Only the MySQL server of this panel can be used.', $none);
			exit;
		}
		if (!in_array($user, $this->users, true)) {
			$none = array();
			Adminer\auth_error(Adminer\h(self::NOT_YOURS), $none);
			exit;
		}
		return array($this->server, $user, Adminer\get_password());
	}

	function login($login, $password) {
		if (!in_array((string) $login, $this->users, true)) {
			return self::NOT_YOURS;
		}
	}

	function loginFormField($name, $heading, $value) {
		if ($name == 'server') {
			return ''; // always this server's MySQL: no row for it
		}
		if ($name == 'db') {
			// The account's databases, narrowed to those the chosen MySQL user may use;
			// left on "Select database", the login lists all of that user's databases
			$dbUsers = array();
			foreach ($this->userDbs as $user => $dbs) {
				foreach ($dbs as $db) {
					$dbUsers[$db][] = $user;
				}
			}
			ksort($dbUsers);
			$current = isset($_GET['db']) ? (string) $_GET['db'] : '';
			$html = '<select name="auth[db]" id="sentora-db"><option value="">Select database</option>';
			foreach ($dbUsers as $db => $users) {
				$html .= '<option value="' . Adminer\h($db) . '" data-users="' . Adminer\h(implode(' ', $users)) . '"'
					. ($db === $current ? ' selected' : '') . '>' . Adminer\h($db) . '</option>';
			}
			$html .= '</select>';
			$html .= Adminer\script("(function () {
	var user = document.querySelector('[name=\"auth[username]\"]'), db = document.getElementById('sentora-db');
	if (!user || !db) { return; }
	function narrow() {
		var u = user.value;
		Array.prototype.forEach.call(db.options, function (o) {
			if (!o.value) { return; }
			var ok = (' ' + o.getAttribute('data-users') + ' ').indexOf(' ' + u + ' ') >= 0;
			o.hidden = !ok; o.disabled = !ok;
			if (!ok && o.selected) { db.value = ''; }
		});
	}
	user.addEventListener('change', narrow);
	narrow();
})();");
			return $heading . $html . "\n";
		}
		if ($name == 'username' && count($this->users)) {
			// the account's own MySQL users to choose from
			$options = array_combine($this->users, $this->users);
			return $heading . Adminer\html_select('auth[username]', $options, (isset($_GET['username']) ? $_GET['username'] : reset($this->users))) . "\n";
		}
	}

	/**
	* The Sentora style in the panel's Light or Dark (the panel's PHP-Adminer page sets the
	* sentora_theme cookie for this folder): adminer.css, plus adminer-dark.css for Dark. Both are
	* given as "light" so Adminer adds no dark theme of its own and no media query: the panel's
	* choice decides, not the computer's. A design picked in the design chooser still wins.
	*/
	function css() {
		if (!empty($_SESSION['design'])) {
			return null; // the designs plugin shows the chosen one
		}
		$files = array('adminer.css');
		if (isset($_COOKIE['sentora_theme']) && $_COOKIE['sentora_theme'] === 'dark') {
			$files[] = 'adminer-dark.css';
		}
		$return = array();
		foreach ($files as $file) {
			if (is_file($file)) {
				$return[$file . '?v=' . crc32((string) file_get_contents($file))] = 'light';
			}
		}
		return $return;
	}

	/** No connections to adminer.org (version checks are off): drop it from Adminer's security policy */
	function csp(array $csp) {
		foreach ($csp as $i => $policy) {
			foreach ($policy as $directive => $sources) {
				$left = trim(preg_replace('~\s*https?://(www\.)?adminer\.org/?\S*~', '', $sources));
				$csp[$i][$directive] = ($left === '' ? "'none'" : $left);
			}
		}
		return $csp;
	}

	function headers() {
		header('Referrer-Policy: no-referrer');
		header('X-Content-Type-Options: nosniff');
	}
}
