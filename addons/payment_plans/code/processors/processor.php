<?php

/**
 * What a Payment Plans payment processor does (code/processors/<id>.php, listed in
 * pp_pay::Processors()). A processor only talks to its service: whether a payment counts is
 * decided by pp_pay::Apply from what Settle answers.
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-10
 */
interface pp_processor
{
    /**
     * The name shown to sellers and clients (PayPal).
     *
     * @return string
     */
    static function Name();

    /**
     * The keys a seller gives, per mode: field => label, help, pattern (for test mode and live:
     * array('sandbox' => ..., 'live' => ...)), secret (kept encrypted, never shown again).
     *
     * @return array<string, array>
     */
    static function Fields();

    /**
     * Where the seller gets its keys, in a few words.
     *
     * @return string
     */
    static function Help();

    /**
     * The addresses of the processor's payment pages (https://host), where clients are sent.
     *
     * @return string[]
     */
    static function Hosts();

    /**
     * The currencies it takes (of pp::Currencies()).
     *
     * @return string[]
     */
    static function Currencies();

    /**
     * Ask the service whether it accepts the keys.
     *
     * @param array $keys
     * @param string $mode live or sandbox
     * @return string '' when it does, else why not.
     */
    static function Check(array $keys, $mode);

    /**
     * Make the payment of an invoice (its amount and currency from the invoice).
     *
     * @param array $inv
     * @param array $keys
     * @param string $mode
     * @param string $brand The seller's name.
     * @param string $return Where to send the client back (the processor adds its reference).
     * @param string $cancel Where to send a client who cancels.
     * @param string $email The client's email address, or ''.
     * @return array{0:string, 1:string}|string Its reference and the page to pay on, or why not.
     */
    static function Create(array $inv, array $keys, $mode, $brand, $return, $cancel, $email);

    /**
     * Close a payment that is no longer wanted (a new one is made, or the invoice is voided), so it
     * can not take money any more.
     *
     * @param array $inv
     * @param array $keys
     * @param string $mode
     * @return bool True when it can not take money any more (closed, finished, or it never could
     *              without the panel finishing it); false when it may still take money.
     */
    static function Abandon(array $inv, array $keys, $mode);

    /**
     * The reference a client coming back from the payment page carries.
     *
     * @param array $get $_GET
     * @return string
     */
    static function ReturnRef(array $get);

    /**
     * Look at an invoice's payment (in_order_vc, and in_capture_vc when pending), finishing it when
     * the client approved it.
     *
     * @param array $inv
     * @param array $keys
     * @param string $mode
     * @return array|string state (paid, pending, open, failed), ref, payment, amount (in the
     *         currency's smallest unit as pp counts it), currency, invoice (the invoice ID the
     *         payment names), payer, why; or why it could not be looked at.
     */
    static function Settle(array $inv, array $keys, $mode);

    /**
     * What happened to a paid invoice's payment since (in_capture_vc): refunds and chargebacks. Only
     * read: nothing is changed at the processor.
     *
     * @param array $inv
     * @param array $keys
     * @param string $mode
     * @return array|string refund ('' none, 'partial', 'full'), refunded (in the currency's smallest unit
     *         as pp counts it, or null when the processor does not say), dispute ('' none, 'open',
     *         'won', 'lost'; null when the processor could not say: what is known stays); or why it
     *         could not be looked at.
     */
    static function Reversals(array $inv, array $keys, $mode);
}
