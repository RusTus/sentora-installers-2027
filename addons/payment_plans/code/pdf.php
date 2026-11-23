<?php

/**
 * Payment Plans: invoices and credit notes as PDF files, made here with nothing but PHP (no library, no
 * program run): A4 pages, the PDF's own Helvetica, text in Windows-1252 (letters it has not got become
 * the nearest it has, or a question mark).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Written by RusTus, 2026-11-23
 */
class pp_pdf
{
    /** Helvetica's widths (1/1000 of the size), characters 32 to 255 in Windows-1252. */
    const W_REG = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';
    const W_BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

    /** @var string[] Each page's drawing. */
    private $pages = array();

    /** @var float Where the next line goes (from the bottom, in points). */
    public $y = 0;

    /** @var array<int, array<int, int>> */
    private static $w = array();

    /** @var string What goes at the top of every page after the first (the document's name and number). */
    public $head = '';

    function __construct()
    {
        if (!self::$w) {
            self::$w = array(array_map('intval', explode(',', self::W_REG)), array_map('intval', explode(',', self::W_BOLD)));
        }
        $this->Page();
    }

    /**
     * Text in the PDF's own encoding (Windows-1252).
     *
     * @param string $s UTF-8
     * @return string
     */
    static function Enc($s)
    {
        $s = str_replace(array("\r", "\t"), array('', ' '), (string) $s);
        $out = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s) : false;
        if ($out === false) {
            // (iconv refused a letter it can not write: one character at a time, a question mark for that one)
            $out = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $ch) {
                $c = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT', $ch) : false;
                $out .= $c !== false && $c !== '' ? $c : (strlen($ch) === 1 && ord($ch) < 128 ? $ch : '?');
            }
        }
        // (control characters never reach the page)
        return preg_replace('/[\x00-\x1f\x7f]/', '', $out);
    }

    /**
     * How wide a text is (already in Windows-1252), in points.
     *
     * @return float
     */
    static function Width($s, $size, $bold = false)
    {
        $w = 0;
        $t = self::$w[$bold ? 1 : 0];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $o = ord($s[$i]) - 32;
            $w += $o >= 0 && isset($t[$o]) ? $t[$o] : 500;
        }
        return $w * $size / 1000;
    }

    /**
     * A new page (A4).
     *
     * @return void
     */
    function Page()
    {
        $this->pages[] = '';
        $this->y = 800;
        if ($this->head !== '' && count($this->pages) > 1) {
            $this->Text(50, $this->y, $this->head, 9, false, 0.4);
            $this->y -= 24;
        }
    }

    /**
     * Room for some more points on this page, or a new one.
     *
     * @return void
     */
    function Need($h)
    {
        if ($this->y - $h < 60) {
            $this->Page();
        }
    }

    /**
     * Text at a place (left at x); $grey 0 = black.
     *
     * @return void
     */
    function Text($x, $y, $s, $size = 10, $bold = false, $grey = 0.0)
    {
        $e = self::Enc($s);
        if ($e === '') {
            return;
        }
        $this->pages[count($this->pages) - 1] .= sprintf("BT %.3F g /F%d %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $grey, $bold ? 2 : 1, $size, $x, $y,
            strtr($e, array('\\' => '\\\\', '(' => '\\(', ')' => '\\)')));
    }

    /**
     * Text ending at x (amounts).
     *
     * @return void
     */
    function Right($x, $y, $s, $size = 10, $bold = false, $grey = 0.0)
    {
        $this->Text($x - self::Width(self::Enc($s), $size, $bold), $y, $s, $size, $bold, $grey);
    }

    /**
     * A thin line.
     *
     * @return void
     */
    function Line($x1, $y1, $x2, $y2, $grey = 0.75, $width = 0.6)
    {
        $this->pages[count($this->pages) - 1] .= sprintf("%.3F G %.2F w %.2F %.2F m %.2F %.2F l S\n", $grey, $width, $x1, $y1, $x2, $y2);
    }

    /**
     * A filled box (a band behind a heading).
     *
     * @return void
     */
    function Box($x, $y, $w, $h, $grey)
    {
        $this->pages[count($this->pages) - 1] .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f\n", $grey, $x, $y, $w, $h);
    }

    /**
     * A text cut into lines that fit a width (on words; a word longer than the width is cut).
     *
     * @return string[] UTF-8 lines
     */
    static function Wrap($s, $width, $size, $bold = false)
    {
        $out = array();
        foreach (explode("\n", str_replace("\r", '', (string) $s)) as $para) {
            $line = '';
            foreach (preg_split('/ +/', trim($para)) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if (self::Width(self::Enc($try), $size, $bold) <= $width) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                }
                while (self::Width(self::Enc($word), $size, $bold) > $width && mb_strlen($word) > 1) {
                    $n = mb_strlen($word);
                    while ($n > 1 && self::Width(self::Enc(mb_substr($word, 0, $n)), $size, $bold) > $width) {
                        $n--;
                    }
                    $out[] = mb_substr($word, 0, $n);
                    $word = mb_substr($word, $n);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Lines of text from the current place down (wrapped to a width).
     *
     * @return void
     */
    function Para($x, $s, $width, $size = 10, $bold = false, $grey = 0.0, $gap = 1.35)
    {
        foreach (self::Wrap($s, $width, $size, $bold) as $l) {
            $this->Need($size * $gap);
            $this->Text($x, $this->y, $l, $size, $bold, $grey);
            $this->y -= $size * $gap;
        }
    }

    /**
     * The file.
     *
     * @param string $title The document's title (its properties).
     * @return string
     */
    function Out($title)
    {
        $obj = array();
        $n = count($this->pages);
        // 1 catalog, 2 pages, 3 and 4 the fonts, 5 the info, then each page and its content
        $kids = array();
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (6 + 2 * $i) . ' 0 R';
        }
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $obj[5] = '<< /Title (' . strtr(self::Enc($title), array('\\' => '\\\\', '(' => '\\(', ')' => '\\)')) . ') /Producer (Sentora Payment Plans) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        foreach ($this->pages as $i => $content) {
            if ($n > 1) {
                $content .= sprintf("BT 0.5 g /F1 8.0 Tf %.2F 30.00 Td (%d / %d) Tj ET\n", 545 - self::Width(($i + 1) . ' / ' . $n, 8), $i + 1, $n);
            }
            $obj[6 + 2 * $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (7 + 2 * $i) . ' 0 R >>';
            $obj[7 + 2 * $i] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $at = array();
        foreach ($obj as $k => $o) {
            $at[$k] = strlen($pdf);
            $pdf .= $k . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
        foreach ($at as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        return $pdf . "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    // ----- the documents -----

    /**
     * An invoice as a PDF (what the panel shows: its seller, buyer, lines, tax, total, state).
     *
     * @param array $inv pp::Invoice()
     * @return string
     */
    static function Invoice(array $inv)
    {
        $cur = (string) $inv['in_currency_vc'];
        $lines = array();
        foreach (pp::Lines((int) $inv['in_id_pk']) as $o) {
            $lines[] = array((string) $o['li_text_vc'], '', (int) $o['li_net_in']);
        }
        $items = pp::Items($inv);
        if ((string) $inv['in_period_vc'] !== 'once') {
            $rest = $items;
            foreach ($lines as $l) {
                $rest -= $l[2];
            }
            array_unshift($lines, array('Hosting: ' . $inv['in_package_vc'] . ', ' . ((string) $inv['in_period_vc'] === 'year' ? 'yearly' : 'monthly'),
                date('j M Y', (int) $inv['in_from_in']) . ' - ' . date('j M Y', (int) $inv['in_to_in']), $rest));
        }
        $state = array('open' => 'Open', 'pending' => 'Payment being checked', 'paid' => 'Paid', 'void' => 'Void');
        $dates = array(array('Date', date('j M Y', (int) $inv['in_created_in'])), array('Due', date('j M Y', (int) $inv['in_due_in'])));
        if ((string) $inv['in_status_vc'] === 'paid') {
            $dates[] = array('Paid', date('j M Y', (int) $inv['in_paid_in']) . ((string) $inv['in_method_vc'] !== '' ? ' (' . $inv['in_method_vc'] . ')' : ''));
        }
        return self::Doc('Invoice', (string) $inv['in_number_vc'], $inv, $dates, isset($state[(string) $inv['in_status_vc']]) ? $state[(string) $inv['in_status_vc']] : '', $lines, $cur,
            (int) $inv['in_net_in'], (int) $inv['in_tax_in'], (int) $inv['in_total_in'], (int) ($inv['in_taxincl_in'] ?? 0) === 1, (string) ($inv['in_note_vc'] ?? ''));
    }

    /**
     * A credit note as a PDF (the money given back on an invoice, its tax in it as on the invoice).
     *
     * @param array $cn pp::Credit row
     * @param array $inv Its invoice.
     * @return string
     */
    static function CreditNote(array $cn, array $inv)
    {
        $dates = array(array('Date', date('j M Y', (int) $cn['cn_created_in'])), array('For invoice', (string) $inv['in_number_vc'] . ' of ' . date('j M Y', (int) $inv['in_created_in'])));
        $what = (string) $cn['cn_reason_vc'] !== '' ? (string) $cn['cn_reason_vc'] : 'Credit';
        $incl = (int) ($inv['in_taxincl_in'] ?? 0) === 1;
        $lines = array(array($what, 'On invoice ' . $inv['in_number_vc'], -((int) ($incl ? $cn['cn_total_in'] : $cn['cn_net_in']))));
        return self::Doc('Credit note', (string) $cn['cn_number_vc'], $inv, $dates, '', $lines, (string) $cn['cn_currency_vc'],
            -(int) $cn['cn_net_in'], -(int) $cn['cn_tax_in'], -(int) $cn['cn_total_in'], $incl, (string) ($inv['in_note_vc'] ?? ''), (int) $cn['cn_test_in'] === 1);
    }

    /**
     * The page both share.
     *
     * @param array<int, array{0:string, 1:string, 2:int}> $lines text, its small second line, amount
     * @return string
     */
    private static function Doc($kind, $number, array $inv, array $dates, $state, array $lines, $cur, $net, $tax, $total, $incl, $note, $test = null)
    {
        $p = new self();
        $p->head = $kind . ' ' . $number;
        $test = $test === null ? (int) $inv['in_test_in'] === 1 : $test;
        $L = 50;
        $R = 545;
        if ($test) {
            $p->Box($L, $p->y - 6, $R - $L, 20, 0.9);
            $p->Text($L + 8, $p->y, 'TEST: made in test mode. No real money.', 9, true);
            $p->y -= 34;
        }
        // the seller (left) and the document's name and number (right)
        $top = $p->y;
        $p->Text($L, $p->y, strtok((string) $inv['in_seller_tx'] . "\n", "\n"), 13, true);
        $p->y -= 17;
        $rest = explode("\n", (string) $inv['in_seller_tx']);
        array_shift($rest);
        $p->Para($L, implode("\n", $rest), 250, 9.5, false, 0.25);
        $sellerEnd = $p->y;
        $p->Right($R, $top, strtoupper($kind), 20, true);
        $p->Right($R, $top - 22, $number, 12, true, 0.25);
        if ($state !== '') {
            $p->Right($R, $top - 38, $state, 10, true, $state === 'Paid' ? 0.0 : 0.35);
        }
        $p->y = min($sellerEnd, $top - 50) - 18;
        // the buyer (left) and the dates (right)
        $mid = $p->y;
        $p->Text($L, $p->y, 'To', 9, true, 0.45);
        $p->y -= 14;
        $p->Para($L, (string) $inv['in_buyer_tx'], 260, 10);
        $buyerEnd = $p->y;
        $dy = $mid;
        foreach ($dates as $d) {
            $p->Text(340, $dy, $d[0], 9, true, 0.45);
            foreach (self::Wrap($d[1], 130, 10) as $i => $l) {
                $p->Right($R, $dy, $l, 10);
                $dy -= 14;
            }
            $dy -= 2;
        }
        $p->y = min($buyerEnd, $dy) - 20;
        // the lines
        $p->Box($L, $p->y - 6, $R - $L, 20, 0.94);
        $p->Text($L + 8, $p->y, 'Description', 9, true, 0.3);
        $p->Right($R - 8, $p->y, 'Amount (' . $cur . ')', 9, true, 0.3);
        $p->y -= 26;
        foreach ($lines as $l) {
            $wrapped = self::Wrap($l[0], 360, 10);
            $p->Need(16 * count($wrapped) + ($l[1] !== '' ? 13 : 0) + 8);
            $p->Right($R - 8, $p->y, pp::Plain($l[2], $cur), 10);
            foreach ($wrapped as $w) {
                $p->Text($L + 8, $p->y, $w, 10);
                $p->y -= 14;
            }
            if ($l[1] !== '') {
                $p->Text($L + 8, $p->y + 1, $l[1], 8.5, false, 0.45);
                $p->y -= 12;
            }
            $p->y -= 4;
            $p->Line($L, $p->y + 8, $R, $p->y + 8, 0.85);
            $p->y -= 6;
        }
        // the sums
        $label = trim((string) $inv['in_taxlabel_vc']) !== '' ? (string) $inv['in_taxlabel_vc'] : 'Tax';
        $rate = rtrim(rtrim(sprintf('%.2f', (int) $inv['in_taxrate_in'] / 100), '0'), '.') . '%';
        $sums = array();
        if ($incl) {
            $sums[] = array('Total', pp::Plain($total, $cur) . ' ' . $cur, true);
            if ((int) $inv['in_taxrate_in'] > 0) {
                $sums[] = array('Includes ' . $label . ' ' . $rate, pp::Plain($tax, $cur), false);
                $sums[] = array('Net', pp::Plain($net, $cur), false);
            }
        } else {
            if ((int) $inv['in_taxrate_in'] > 0 || count($lines) > 1) {
                $sums[] = array('Subtotal', pp::Plain($net, $cur), false);
            }
            if ((int) $inv['in_taxrate_in'] > 0) {
                $sums[] = array($label . ' ' . $rate, pp::Plain($tax, $cur), false);
            }
            $sums[] = array('Total', pp::Plain($total, $cur) . ' ' . $cur, true);
        }
        $p->Need(18 * count($sums) + 10);
        $p->y -= 4;
        foreach ($sums as $s) {
            $p->Text(330, $p->y, $s[0], $s[2] ? 12 : 10, $s[2]);
            $p->Right($R - 8, $p->y, $s[1], $s[2] ? 12 : 10, $s[2]);
            if ($s[2]) {
                $p->Line(330, $p->y + 16, $R, $p->y + 16, 0.3, 0.8);
            }
            $p->y -= $s[2] ? 22 : 17;
        }
        if ($note !== '') {
            $p->y -= 10;
            $p->Para($L, $note, $R - $L, 9.5, true);
        }
        return $p->Out($kind . ' ' . $number);
    }
}
