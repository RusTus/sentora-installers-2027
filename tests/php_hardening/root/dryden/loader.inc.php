<?php
// Stand-in panel for testing PHP Hardening: SQLite in place of MySQL, small stand-ins for the core
// classes. Env: PH_DB (database file), PH_W (the test's folder: fake /opt/sentora-php, hosted, panel),
// PH_ME (logged-in account).

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

$zdbh = new TestPDO('sqlite:' . getenv('PH_DB'));
$zdbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$zdbh->exec('PRAGMA busy_timeout=5000');
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_accounts (ac_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ac_user_vc TEXT, ac_group_fk INT, ac_deleted_ts INT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_modules (mo_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, mo_folder_vc TEXT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_groups (ug_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ug_name_vc TEXT)");
$zdbh->exec("CREATE TABLE IF NOT EXISTS x_permissions (pe_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, pe_group_fk INT, pe_module_fk INT)");
date_default_timezone_set('UTC');

class ctrl_users
{
    static function GetUserDetail($uid = null)
    {
        global $zdbh;
        $uid = $uid === null ? (int) (isset($GLOBALS['PH_ME']) ? $GLOBALS['PH_ME'] : getenv('PH_ME')) : (int) $uid;
        $q = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_id_pk = :u");
        $q->execute(array(':u' => $uid));
        $a = $q->fetch(PDO::FETCH_ASSOC);
        return $a ? array('userid' => (string) $a['ac_id_pk'], 'username' => $a['ac_user_vc'], 'usergroupid' => (string) $a['ac_group_fk'])
            : array('userid' => '', 'username' => '', 'usergroupid' => '');
    }
}

class ctrl_options
{
    static function GetSystemOption($n)
    {
        $w = getenv('PH_W');
        $o = array('sentora_root' => $w . '/panel/', 'hosted_dir' => $w . '/hosted/');
        return isset($o[$n]) ? $o[$n] : '';
    }
}

class sys_platform
{
    static function Get($name, $fill = array())
    {
        $o = array('php_fpm_process' => 'fake-php-fpm{v}', 'php_fpm_service' => 'php{v}-fpm');
        $v = isset($o[$name]) ? $o[$name] : '';
        foreach ($fill as $k => $x) {
            $v = str_replace('{' . $k . '}', $x, $v);
        }
        return $v;
    }
}

class sys_phpversions
{
    const BASE_DIR = PH_BASE_DIR;

    static function DefaultVersion()
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    static function Installed()
    {
        $out = array();
        foreach ((array) glob(self::BASE_DIR . '*/sbin/php-fpm') as $b) {
            $out[] = basename(dirname(dirname($b)));
        }
        usort($out, 'version_compare');
        return $out;
    }

    static function Service($v)
    {
        return 'sentora-php' . $v . '-fpm';
    }
}
define('PH_BASE_DIR', getenv('PH_W') . '/opt/sentora-php/');

class sys_phpbundles
{
    const REPO = 'RusTus/sentora-installers-2027';
    const TAG = 'current';

    static function Tag()
    {
        return 'ubuntu26.04';
    }

    static function Fetch($url)
    {
        return false;
    }
}

class sys_accountphp
{
    static function ServiceName($v)
    {
        return $v === sys_phpversions::DefaultVersion() ? sys_platform::Get('php_fpm_service', array('v' => $v)) : sys_phpversions::Service($v);
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
