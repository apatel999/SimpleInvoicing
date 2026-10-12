<?php

function invoice_pdf_filename($invoice)
{
    $name = isset($invoice['invoice_number']) ? (string) $invoice['invoice_number'] : 'invoice';
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim($name, '-.');
    if ($name === '') {
        $name = 'invoice';
    }
    return $name . '.pdf';
}

function output_invoice_pdf($invoice, $items, $balance)
{
    $filename = invoice_pdf_filename($invoice);
    $pdf = si_build_invoice_pdf($invoice, $items, $balance);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    exit;
}

function si_pdf_latin($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    if (function_exists('iconv')) {
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($out !== false) {
            return $out;
        }
    }
    return utf8_decode($text);
}

function si_pdf_escape($text)
{
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), si_pdf_latin($text));
}

function si_pdf_wrap($text, $fontSize, $maxWidth)
{
    $text = trim(str_replace(array("\r\n", "\r"), "\n", (string) $text));
    if ($text === '') {
        return array();
    }
    $avg = $fontSize * 0.5;
    $out = array();
    foreach (explode("\n", $text) as $para) {
        $words = preg_split('/\s+/', trim($para));
        if ($words === array('')) {
            $out[] = '';
            continue;
        }
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($try) * $avg > $maxWidth && $line !== '') {
                $out[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function si_pdf_text_width($text, $size, $bold = false)
{
    return strlen(si_pdf_latin($text)) * $size * ($bold ? 0.55 : 0.5);
}

function si_pdf_paeth($a, $b, $c)
{
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);
    if ($pa <= $pb && $pa <= $pc) {
        return $a;
    }
    if ($pb <= $pc) {
        return $b;
    }
    return $c;
}

function si_pdf_png_recon($filter, $scan, $prior, $bpp)
{
    $len = strlen($scan);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $x = ord($scan[$i]);
        $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
        $b = $prior !== '' ? ord($prior[$i]) : 0;
        $c = ($i >= $bpp && $prior !== '') ? ord($prior[$i - $bpp]) : 0;
        if ($filter === 1) {
            $x = ($x + $a) & 255;
        } elseif ($filter === 2) {
            $x = ($x + $b) & 255;
        } elseif ($filter === 3) {
            $x = ($x + (int) floor(($a + $b) / 2)) & 255;
        } elseif ($filter === 4) {
            $x = ($x + si_pdf_paeth($a, $b, $c)) & 255;
        }
        $out .= chr($x);
    }
    return $out;
}

function si_pdf_png_to_rgb($bin)
{
    if (strlen($bin) < 16 || substr($bin, 0, 8) !== "\x89PNG\x0d\x0a\x1a\x0a") {
        return null;
    }
    $pos = 8;
    $len = strlen($bin);
    $width = 0;
    $height = 0;
    $bit = 0;
    $color = 0;
    $interlace = 0;
    $idat = '';
    $plte = '';
    $trns = '';
    while ($pos + 8 <= $len) {
        $n = unpack('N', substr($bin, $pos, 4));
        $n = $n[1];
        $type = substr($bin, $pos + 4, 4);
        $data = substr($bin, $pos + 8, $n);
        $pos += 12 + $n;
        if ($type === 'IHDR' && strlen($data) >= 13) {
            $ihdr = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccomp/Cfilt/Cinter', $data);
            $width = (int) $ihdr['width'];
            $height = (int) $ihdr['height'];
            $bit = (int) $ihdr['bit'];
            $color = (int) $ihdr['color'];
            $interlace = (int) $ihdr['inter'];
        } elseif ($type === 'PLTE') {
            $plte = $data;
        } elseif ($type === 'tRNS') {
            $trns = $data;
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
    }
    if ($width < 1 || $height < 1 || $bit !== 8 || $interlace !== 0 || $idat === '') {
        return null;
    }
    $raw = @gzuncompress($idat);
    if ($raw === false) {
        return null;
    }
    $channels = array(0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4);
    if (!isset($channels[$color])) {
        return null;
    }
    $cpp = $channels[$color];
    $stride = $width * $cpp;
    if (strlen($raw) < $height * ($stride + 1)) {
        return null;
    }
    $rgb = '';
    $prior = str_repeat("\x00", $stride);
    $offset = 0;
    for ($y = 0; $y < $height; $y++) {
        $filter = ord($raw[$offset]);
        $scan = substr($raw, $offset + 1, $stride);
        $offset += $stride + 1;
        $row = si_pdf_png_recon($filter, $scan, $prior, $cpp);
        $prior = $row;
        for ($x = 0; $x < $width; $x++) {
            $i = $x * $cpp;
            if ($color === 2) {
                $rgb .= substr($row, $i, 3);
            } elseif ($color === 6) {
                $r = ord($row[$i]);
                $g = ord($row[$i + 1]);
                $b = ord($row[$i + 2]);
                $a = ord($row[$i + 3]);
                $rgb .= chr((int) round($r * $a / 255 + 255 * (255 - $a) / 255));
                $rgb .= chr((int) round($g * $a / 255 + 255 * (255 - $a) / 255));
                $rgb .= chr((int) round($b * $a / 255 + 255 * (255 - $a) / 255));
            } elseif ($color === 0) {
                $rgb .= str_repeat($row[$i], 3);
            } elseif ($color === 4) {
                $v = ord($row[$i]);
                $a = ord($row[$i + 1]);
                $v = (int) round($v * $a / 255 + 255 * (255 - $a) / 255);
                $rgb .= chr($v) . chr($v) . chr($v);
            } elseif ($color === 3) {
                $idx = ord($row[$i]);
                $pr = $pg = $pb = 255;
                if (strlen($plte) >= ($idx + 1) * 3) {
                    $pr = ord($plte[$idx * 3]);
                    $pg = ord($plte[$idx * 3 + 1]);
                    $pb = ord($plte[$idx * 3 + 2]);
                }
                $a = 255;
                if ($trns !== '' && isset($trns[$idx])) {
                    $a = ord($trns[$idx]);
                }
                $rgb .= chr((int) round($pr * $a / 255 + 255 * (255 - $a) / 255));
                $rgb .= chr((int) round($pg * $a / 255 + 255 * (255 - $a) / 255));
                $rgb .= chr((int) round($pb * $a / 255 + 255 * (255 - $a) / 255));
            }
        }
    }
    $compressed = gzcompress($rgb, 9);
    if ($compressed === false) {
        return null;
    }
    return array(
        'width' => $width,
        'height' => $height,
        'data' => $compressed,
        'filter' => 'FlateDecode',
    );
}

function si_pdf_load_logo()
{
    $file = company_logo_file();
    if ($file === '') {
        return null;
    }
    $bin = @file_get_contents($file);
    if ($bin === false || $bin === '') {
        return null;
    }
    $info = @getimagesize($file);
    $type = $info ? (int) $info[2] : 0;
    if ($type === IMAGETYPE_JPEG) {
        return array(
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'data' => $bin,
            'filter' => 'DCTDecode',
        );
    }
    if ($type === IMAGETYPE_PNG || substr($bin, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a") {
        return si_pdf_png_to_rgb($bin);
    }
    return null;
}

function si_build_invoice_pdf($invoice, $items, $balance)
{
    $pageW = 595.28;
    $pageH = 841.89;
    $margin = 48.0;
    $contentW = $pageW - ($margin * 2);
    $right = $pageW - $margin;
    $pages = array();
    $y = 0;
    $logo = si_pdf_load_logo();

    $newPage = function () use (&$pages, &$y, $pageH, $margin) {
        $pages[] = '';
        $y = $pageH - $margin;
    };

    $write = function ($chunk) use (&$pages) {
        $pages[count($pages) - 1] .= $chunk;
    };

    $text = function ($x, $pdfY, $size, $string, $bold = false, $gray = 0) use ($write) {
        if ($gray > 0) {
            $write(sprintf("%.2f g\n", $gray));
        }
        $write(sprintf(
            "BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $pdfY,
            si_pdf_escape($string)
        ));
        if ($gray > 0) {
            $write("0 g\n");
        }
    };

    $textRight = function ($rightX, $pdfY, $size, $string, $bold = false, $gray = 0) use ($text) {
        $text($rightX - si_pdf_text_width($string, $size, $bold), $pdfY, $size, $string, $bold, $gray);
    };

    $line = function ($x1, $y1, $x2, $y2, $width = 0.6) use ($write) {
        $write(sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $width, $x1, $y1, $x2, $y2));
    };

    $rectFill = function ($x, $pdfY, $w, $h, $gray) use ($write) {
        $write(sprintf("%.2f g %.2f %.2f %.2f %.2f re f 0 g\n", $gray, $x, $pdfY, $w, $h));
    };

    $ensure = function ($need) use (&$y, $newPage, $margin) {
        if ($y - $need < $margin + 36) {
            $newPage();
            return true;
        }
        return false;
    };

    $company = setting('company_name', 'My Company');
    $blocks = company_contact_blocks();
    $footer = array_merge($blocks['contact'], $blocks['address']);
    $customerLines = array();
    if (!empty($invoice['customer_company'])) {
        $customerLines[] = $invoice['customer_company'];
    }
    $addr = format_address($invoice);
    if ($addr !== '') {
        $customerLines = array_merge($customerLines, explode("\n", $addr));
    }
    if (!empty($invoice['customer_email'])) {
        $customerLines[] = $invoice['customer_email'];
    }
    if (!empty($invoice['customer_phone'])) {
        $customerLines[] = format_phone($invoice['customer_phone']);
    }

    $colQty = $margin + 318;
    $colPrice = $margin + 400;
    $colAmt = $right - 8;
    $descWidth = 250;

    $baseline = function ($boxBottom, $boxH, $fontSize) {
        return $boxBottom + ($boxH / 2) - ($fontSize * 0.35);
    };

    $drawTableHeader = function () use (&$y, $rectFill, $text, $textRight, $baseline, $margin, $contentW, $colQty, $colPrice, $colAmt) {
        $barH = 26;
        $boxBottom = $y - $barH;
        $rectFill($margin, $boxBottom, $contentW, $barH, 0.945);
        $base = $baseline($boxBottom, $barH, 8);
        $text($margin + 8, $base, 8, 'DESCRIPTION', true);
        $textRight($colQty, $base, 8, 'QTY', true);
        $textRight($colPrice, $base, 8, 'PRICE', true);
        $textRight($colAmt, $base, 8, 'AMOUNT', true);
        $y = $boxBottom;
    };

    $newPage();

    $headerTop = $y;
    $leftY = $y;
    if ($logo) {
        $maxW = 128.0;
        $maxH = 42.0;
        $scale = min($maxW / $logo['width'], $maxH / $logo['height'], 1);
        $lw = $logo['width'] * $scale;
        $lh = $logo['height'] * $scale;
        $write(sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n", $lw, $lh, $margin, $leftY - $lh));
        $leftY -= $lh + 10;
    }
    $text($margin, $leftY, 12, $company, true);
    $leftY -= 18;

    $textRight($right, $headerTop, 16, 'INVOICE', true);
    $meta = array(
        array('Invoice No', $invoice['invoice_number']),
        array('Invoice date', format_date($invoice['issue_date'])),
        array('Due date', format_date($invoice['due_date'])),
    );
    $metaY = $headerTop - 26;
    foreach ($meta as $row) {
        $text($right - 200, $metaY, 9, $row[0], false, 0.45);
        $textRight($right, $metaY, 10, $row[1], true);
        $metaY -= 14;
    }
    $y = min($leftY, $metaY) - 18;

    $text($margin, $y, 8, 'INVOICE TO', true, 0.45);
    $textRight($right, $y, 8, 'BALANCE DUE', true, 0.45);
    $y -= 16;
    $text($margin, $y, 12, $invoice['customer_name'], true);
    $textRight($right, $y, 16, money($balance), true);
    $y -= 16;
    foreach ($customerLines as $row) {
        $text($margin, $y, 9, $row, false, 0.35);
        $y -= 12;
    }
    $y -= 14;

    $ensure(40);
    $drawTableHeader();

    foreach ($items as $item) {
        $descLines = si_pdf_wrap($item['description'], 10, $descWidth);
        if (!$descLines) {
            $descLines = array('');
        }
        $fontSize = 10;
        $lineH = 13;
        $pad = 9;
        $n = count($descLines);
        $blockH = $fontSize + (($n - 1) * $lineH);
        $rowH = $pad + $blockH + $pad;
        if ($ensure($rowH + 8)) {
            $drawTableHeader();
        }
        $boxBottom = $y - $rowH;
        $firstBase = $boxBottom + ($rowH / 2) + (($n - 1) * $lineH / 2) - ($fontSize * 0.35);
        foreach ($descLines as $i => $descLine) {
            $text($margin + 8, $firstBase - ($i * $lineH), $fontSize, $descLine);
        }
        $numBase = $baseline($boxBottom, $rowH, $fontSize);
        $textRight($colQty, $numBase, $fontSize, (string) $item['quantity']);
        $textRight($colPrice, $numBase, $fontSize, money($item['unit_price']));
        $textRight($colAmt, $numBase, $fontSize, money($item['line_total']));
        $y = $boxBottom;
        $line($margin, $y, $right, $y, 0.4);
    }

    $y -= 12;
    $totals = array(
        array('Subtotal', money($invoice['subtotal']), false),
    );
    if ((float) $invoice['discount_amount'] > 0) {
        $totals[] = array('Discount', money($invoice['discount_amount']), false);
    }
    if ((float) $invoice['tax_amount'] > 0) {
        $totals[] = array('Tax (' . $invoice['tax_rate'] . '%)', money($invoice['tax_amount']), false);
    }
    $totals[] = array('Total', money($invoice['total']), true);
    if ((float) $invoice['amount_paid'] > 0) {
        $totals[] = array('Paid', money($invoice['amount_paid']), false);
        $totals[] = array('Balance', money($balance), true);
    }
    $ensure(count($totals) * 18 + 16);
    $totalsLeft = $right - 200;
    foreach ($totals as $row) {
        if ($row[2]) {
            $rectFill($totalsLeft - 8, $y - 6, 208, 20, 0.945);
        }
        $text($totalsLeft, $y, 10, $row[0], $row[2]);
        $textRight($right - 8, $y, 10, $row[1], $row[2]);
        $y -= 18;
    }

    $y -= 18;
    if (!empty($invoice['notes'])) {
        $noteLines = si_pdf_wrap($invoice['notes'], 9, $contentW);
        $ensure(28 + count($noteLines) * 12);
        $text($margin, $y, 8, 'NOTES', true, 0.45);
        $y -= 14;
        foreach ($noteLines as $row) {
            $ensure(14);
            $text($margin, $y, 9, $row);
            $y -= 12;
        }
        $y -= 10;
    }
    if (!empty($invoice['terms'])) {
        $termLines = si_pdf_wrap($invoice['terms'], 9, $contentW);
        $ensure(28 + count($termLines) * 12);
        $text($margin, $y, 8, 'TERMS AND CONDITIONS', true, 0.45);
        $y -= 14;
        foreach ($termLines as $row) {
            $ensure(14);
            $text($margin, $y, 9, $row, false, 0.35);
            $y -= 12;
        }
        $y -= 10;
    }
    $ensure(20);
    $text($margin, $y, 10, 'Thank you for your business.', true);

    if ($footer) {
        $y -= 28;
        $ensure(18);
        $line($margin, $y + 10, $right, $y + 10, 0.4);
        $x = $margin;
        foreach ($footer as $row) {
            $chunk = $row;
            $w = si_pdf_text_width($chunk, 8) + 16;
            if ($x + $w > $right && $x > $margin) {
                $y -= 12;
                $ensure(14);
                $x = $margin;
            }
            $text($x, $y, 8, $chunk, false, 0.45);
            $x += $w;
        }
    }

    $font1 = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $font2 = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objs = array();
    $objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $pageIds = array();
    $contentIds = array();
    $nextId = 5;
    $imageId = 0;
    if ($logo) {
        $imageId = $nextId;
        $nextId++;
    }
    foreach ($pages as $content) {
        $pageIds[] = $nextId;
        $contentIds[] = $nextId + 1;
        $nextId += 2;
    }
    $kids = '';
    foreach ($pageIds as $id) {
        $kids .= $id . ' 0 R ';
    }
    $objs[] = '<< /Type /Pages /Kids [' . trim($kids) . '] /Count ' . count($pageIds) . ' >>';
    $objs[] = $font1;
    $objs[] = $font2;
    if ($logo) {
        $filter = !empty($logo['filter']) ? $logo['filter'] : 'DCTDecode';
        $objs[$imageId - 1] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $logo['width']
            . ' /Height ' . (int) $logo['height']
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /' . $filter
            . ' /Length ' . strlen($logo['data']) . " >>\nstream\n"
            . $logo['data']
            . "\nendstream";
    }
    $xobject = $imageId ? ' /XObject << /Im1 ' . $imageId . ' 0 R >>' : '';
    foreach ($pages as $i => $content) {
        $stream = "0 g\n0.45 G\n" . $content;
        $objs[$pageIds[$i] - 1] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',
            $pageW,
            $pageH,
            $xobject,
            $contentIds[$i]
        );
        $objs[$contentIds[$i] - 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }

    $out = "%PDF-1.4\n";
    $offsets = array(0);
    foreach ($objs as $i => $body) {
        $offsets[$i + 1] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($out);
    $out .= 'xref' . "\n" . '0 ' . (count($objs) + 1) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objs); $i++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $out .= 'trailer << /Size ' . (count($objs) + 1) . ' /Root 1 0 R >>' . "\n";
    $out .= 'startxref' . "\n" . $xref . "\n%%EOF";
    return $out;
}
