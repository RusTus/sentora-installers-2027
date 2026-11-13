<?php

/**
 * Payment Plans in the menu (panels 2027.89 and newer ask this; older ones always show it): sellers
 * (administrators and resellers) always see it; a client only while billed through the panel, with a
 * plan or an invoice. The page itself stays open to every group that may use it (a client without
 * billing reads "You have no invoices").
 *
 * $user: the account whose menu it is (ctrl_users::GetUserDetail).
 *
 * Written by RusTus, 2026-11-13
 */
global $zdbh;
if (in_array((int) $user['usergroupid'], array(1, 2), true)) {
    return true;
}
$uid = (int) $user['userid'];
try {
    $q = $zdbh->prepare('SELECT (SELECT COUNT(*) FROM x_pp_plans WHERE pl_acc_fk = :a) + (SELECT COUNT(*) FROM x_pp_invoices WHERE in_acc_fk = :b)');
    $q->execute(array(':a' => $uid, ':b' => $uid));
    return (int) $q->fetchColumn() > 0;
} catch (Exception $e) {
    return false;   // (no tables yet: nobody is billed)
}
