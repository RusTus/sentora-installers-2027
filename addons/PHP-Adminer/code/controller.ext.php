<?php

/**
 * PHP-Adminer: Adminer 6.3.0 inside the panel (app/), for each account's own MySQL users on this
 * server, in the panel's style: a bar with the account's MySQL users and databases and New window,
 * then Adminer in a frame (in the panel's Light or Dark, see module.zpm).
 *
 * Updated by RusTus, 2026-11-08
 */
class module_controller extends ctrl_module
{

    /**
     * Escape and translate.
     *
     * @param string $s The English text.
     * @return string
     */
    static function t($s)
    {
        return htmlspecialchars(ui_language::translate($s), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Adminer's address (template <@ AppUrl @>).
     *
     * @return string
     */
    static function getAppUrl()
    {
        return 'modules/' . rawurlencode(basename(dirname(__DIR__))) . '/app/';
    }

    /**
     * How many of something the account has (not deleted).
     *
     * @param string $table x_mysql_users or x_mysql_databases.
     * @return int
     */
    static function Count($table)
    {
        global $zdbh;
        static $n = array();
        if (!isset($n[$table])) {
            $u = ctrl_users::GetUserDetail();
            $col = $table === 'x_mysql_users' ? array('mu_acc_fk', 'mu_deleted_ts') : array('my_acc_fk', 'my_deleted_ts');
            $sql = $zdbh->prepare("SELECT COUNT(*) FROM " . $table . " WHERE " . $col[0] . " = :uid AND " . $col[1] . " IS NULL");
            $sql->execute(array(':uid' => (int) $u['userid']));
            $n[$table] = (int) $sql->fetchColumn();
        }
        return $n[$table];
    }

    /**
     * The account has MySQL users (template <% if HasMySQLUsers %>).
     *
     * @return bool
     */
    static function getHasMySQLUsers()
    {
        return self::Count('x_mysql_users') > 0;
    }

    /**
     * The account has no MySQL users yet (template <% if HasNoMySQLUsers %>).
     *
     * @return bool
     */
    static function getHasNoMySQLUsers()
    {
        return !self::getHasMySQLUsers();
    }

    /**
     * The facts in the bar (<@ Facts @>): the account's MySQL users and databases.
     *
     * @return string The HTML.
     */
    static function getFacts()
    {
        return '<span><b>' . self::t('MySQL users') . '</b> ' . self::Count('x_mysql_users') . '</span>'
            . '<span><b>' . self::t('Databases') . '</b> ' . self::Count('x_mysql_databases') . '</span>';
    }

}

?>
