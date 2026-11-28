<?php
/**
 * Minimal dependency-free PDF writer + invoice renderer.
 *
 * Produces a valid PDF 1.4 document using the 14 standard core fonts
 * (Helvetica / Helvetica-Bold, WinAnsi encoding) and uncompressed
 * content streams — no external libraries, matching the project's
 * "no framework dependencies" rule. Text is laid out top-down on an
 * A4 page (595 x 842 pt) with automatic page breaks.
 *
 * invoice_pdf(array $inv)  renders invoice_data() output as a PDF
 * string (see includes/invoice_lib.php).
 */

/** Standard Helvetica AFM widths for ASCII 32..126 (per 1000 units). */
function inv_pdf_w_regular()
{
    return [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
            333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
            556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
}

/** Standard Helvetica-Bold AFM widths for ASCII 32..126 (per 1000 units). */
function inv_pdf_w_bold()
{
    return [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
            975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
            333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
            611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
}

class MiniPDF
{
    const PW = 595.28;
    const PH = 841.89;

    public $pages = [];          /* array of content-stream strings */
    private $cur = '';           /* current page content */
    private $y = 0;              /* top-down cursor (pt from top) */
    private $top;                /* usable top margin */
    private $bottom;             /* usable bottom margin */

    public function __construct($top = 36, $bottom = 40)
    {
        $this->top = $top;
        $this->bottom = $bottom;
        $this->y = $top;
    }

    public function y() { return $this->y; }
    public function setY($y) { $this->y = $y; }

    /** Open a new page (also used internally on overflow). */
    public function newPage()
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
        $this->y = $this->top;
    }

    /** Ensure at least $need pt remain on this page, else break. */
    public function need($need)
    {
        if ($this->cur === '') {
            return;
        }
        if ($this->y + $need > self::PH - $this->bottom) {
            $this->newPage();
        }
    }

    /** Convert a UTF-8 string to a PDF WinAnsi-safe escaped literal. */
    private static function esc($s)
    {
        $s = str_replace(['₹', '•', '×', '—', '–', '’', '“', '”', '&'], ['Rs. ', '*', 'x', '-', '-', "'", '"', '"', '&'], (string)$s);
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c == 194 && isset($s[$i + 1]) && ord($s[$i + 1]) == 160) { $out .= ' '; $i++; continue; } // nbsp
            if ($c < 32 || $c > 126) {
                if ($c >= 192 && $i + 1 < $len && (ord($s[$i + 1]) & 0xC0) == 0x80) { $i++; } // skip 2-byte UTF-8 lead
                $out .= '?';
                continue;
            }
            if ($s[$i] == '(' || $s[$i] == ')' || $s[$i] == '\\') {
                $out .= '\\' . $s[$i];
            } else {
                $out .= $s[$i];
            }
        }
        return $out;
    }

    /** Measured string width in pt. */
    public static function width($s, $size, $bold = false)
    {
        $widths = $bold ? inv_pdf_w_bold() : inv_pdf_w_regular();
        $s = str_replace(['₹'], ['Rs. '], (string)$s);
        $w = 0.0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c >= 32 && $c <= 126) {
                $w += $widths[$c - 32];
            } elseif ($c >= 192 && $i + 1 < $len && (ord($s[$i + 1]) & 0xC0) == 0x80) {
                $i++; // multi-byte UTF-8 lead byte: skip its continuation
            }
        }
        return $w * $size / 1000.0;
    }

    /** Draw text. $align: 'L', 'R' or 'C' relative to $x. $rgb = [r,g,b] 0..1. */
    public function text($x, $yTop, $size, $bold, $str, $align = 'L', $rgb = [0, 0, 0])
    {
        $w = self::width($str, $size, $bold);
        if ($align === 'R') { $x -= $w; }
        elseif ($align === 'C') { $x -= $w / 2; }
        $y = self::PH - $yTop;
        $f = $bold ? '/F2' : '/F1';
        $this->cur .= sprintf("BT %.2f %.2f %.2f rg %s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $f, $size, $x, $y, self::esc($str));
    }

    /** Horizontal line. */
    public function hline($x1, $x2, $yTop, $width = 0.7, $gray = 0.75)
    {
        $y = self::PH - $yTop;
        $this->cur .= sprintf("%.2f G %.2f w %.2f %.2f m %.2f %.2f l S\n", $gray, $width, $x1, $y, $x2, $y);
    }

    /** Filled rectangle (grayscale fill). */
    public function rect($x, $yTop, $w, $h, $fillGray)
    {
        $y = self::PH - $yTop - $h;
        $this->cur .= sprintf("%.3f g %.2f %.2f %.2f %.2f re f\n", $fillGray, $x, $y, $w, $h);
    }

    /** Assemble the PDF document string. */
    public function output()
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
            $this->cur = '';
        }
        $n = count($this->pages);
        if ($n === 0) { $this->pages[] = ''; $n = 1; }

        $objs = [];          /* serialized object bodies, index = obj no - 1 */
        /* 1: catalog, 2: pages, 3: F1, 4: F2, then page+content pairs */
        $kids = [];
        $firstPageObj = 5;
        for ($i = 0; $i < $n; $i++) {
            $kids[] = ($firstPageObj + $i * 2) . ' 0 R';
        }
        $objs[0] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[1] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
        $objs[2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        for ($i = 0; $i < $n; $i++) {
            $pageObj = $firstPageObj + $i * 2;
            $contObj = $pageObj + 1;
            $objs[$pageObj - 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . sprintf('%.2f %.2f', self::PW, self::PH) . "] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contObj 0 R >>";
            $stream = $this->pages[$i];
            $objs[$contObj - 1] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $count = count($objs);
        for ($i = 0; $i < $count; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 0; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }
}

/** Render the invoice (invoice_data() output) as a PDF string. */
function invoice_pdf(array $inv)
{
    $o = $inv['order'];
    $t = $inv['totals'];
    $c = $inv['company'];
    $green = [0.09, 0.263, 0.235];   /* #17433c */
    $gray = [0.42, 0.44, 0.46];

    $p = new MiniPDF(40, 46);
    $p->newPage();
    $M = 46;                          /* left/right margin */
    $W = MiniPDF::PW - $M * 2;        /* usable width */
    $R = MiniPDF::PW - $M;            /* right edge */

    /* ---------- header ---------- */
    $p->text($M, 52, 15.5, true, $c['name'], 'L', $green);
    $y = 70;
    foreach (inv_pdf_wrap(trim((string)$c['address']), 8.2, false, 330) as $line) {
        $p->text($M, $y, 8.2, false, $line, 'L', $gray);
        $y += 11;
    }
    $p->text($M, $y, 8.2, false, 'Phone: ' . $c['phone'] . '   Email: ' . $c['email'], 'L', $gray);
    $y += 8;

    $p->text($R, 52, 19, true, 'TAX INVOICE', 'R', $green);
    $p->text($R, 70, 9, false, 'Invoice No: ' . $inv['no'], 'R');
    $p->text($R, 81, 9, false, 'Invoice Date: ' . dmy($o['approved_at'] ?: $o['created_at']), 'R');
    $p->text($R, 92, 9, false, 'Order No: ' . $o['order_no'], 'R');

    $p->hline($M, $R, 106, 2.2, 0.09);
    $y = 120;

    /* ---------- billed to / ship to / payment ---------- */
    $ship = null;
    if ((string)$o['ship_name'] !== '' || (string)$o['ship_address'] !== '') {
        $ship = [
            'name' => $o['ship_name'] ?: $o['full_name'],
            'addr' => trim(($o['ship_address'] ?: $o['address']) . ', ' . ($o['ship_city'] ?: $o['city']) . ', ' . ($o['ship_state'] ?: $o['state']) . ' - ' . ($o['ship_pincode'] ?: $o['pincode']), ', -'),
            'mobile' => $o['ship_mobile'] ?: $o['mobile'],
        ];
    }
    $p->text($M, $y, 8, true, 'BILLED TO', 'L', $gray);
    $p->text($M + 205, $y, 8, true, 'PAYMENT & STATUS', 'L', $gray);
    $y += 14;
    $p->text($M, $y, 10, true, $o['full_name'] . ' (' . $o['username'] . ')');
    $p->text($M + 205, $y, 9.5, false, 'Payment Mode: ' . $inv['labels']['pm']);
    $y += 12;
    $addr = trim($o['address'] . ', ' . $o['city'] . ', ' . $o['state'] . ' - ' . $o['pincode'], ', -');
    if ($addr !== '') { $p->text($M, $y, 9, false, $addr, 'L', [0.25, 0.25, 0.25]); $y += 11; }
    $p->text($M + 205, $y, 9.5, false, 'Payment: ' . $inv['labels']['pay'] . '   Order: ' . $inv['labels']['status']);
    $p->text($M, $y, 9, false, 'Mobile: ' . $o['mobile'], 'L', [0.25, 0.25, 0.25]);
    $y += 11;
    if ($ship) {
        $p->text($M, $y, 8, true, 'SHIP TO', 'L', $gray);
        $y += 12;
        $p->text($M, $y, 9, false, $ship['name'] . ' - ' . $ship['addr'] . ' - Mobile: ' . $ship['mobile'], 'L', [0.25, 0.25, 0.25]);
        $y += 11;
    }
    $y += 9;

    /* ---------- items table ---------- */
    $cols = [
        '#'      => [$M + 4, 14, 'L'],
        'PRODUCT' => [$M + 24, 0, 'L'],
        'MRP'    => [$R - 236, 52, 'R'],
        'DP'     => [$R - 176, 52, 'R'],
        'BV'     => [$R - 116, 40, 'R'],
        'QTY'    => [$R - 68, 30, 'R'],
        'DP TOTAL' => [$R - 6, 62, 'R'],
    ];
    $rowH = 17;
    $headY = $y;
    $p->rect($M, $headY, $W, $rowH, 0.09);
    foreach ($cols as $label => [$cx, $cw, $al]) {
        $p->text($cx, $headY + 12, 7.6, true, $label, $al, [1, 1, 1]);
    }
    $y += $rowH;

    $i = 0;
    foreach ($inv['items'] as $it) {
        $i++;
        $name = (string)$it['product_name'];
        /* wrap product name into the available width (up to 2 lines) */
        $maxW = $cols['DP'][0] - $cols['PRODUCT'][0] - 10;
        $lines = [''];
        $words = preg_split('/\s+/', $name);
        foreach ($words as $wd) {
            $try = ($lines[count($lines) - 1] === '' ? '' : $lines[count($lines) - 1] . ' ') . $wd;
            if (MiniPDF::width($try, 9) <= $maxW || $lines[count($lines) - 1] === '') {
                $lines[count($lines) - 1] = $try;
            } else {
                $lines[] = $wd;
                if (count($lines) >= 2) { break; }
            }
        }
        $h = $rowH + (count($lines) - 1) * 10;
        $p->setY($y);
        $p->need($h);
        $y = $p->y();
        if ($i % 2 == 0) { $p->rect($M, $y, $W, $h, 0.965); }
        $ty = $y + 12;
        foreach ($lines as $ln) {
            $p->text($cols['#'][0], $ty, 9, false, (string)$i, 'L');
            $p->text($cols['PRODUCT'][0], $ty, 9, false, $ln);
            $ty += 10;
        }
        $baseY = $y + 12;
        $p->text($cols['MRP'][0], $baseY, 9, false, 'Rs. ' . number_format((float)$it['mrp'], 2), 'R');
        $p->text($cols['DP'][0], $baseY, 9, false, 'Rs. ' . number_format((float)$it['price'], 2), 'R');
        $p->text($cols['BV'][0], $baseY, 9, false, number_format((float)$it['bv'], 0), 'R');
        $p->text($cols['QTY'][0], $baseY, 9, false, (string)(int)$it['qty'], 'R');
        $p->text($cols['DP TOTAL'][0], $baseY, 9, false, 'Rs. ' . number_format((float)$it['price'] * (int)$it['qty'], 2), 'R');
        $y += $h;
        $p->hline($M, $R, $y, 0.4, 0.88);
    }

    /* totals row (kept together with the summary block) */
    $p->setY($y);
    $p->need($rowH + 26 + 110);
    $y = $p->y();
    $p->rect($M, $y, $W, $rowH, 0.93);
    $p->text($cols['PRODUCT'][0], $y + 12, 9, true, 'Total (' . $i . ' product' . ($i > 1 ? 's' : '') . ')');
    $p->text($cols['MRP'][0], $y + 12, 9, true, 'Rs. ' . number_format($t['mrp'], 2), 'R');
    $p->text($cols['BV'][0], $y + 12, 9, true, number_format($t['bv'], 0), 'R');
    $p->text($cols['QTY'][0], $y + 12, 9, true, (string)(int)$t['qty'], 'R');
    $p->text($cols['DP TOTAL'][0], $y + 12, 9, true, 'Rs. ' . number_format($t['dp'], 2), 'R');
    $y += $rowH + 16;

    /* ---------- summary block ---------- */
    $p->setY($y);
    $p->need(110);
    $y = $p->y();
    $sumW = 250;
    $sx = $R - $sumW;
    $rows = [
        ['Total MRP', 'Rs. ' . number_format($t['mrp'], 2), false],
        ['Distributor Savings (MRP - DP)', '- Rs. ' . number_format($t['savings'], 2), false],
        ['Total BV Earned', number_format($t['bv'], 0) . ' BV', false],
    ];
    foreach ($rows as [$lbl, $val, $gr]) {
        $p->hline($sx, $R, $y + 13, 0.4, 0.9);
        $p->text($sx + 4, $y + 10, 9.3, false, $lbl, 'L', [0.35, 0.37, 0.38]);
        $p->text($R - 4, $y + 10, 9.3, false, $val, 'R');
        $y += 15;
    }
    $p->rect($sx, $y, $sumW, 22, 0.09);
    $p->text($sx + 6, $y + 15, 10.5, true, 'Total Payable (DP)', 'L', [1, 1, 1]);
    $p->text($R - 6, $y + 15, 11.5, true, 'Rs. ' . number_format($t['dp'], 2), 'R', [1, 1, 1]);

    /* amount in words (left of the summary block) */
    $wy = $y - 45;
    $p->text($M, $wy, 8, true, 'AMOUNT IN WORDS (TOTAL DP PAYABLE)', 'L', $gray);
    foreach (inv_pdf_wrap(invoice_words($t['dp']), 9, false, $sx - $M - 16) as $k => $line) {
        $p->text($M, $wy + 13 + $k * 12, 9, false, $line, 'L', [0.2, 0.2, 0.2]);
    }

    /* ---------- footer (last page) ---------- */
    $fy = MiniPDF::PH - 34;
    $p->hline($M, $R, $fy - 12, 0.5, 0.85);
    $p->text($M, $fy, 7.6, false,
        'Prices are inclusive of applicable taxes. DP is the net payable amount; BV is the plan value credited on approval.',
        'L', $gray);
    $p->text($M, $fy + 10, 7.6, false,
        'This is a computer-generated invoice and does not require a physical signature.',
        'L', $gray);
    $p->text($R, $fy + 10, 7.6, false, $c['name'], 'R', $gray);

    return $p->output();
}

/** Greedy word-wrap for PDF text: returns lines that fit $maxW pt. */
function inv_pdf_wrap($text, $size, $bold, $maxW)
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/', trim((string)$text)) as $wd) {
        if ($wd === '') { continue; }
        $try = $line === '' ? $wd : $line . ' ' . $wd;
        if (MiniPDF::width($try, $size, $bold) <= $maxW || $line === '') {
            $line = $try;
        } else {
            $lines[] = $line;
            $line = $wd;
        }
    }
    if ($line !== '') { $lines[] = $line; }
    return $lines ? $lines : [''];
}
