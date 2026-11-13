<?php

/**
 * Payment Plans' daemon work (every run): automatic plans for new clients on priced packages,
 * invoices made ahead of each paid period, reminders on the due date, clients switched off past
 * their seller's grace days, and payments whose client did not come back to the panel settled from
 * the processor's own answer.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-09
 */
class pp_daemon
{
    /**
     * One run.
     *
     * @global db_driver $zdbh The panel's database connection.
     * @return string[] What happened (for the daemon's log).
     */
    static function Run($seller = 0)
    {
        global $zdbh;
        pp::Ensure();
        $log = array();
        $now = time();
        // (one seller's only: its "Run billing now" in test mode)
        $only = (int) $seller > 0 ? (int) $seller : 0;
        // 0. automatic plans: new clients on a priced package
        $log = array_merge($log, pp::AutoPlans($only));
        // 1. payments not settled: the client paid and did not come back, or the payment was pending
        $q = $zdbh->prepare("SELECT * FROM x_pp_invoices WHERE ((in_status_vc = 'pending') OR (in_status_vc = 'open' AND in_order_vc IS NOT NULL AND in_order_in BETWEEN :a AND :b))"
            . ($only ? " AND in_seller_fk = " . $only : ''));
        $q->execute(array(':a' => $now - 3 * 86400, ':b' => $now - ($only ? 0 : 120)));
        // (at most 2 minutes of asking: a slow or unreachable payment service never holds up the
        // daemon's other work; what is left is asked about on the next run)
        $until = microtime(true) + 120;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            if (microtime(true) > $until) {
                $log[] = 'Payment Plans: the payment services are slow; the other payments are checked on the next run';
                break;
            }
            $r = pp_pay::Settle($inv);
            if ($r === 'paid') {
                $log[] = 'Payment Plans: invoice ' . $inv['in_number_vc'] . ' paid with ' . pp_pay::Name((string) $inv['in_proc_vc']);
            }
        }
        // (2 and 3: only for sellers with Payment Plans on; off skips the payment check altogether)
        // 2. the next invoice of each plan, the seller's days ahead of what is paid
        foreach ($zdbh->query("SELECT p.*, s.ps_lead_in, s.ps_mode_vc FROM x_pp_plans p JOIN x_pp_sellers s ON s.ps_acc_fk = p.pl_seller_fk
            JOIN x_accounts a ON a.ac_id_pk = p.pl_acc_fk AND a.ac_deleted_ts IS NULL WHERE p.pl_active_in = 1 AND s.ps_on_in = 1" . ($only ? " AND p.pl_seller_fk = " . $only : ''))->fetchAll(PDO::FETCH_ASSOC) as $plan) {
            if ((int) $plan['pl_paiduntil_in'] - (int) $plan['ps_lead_in'] * 86400 > $now) {
                continue;
            }
            // a client moved to another account: its new seller bills it (or not), never this one
            if (pp::SellerOf((int) $plan['pl_acc_fk']) !== (int) $plan['pl_seller_fk']) {
                $zdbh->prepare("UPDATE x_pp_plans SET pl_active_in = 0 WHERE pl_acc_fk = :u AND pl_seller_fk = :s")
                    ->execute(array(':u' => (int) $plan['pl_acc_fk'], ':s' => (int) $plan['pl_seller_fk']));
                $log[] = 'Payment Plans: plan of account ' . (int) $plan['pl_acc_fk'] . ' stopped (it belongs to another account now)';
                continue;
            }
            // (live: a TEST invoice left from testing does not hold up the real ones)
            $open = $zdbh->prepare("SELECT COUNT(*) FROM x_pp_invoices WHERE in_acc_fk = :u AND in_status_vc IN ('open', 'pending')" . ((string) $plan['ps_mode_vc'] === 'live' ? " AND in_test_in = 0" : ''));
            $open->execute(array(':u' => (int) $plan['pl_acc_fk']));
            if ((int) $open->fetchColumn() > 0) {
                continue;
            }
            $r = pp::MakeInvoice((int) $plan['pl_acc_fk']);
            $log[] = 'Payment Plans: ' . (is_array($r) ? 'invoice ' . $r['in_number_vc'] . ' made' : 'no invoice for account ' . (int) $plan['pl_acc_fk'] . ': ' . $r);
        }
        // 3. reminders on the due date, then switched off past the grace days
        // (a TEST invoice only while its seller is testing: after going live it reminds and switches off nobody)
        foreach ($zdbh->query("SELECT i.*, s.ps_grace_in, s.ps_suspend_in FROM x_pp_invoices i JOIN x_pp_sellers s ON s.ps_acc_fk = i.in_seller_fk
            WHERE i.in_status_vc = 'open' AND s.ps_on_in = 1 AND (i.in_test_in = 0 OR s.ps_mode_vc <> 'live') AND i.in_due_in <= " . (int) $now . ($only ? " AND i.in_seller_fk = " . $only : ''))->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            if ((int) $inv['in_reminded_in'] < 1) {
                pp::Tell($inv, 'due');
                $zdbh->prepare("UPDATE x_pp_invoices SET in_reminded_in = 1 WHERE in_id_pk = :i")->execute(array(':i' => (int) $inv['in_id_pk']));
                $log[] = 'Payment Plans: invoice ' . $inv['in_number_vc'] . ' is due (reminded)';
            }
            // (never within a day of the invoice being made, whatever the grace days: a new client is
            // not switched off minutes after its first invoice)
            if ((int) $inv['in_reminded_in'] < 2 && (int) $inv['ps_suspend_in'] && $now >= (int) $inv['in_due_in'] + (int) $inv['ps_grace_in'] * 86400
                && $now >= (int) $inv['in_created_in'] + 86400) {
                $plan = pp::Plan((int) $inv['in_acc_fk']);
                // only while the client is still the seller's, on an active plan
                if ($plan && (int) $plan['pl_active_in'] && (int) $plan['pl_seller_fk'] === (int) $inv['in_seller_fk'] && pp::SellerOf((int) $inv['in_acc_fk']) === (int) $inv['in_seller_fk'] && pp::SwitchOff($inv)) {
                    pp::Tell($inv, 'suspended');
                    $log[] = 'Payment Plans: account ' . (int) $inv['in_acc_fk'] . ' switched off (invoice ' . $inv['in_number_vc'] . ' unpaid)';
                }
                $zdbh->prepare("UPDATE x_pp_invoices SET in_reminded_in = 2 WHERE in_id_pk = :i")->execute(array(':i' => (int) $inv['in_id_pk']));
            }
        }
        // 4. refunds and chargebacks: each paid invoice looked at once a day for 180 days after it was
        // paid (the time a client has to dispute a payment), and while a chargeback is open
        // (at most a minute and 50 invoices a run: the rest on the next runs)
        $q = $zdbh->prepare("SELECT * FROM x_pp_invoices WHERE in_status_vc = 'paid' AND in_capture_vc IS NOT NULL AND in_checked_in < :c
            AND (in_paid_in >= :p OR in_dispute_vc = 'open')" . ($only ? " AND in_seller_fk = " . $only : '') . " ORDER BY in_checked_in LIMIT 50");
        $q->execute(array(':c' => $now - ($only ? 0 : 20 * 3600), ':p' => $now - 180 * 86400));
        $until = microtime(true) + 60;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            if (microtime(true) > $until) {
                break;
            }
            $r = pp_pay::Reversals($inv);
            // (what changed; a payment that could not be looked at is tried again tomorrow)
            if ($r !== '' && stripos($r, 'invoice ') !== false) {
                $log[] = 'Payment Plans: ' . $r;
            }
        }
        return $log;
    }
}
