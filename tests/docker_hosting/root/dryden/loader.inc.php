<?php
// Stand-in panel for testing Docker Hosting: SQLite in place of MySQL, small stand-ins for the core classes.
// Database file: env DH_DB. Logged-in account: $GLOBALS['DH_ME'].

class TestPDO extends PDO
{
    static function rw($sql)
    {
        $sql = preg_replace('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', '', $sql);
        $sql = str_replace('INT NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/,\s*KEY \w+ \([^)]*\)/', '', $sql);
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

class MyPDO extends PDO
{
}

if (getenv('DH_MYSQL')) {
    // a real MySQL or MariaDB (DH_MYSQL = "host;port;user;password;database"): the SQL as it is
    list($h, $p, $u, $pw, $db) = explode(';', getenv('DH_MYSQL'));
    $zdbh = new MyPDO("mysql:host=$h;port=$p;dbname=$db;charset=utf8mb4", $u, $pw);
    $zdbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_accounts (ac_id_pk int(6) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, ac_user_vc varchar(50), ac_email_vc varchar(200) DEFAULT '', ac_reseller_fk int(6), ac_group_fk int(6),
        ac_enabled_in int(1) DEFAULT 1, ac_deleted_ts int(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_vhosts (vh_id_pk int(6) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, vh_acc_fk int(6), vh_name_vc varchar(255) CHARACTER SET latin1 COLLATE latin1_general_ci,
        vh_type_in int(1) DEFAULT 1, vh_deleted_ts int(30)) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dns (dn_id_pk int(6) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, dn_acc_fk int(6), dn_name_vc varchar(255), dn_vhost_fk int(6), dn_type_vc varchar(50),
        dn_host_vc varchar(100), dn_ttl_in int(30), dn_target_vc varchar(2000), dn_texttarget_tx text, dn_priority_in int(50), dn_weight_in int(50), dn_port_in int(50), dn_created_ts int(30),
        dn_deleted_ts int(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_settings (so_name_vc varchar(50) PRIMARY KEY, so_value_tx text) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_modules (mo_id_pk int(6) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, mo_folder_vc varchar(255), mo_name_vc varchar(200), mo_enabled_en enum('true','false'))");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_groups (ug_id_pk int(6) unsigned NOT NULL PRIMARY KEY)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_permissions (pe_group_fk int(6), pe_module_fk int(6))");
} else {
    $zdbh = new TestPDO('sqlite:' . getenv('DH_DB'));
    $zdbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $zdbh->exec('PRAGMA journal_mode=WAL');
    $zdbh->exec('PRAGMA busy_timeout=10000');
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_accounts (ac_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ac_user_vc TEXT, ac_email_vc TEXT DEFAULT '', ac_reseller_fk INT, ac_group_fk INT, ac_enabled_in INT DEFAULT 1, ac_deleted_ts INT)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_vhosts (vh_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, vh_acc_fk INT, vh_name_vc TEXT, vh_type_in INT DEFAULT 1, vh_deleted_ts INT)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_dns (dn_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, dn_acc_fk INT, dn_name_vc TEXT, dn_vhost_fk INT, dn_type_vc TEXT, dn_host_vc TEXT, dn_ttl_in INT,
        dn_target_vc TEXT, dn_texttarget_tx TEXT, dn_priority_in INT, dn_weight_in INT, dn_port_in INT, dn_created_ts INT, dn_deleted_ts INT)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_settings (so_name_vc TEXT PRIMARY KEY, so_value_tx TEXT)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_modules (mo_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, mo_folder_vc TEXT, mo_name_vc TEXT, mo_enabled_en TEXT)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_groups (ug_id_pk INTEGER PRIMARY KEY)");
    $zdbh->exec("CREATE TABLE IF NOT EXISTS x_permissions (pe_group_fk INT, pe_module_fk INT)");
}

$security = array('server_crypto_key' => getenv('DH_SECRET') !== false ? getenv('DH_SECRET') : 'test-server-key-0123456789abcdef');

class ctrl_users
{
    static function GetUserDetail($uid = null)
    {
        $uid = $uid === null ? (int) $GLOBALS['DH_ME'] : (int) $uid;
        return array('userid' => (string) $uid, 'usergroupid' => '');
    }
}

class ctrl_secret
{
    static function K()
    {
        global $security;
        return strlen($security['server_crypto_key']) < 16 ? false : sodium_crypto_generichash('sentora stored passwords|' . $security['server_crypto_key'], '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    static function Encrypt($p)
    {
        if ((string) $p === '' || self::K() === false) {
            return '';
        }
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc1:' . base64_encode($n . sodium_crypto_secretbox((string) $p, $n, self::K()));
    }

    static function Decrypt($s)
    {
        if (self::K() === false || !is_string($s) || strncmp($s, 'enc1:', 5) !== 0) {
            return false;
        }
        $r = base64_decode(substr($s, 5));
        $p = sodium_crypto_secretbox_open(substr($r, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($r, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::K());
        return $p === false ? false : $p;
    }
}

class ctrl_options
{
    static function GetSystemOption($n)
    {
        global $zdbh;
        if ($n === 'sentora_domain') {
            return 'panel.example';
        }
        $q = $zdbh->prepare("SELECT so_value_tx FROM x_settings WHERE so_name_vc = :n");
        $q->execute(array(':n' => $n));
        return (string) $q->fetchColumn();
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

class ui_language
{
    static function translate($s)
    {
        return $s;
    }
}

class ui_module
{
    static function CheckModuleEnabled($n)
    {
        return getenv('DH_MODULE_OFF') ? false : true;
    }
}

class fs_filehandler
{
    static function NewLine()
    {
        return "\n";
    }
}

// (mail: written to the file DH_MAIL)
class sys_email
{
    public $Subject = '';
    public $Body = '';
    public $to = array();

    public function AddAddress($a)
    {
        $this->to[] = $a;
    }

    public function SendEmail()
    {
        file_put_contents(getenv('DH_MAIL'), "To: " . implode(', ', $this->to) . "\nSubject: " . $this->Subject . "\n\n" . $this->Body . "\n----\n", FILE_APPEND);
        return true;
    }
}

class ctrl_module
{
    // (as the core's: the module's name, description and icon for module.zpm)
    public static function getModuleName()
    {
        return 'Docker Hosting';
    }

    public static function getModuleDesc()
    {
        return 'desc';
    }

    public static function getModuleIcon()
    {
        return 'modules/docker_hosting/assets/icon.png';
    }
}
