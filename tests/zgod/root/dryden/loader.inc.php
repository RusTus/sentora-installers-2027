<?php
// Stand-in panel for testing zGod: SQLite in place of MySQL (the panel's own columns), and small stand-ins for
// the core classes it uses. Env: ZG_DB (database file). $GLOBALS['ZG_ME'] is the logged-in account.

$zdbh = new PDO('sqlite:' . getenv('ZG_DB'));
$zdbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
date_default_timezone_set('UTC');
$zgSchema = array(
    'x_accounts' => 'ac_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ac_user_vc TEXT, ac_pass_vc TEXT, ac_email_vc TEXT, ac_reseller_fk INT, ac_package_fk INT, ac_group_fk INT, ac_usertheme_vc TEXT, ac_usercss_vc TEXT, ac_enabled_in INT DEFAULT 1, ac_lastlogon_ts INT, ac_notice_tx TEXT, ac_resethash_tx TEXT, ac_passsalt_vc TEXT, ac_catorder_vc TEXT, ac_created_ts INT, ac_deleted_ts INT',
    'x_profiles' => 'ud_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ud_user_fk INT, ud_fullname_vc TEXT, ud_language_vc TEXT, ud_group_fk INT, ud_package_fk INT, ud_address_tx TEXT, ud_postcode_vc TEXT, ud_phone_vc TEXT, ud_created_ts INT',
    'x_packages' => 'pk_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, pk_name_vc TEXT, pk_reseller_fk INT, pk_enablephp_in INT, pk_created_ts INT, pk_deleted_ts INT',
    'x_quotas' => 'qt_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, qt_package_fk INT, qt_domains_in INT, qt_mailboxes_in INT, qt_diskspace_bi INT, qt_bandwidth_bi INT',
    'x_bandwidth' => 'bd_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, bd_acc_fk INT, bd_month_in INT, bd_transamount_bi INT, bd_diskamount_bi INT, bd_diskover_in INT, bd_diskcheck_in INT, bd_transover_in INT, bd_transcheck_in INT',
    'x_groups' => 'ug_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ug_name_vc TEXT, ug_notes_tx TEXT, ug_reseller_fk INT',
    'x_vhosts' => 'vh_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, vh_acc_fk INT, vh_name_vc TEXT, vh_directory_vc TEXT, vh_type_in INT, vh_active_in INT, vh_enabled_in INT, vh_php_version_vc TEXT, vh_created_ts INT, vh_deleted_ts INT',
    'x_mysql_databases' => 'my_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, my_acc_fk INT, my_name_vc TEXT, my_usedspace_bi INT, my_created_ts INT, my_deleted_ts INT',
    'x_mailboxes' => 'mb_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, mb_acc_fk INT, mb_address_vc TEXT, mb_enabled_in INT, mb_created_ts INT, mb_deleted_ts INT',
    'x_forwarders' => 'fw_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, fw_acc_fk INT, fw_address_vc TEXT, fw_destination_vc TEXT, fw_keepmessage_in INT, fw_created_ts INT, fw_deleted_ts INT',
    'x_aliases' => 'al_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, al_acc_fk INT, al_address_vc TEXT, al_destination_vc TEXT, al_created_ts INT, al_deleted_ts INT',
    'x_distlists' => 'dl_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, dl_acc_fk INT, dl_address_vc TEXT, dl_created_ts INT, dl_deleted_ts INT',
    'x_ftpaccounts' => 'ft_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ft_acc_fk INT, ft_user_vc TEXT, ft_directory_vc TEXT, ft_access_vc TEXT, ft_password_vc TEXT, ft_created_ts INT, ft_deleted_ts INT',
    'x_cronjobs' => 'ct_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, ct_acc_fk INT, ct_script_vc TEXT, ct_timing_vc TEXT, ct_fullpath_vc TEXT, ct_description_tx TEXT, ct_created_ts INT, ct_deleted_ts INT',
    'x_logs' => 'lg_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, lg_user_fk INT, lg_code_vc TEXT, lg_module_vc TEXT, lg_detail_tx TEXT, lg_stack_tx TEXT, lg_when_ts INT',
    'x_modules' => 'mo_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, mo_name_vc TEXT, mo_folder_vc TEXT, mo_desc_tx TEXT, mo_enabled_en TEXT',
    'x_permissions' => 'pe_id_pk INTEGER PRIMARY KEY AUTOINCREMENT, pe_group_fk INT, pe_module_fk INT',
);
foreach ($zgSchema as $t => $cols) {
    $zdbh->exec("CREATE TABLE IF NOT EXISTS $t ($cols)");
}

class ctrl_users
{
    static function GetUserDetail($uid = null)
    {
        global $zdbh;
        $uid = $uid === null ? (int) $GLOBALS['ZG_ME'] : (int) $uid;
        $q = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_id_pk = :u");
        $q->execute(array(':u' => $uid));
        $a = $q->fetch(PDO::FETCH_ASSOC);
        return $a ? array('userid' => (string) $a['ac_id_pk'], 'username' => $a['ac_user_vc'], 'usergroupid' => (string) $a['ac_group_fk'])
            : array('userid' => '', 'username' => '', 'usergroupid' => '');
    }
}

// the panel's form key: a POST without the session's key stops the request (as the real one does)
class runtime_csfr
{
    static function Protect()
    {
        if (!isset($_POST['csfr_token']) || $_POST['csfr_token'] !== 'session-key') {
            throw new RuntimeException('form key refused');
        }
    }

    static function Token()
    {
        return '<input type="hidden" name="csfr_token" value="session-key">';
    }
}

class runtime_hook
{
    static $ran = array();

    static function Execute($n)
    {
        self::$ran[] = $n;
    }
}

// the panel's suspension record (dryden/ws/suspend.class.php), as far as zGod uses it
class ws_suspend
{
    static function Ensure()
    {
        $GLOBALS['zdbh']->exec("CREATE TABLE IF NOT EXISTS x_account_suspend (as_acc_fk INTEGER PRIMARY KEY, as_reason_vc TEXT, as_by_fk INT, as_time_in INT)");
    }

    static function Clean($reason)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason));
        return (mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>\p{Cf}]/u', $reason)) ? false : $reason;
    }

    static function Set($uid, $reason, $by)
    {
        self::Ensure();
        $GLOBALS['zdbh']->prepare("REPLACE INTO x_account_suspend VALUES (:u, :r, :b, :t)")->execute(array(':u' => $uid, ':r' => $reason, ':b' => $by, ':t' => time()));
    }

    static function Reason($uid)
    {
        self::Ensure();
        $q = $GLOBALS['zdbh']->prepare("SELECT as_reason_vc FROM x_account_suspend WHERE as_acc_fk = :u");
        $q->execute(array(':u' => $uid));
        $r = $q->fetchColumn();
        return $r === false ? '' : (string) $r;
    }

    static function Clear($uid)
    {
        self::Ensure();
        $GLOBALS['zdbh']->prepare("DELETE FROM x_account_suspend WHERE as_acc_fk = :u")->execute(array(':u' => $uid));
    }
}

class ui_language
{
    static function translate($s)
    {
        return $s;
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
