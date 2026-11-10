<?php
// Stand-in panel for testing Payment Plans: SQLite in place of MySQL, small stand-ins for the core classes.
// Database file: env PP_DB. Logged-in account: env PP_ME or $GLOBALS['PP_ME'].

class TestPDO extends PDO
{
    static $log = array();

    static function rw($sql)
    {
        $o = $sql;
        if (strpos($sql, 'UPDATE x_pp_invoices i JOIN x_pp_sellers s') !== false) {
            return "UPDATE x_pp_invoices SET in_test_in = 1 WHERE in_seller_fk IN (SELECT ps_acc_fk FROM x_pp_sellers WHERE ps_mode_vc = 'sandbox')";
        }
        if (preg_match('/^\s*ALTER TABLE \S+ MODIFY/i', $sql)) {
            self::$log[] = 'skipped (sqlite): ' . $sql;
            return 'SELECT 1';
        }
        $sql = str_replace("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c",
            "SELECT COUNT(*) FROM pragma_table_info(:t) WHERE name = :c", $sql);
        $sql = preg_replace('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', '', $sql);
        $sql = str_replace('INT NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
        $sql = preg_replace('/,\s*KEY \w+ \([^)]*\)/', '', $sql);
        $sql = str_replace(' FOR UPDATE', '', $sql);
        $sql = str_replace('GREATEST(', 'MAX(', $sql);
        return $sql;
    }

    #[\ReturnTypeWillChange]
    function prepare($q, $o = array())
    {
        return parent::prepare(self::rw($q), $o);
    }

    #[\ReturnTypeWillChange]
    function exec($q)
    {
        return parent::exec(self::rw($q));
    }

    #[\ReturnTypeWillChange]
    function query($q, $mode = null, ...$a)
    {
        return $mode === null ? parent::query(self::rw($q)) : parent::query(self::rw($q), $mode, ...$a);
    }
}

$zdbh = new TestPDO('sqlite:' . getenv('PP_DB'));
$zdbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$zdbh->exec('PRAGMA journal_mode=WAL');
$zdbh->exec('PRAGMA busy_timeout=5000');
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_accounts (ac_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ac_user_vc TEXT, ac_email_vc TEXT, ac_reseller_fk INT, ac_package_fk INT,
    ac_group_fk INT, ac_enabled_in INT DEFAULT 1, ac_created_ts INT, ac_deleted_ts INT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_packages (pk_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, pk_name_vc TEXT, pk_reseller_fk INT, pk_deleted_ts INT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_profiles (ud_user_fk INT, ud_fullname_vc TEXT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_account_suspend (as_acc_fk INT PRIMARY KEY, as_reason_vc TEXT, as_by_fk INT, as_time_in INT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS t_events (ev TEXT)");

function test_event($e)
{
    global $zdbh;
    $zdbh->prepare("INSERT INTO t_events (ev) VALUES (:e)")->execute(array(':e' => $e));
}

$security = array('server_crypto_key' => 'test-server-key-0123456789abcdef');
date_default_timezone_set('America/Chicago');

class ctrl_users
{
    static function GetUserDetail($uid = null)
    {
        global $zdbh;
        $uid = $uid === null ? (int) (isset($GLOBALS['PP_ME']) ? $GLOBALS['PP_ME'] : getenv('PP_ME')) : (int) $uid;
        $q = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_id_pk = :u");
        $q->execute(array(':u' => $uid));
        $a = $q->fetch(PDO::FETCH_ASSOC);
        return $a ? array('userid' => (string) $a['ac_id_pk'], 'username' => $a['ac_user_vc'], 'email' => (string) $a['ac_email_vc'], 'usergroupid' => (string) $a['ac_group_fk'])
            : array('userid' => '', 'username' => '', 'email' => '', 'usergroupid' => '');
    }
}

class ctrl_secret
{
    static function K()
    {
        return sodium_crypto_generichash('test', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    static function Encrypt($p)
    {
        if ((string) $p === '' || getenv('PP_NOKEY')) {
            return '';
        }
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc1:' . base64_encode($n . sodium_crypto_secretbox((string) $p, $n, self::K()));
    }

    static function Decrypt($s)
    {
        if (!is_string($s) || strncmp($s, 'enc1:', 5) !== 0) {
            return false;
        }
        $r = base64_decode(substr($s, 5));
        $p = sodium_crypto_secretbox_open(substr($r, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($r, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::K());
        return $p === false ? false : $p;
    }
}

class ctrl_notices
{
    static function Send(array $n)
    {
        test_event('notice:' . $n['key'] . ':' . $n['type'] . ':' . $n['title']);
    }

    static function Withdraw($k, $by = 0)
    {
        test_event('withdraw:' . $k);
    }
}

class sys_email
{
    public $Subject = '';
    public $Body = '';
    public $to = array();

    function AddAddress($a)
    {
        $this->to[] = $a;
    }

    function AddReplyTo($a, $n = '')
    {
    }

    function SendEmail()
    {
        test_event('email:' . implode(',', $this->to) . ':' . $this->Subject . ':' . str_replace("\n", ' | ', $this->Body));
        return true;
    }
}

class ws_suspend
{
    static function Set($uid, $reason, $by)
    {
        global $zdbh;
        $zdbh->prepare("REPLACE INTO x_account_suspend (as_acc_fk, as_reason_vc, as_by_fk, as_time_in) VALUES (:u, :r, :b, :t)")
            ->execute(array(':u' => (int) $uid, ':r' => (string) $reason, ':b' => (int) $by, ':t' => time()));
    }

    static function Reason($uid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT as_reason_vc FROM x_account_suspend WHERE as_acc_fk = :u");
        $q->execute(array(':u' => (int) $uid));
        return (string) $q->fetchColumn();
    }

    static function Clear($uid)
    {
        global $zdbh;
        $zdbh->prepare("DELETE FROM x_account_suspend WHERE as_acc_fk = :u")->execute(array(':u' => (int) $uid));
    }
}

class runtime_hook
{
    static function Execute($n)
    {
        test_event('hook:' . $n);
    }
}

class runtime_csfr
{
    static function Protect()
    {
    }

    static function Token()
    {
        return '<input type="hidden" name="csfr_token" value="t">';
    }
}

class ctrl_options
{
    static function GetSystemOption($n)
    {
        return $n === 'sentora_root' ? dirname(__DIR__) : ($n === 'sentora_domain' ? 'panel.example' : '');
    }
}

class ctrl_branding
{
    static function PanelUrlFor($uid)
    {
        return 'https://panel.example';
    }
}

class ui_language
{
    static function translate($s)
    {
        return $s;
    }

    static function Date($f, $t)
    {
        return date($f, $t);
    }
}

class ui_module
{
    static function CheckModuleEnabled($n)
    {
        return true;
    }
}

class fs_filehandler
{
    static function NewLine()
    {
        return "\n";
    }
}

class ctrl_module
{
}

// the fake PayPal and Stripe (env PP_FAKE: http://127.0.0.1:port)
if (getenv('PP_FAKE') && !getenv('PP_OLD')) {
    require_once dirname(__DIR__) . '/modules/payment_plans/code/payments.php';
    $f = getenv('PP_FAKE');
    pp_proc_paypal::$api = array('live' => $f . '/paypal/live', 'sandbox' => $f . '/paypal/sandbox');
    pp_proc_paypal::$pages = array($f . '/pages/paypal');
    pp_proc_stripe::$api = $f . '/stripe';
    pp_proc_stripe::$pages = array($f . '/pages/stripe');
}
