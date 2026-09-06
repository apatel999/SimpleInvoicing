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

function si_build_invoice_pdf($invoice, $items, $balance)
{
    $pageW = 595.28;
    $pageH = 841.89;
    $margin = 48.0;
    $contentW = $pageW - ($margin * 2);
    $pages = array();
    $y = 0;

    $newPage = function () use (&$pages, &$y, $pageH, $margin) {
        $pages[] = '';
        $y = $pageH - $margin;
    };

    $write = function ($chunk) use (&$pages) {
        $pages[count($pages) - 1] .= $chunk;
    };

    $text = function ($x, $pdfY, $size, $string, $bold = false) use ($write) {
        $font = $bold ? 'F2' : 'F1';
        $write(sprintf(
            "BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $pdfY,
            si_pdf_escape($string)
        ));
    };

    $line = function ($x1, $y1, $x2, $y2, $width = 0.6) use ($write) {
        $write(sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $width, $x1, $y1, $x2, $y2));
    };

    $rectFill = function ($x, $pdfY, $w, $h, $gray) use ($write) {
        $write(sprintf("%.2f g %.2f %.2f %.2f %.2f re f 0 g\n", $gray, $x, $pdfY, $w, $h));
    };

    $ensure = function ($need) use (&$y, $newPage, $margin) {
        if ($y - $need < $margin + 24) {
            $newPage();
        }
    };

    $company = setting('company_name', 'My Company');
    $blocks = company_contact_blocks();
    $companyLines = array_merge(array($company), $blocks['contact'], $blocks['address']);
    $customerLines = array($invoice['customer_name']);
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
        $customerLines[] = $invoice['customer_phone'];
    }
    if (!empty($invoice['tax_number'])) {
        $customerLines[] = 'Tax ID: ' . $invoice['tax_number'];
    }

    $newPage();

    $headerY = $y;
    $text($margin, $y - 4, 16, $company, true);
    $y -= 22;
    foreach (array_slice($companyLines, 1) as $row) {
        $text($margin, $y, 9, $row);
        $y -= 12;
    }
    $metaX = $margin + 320;
    $text($metaX, $headerY - 4, 18, 'INVOICE', true);
    $text($metaX, $headerY - 24, 10, 'Invoice No  ' . $invoice['invoice_number']);
    $text($metaX, $headerY - 38, 10, 'Invoice date  ' . format_date($invoice['issue_date']));
    $text($metaX, $headerY - 52, 10, 'Due date  ' . format_date($invoice['due_date']));
    $y = min($y, $headerY - 70);

    $y -= 10;
    $line($margin, $y, $pageW - $margin, $y, 0.8);
    $y -= 22;

    $text($margin, $y, 8, 'INVOICE TO', true);
    $text($margin + 320, $y, 8, 'BALANCE DUE', true);
    $y -= 16;
    $text($margin, $y, 12, $customerLines[0], true);
    $text($margin + 320, $y, 14, money($balance), true);
    $y -= 16;
    foreach (array_slice($customerLines, 1) as $row) {
        $text($margin, $y, 9, $row);
        $y -= 12;
    }
    $y -= 10;

    $colDesc = $margin;
    $colQty = $margin + 278;
    $colPrice = $margin + 348;
    $colAmt = $margin + 430;
    $headerH = 18;
    $ensure(40);
    $rectFill($margin, $y - 12, $contentW, $headerH, 0.94);
    $text($colDesc + 6, $y - 8, 8, 'DESCRIPTION', true);
    $text($colQty, $y - 8, 8, 'QTY', true);
    $text($colPrice, $y - 8, 8, 'PRICE', true);
    $text($colAmt, $y - 8, 8, 'AMOUNT', true);
    $y -= 26;

    foreach ($items as $item) {
        $descLines = si_pdf_wrap($item['description'], 9, 260);
        if (!$descLines) {
            $descLines = array('');
        }
        $rowH = max(16, count($descLines) * 12 + 6);
        $ensure($rowH + 8);
        $rowTop = $y;
        $text($colDesc + 6, $rowTop, 9, $descLines[0]);
        for ($i = 1; $i < count($descLines); $i++) {
            $text($colDesc + 6, $rowTop - ($i * 12), 9, $descLines[$i]);
        }
        $text($colQty, $rowTop, 9, (string) $item['quantity']);
        $text($colPrice, $rowTop, 9, money($item['unit_price']));
        $text($colAmt, $rowTop, 9, money($item['line_total']));
        $y = $rowTop - $rowH;
        $line($margin, $y + 4, $pageW - $margin, $y + 4, 0.3);
        $y -= 4;
    }

    $y -= 8;
    $totals = array(
        array('Subtotal', money($invoice['subtotal'])),
    );
    if ((float) $invoice['discount_amount'] > 0) {
        $totals[] = array('Discount', money($invoice['discount_amount']));
    }
    if ((float) $invoice['tax_amount'] > 0) {
        $totals[] = array('Tax (' . $invoice['tax_rate'] . '%)', money($invoice['tax_amount']));
    }
    $totals[] = array('Total', money($invoice['total']), true);
    if ((float) $invoice['amount_paid'] > 0) {
        $totals[] = array('Paid', money($invoice['amount_paid']));
        $totals[] = array('Balance', money($balance), true);
    }
    $ensure(count($totals) * 16 + 20);
    $labelX = $margin + 300;
    $valueX = $margin + 430;
    foreach ($totals as $row) {
        $bold = !empty($row[2]);
        if ($bold) {
            $rectFill($labelX - 8, $y - 6, 205, 18, 0.94);
        }
        $text($labelX, $y, 10, $row[0], $bold);
        $text($valueX, $y, 10, $row[1], $bold);
        $y -= 18;
    }

    $y -= 16;
    if (!empty($invoice['notes'])) {
        $noteLines = si_pdf_wrap($invoice['notes'], 9, $contentW);
        $ensure(28 + count($noteLines) * 12);
        $text($margin, $y, 8, 'NOTES', true);
        $y -= 14;
        foreach ($noteLines as $row) {
            $ensure(14);
            $text($margin, $y, 9, $row);
            $y -= 12;
        }
        $y -= 8;
    }
    if (!empty($invoice['terms'])) {
        $termLines = si_pdf_wrap($invoice['terms'], 9, $contentW);
        $ensure(28 + count($termLines) * 12);
        $text($margin, $y, 8, 'TERMS AND CONDITIONS', true);
        $y -= 14;
        foreach ($termLines as $row) {
            $ensure(14);
            $text($margin, $y, 9, $row);
            $y -= 12;
        }
        $y -= 8;
    }
    $ensure(20);
    $text($margin, $y, 10, 'Thank you for your business.', true);

    $font1 = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $font2 = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
    $objs = array();
    $objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $pageIds = array();
    $contentIds = array();
    $nextId = 5;
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
    foreach ($pages as $i => $content) {
        $stream = "0 g\n0.2 G\n" . $content;
        $objs[$pageIds[$i] - 1] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
            $pageW,
            $pageH,
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
