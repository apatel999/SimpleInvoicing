<?php

function calculate_invoice_totals($items, $discountType, $discountValue, $taxRate)
{
    $normalized = array();
    $subtotal = 0.0;
    $order = 0;

    foreach ($items as $item) {
        $description = trim(isset($item['description']) ? $item['description'] : '');
        $quantity = round((float) (isset($item['quantity']) ? $item['quantity'] : 0), 2);
        $unitPrice = round((float) (isset($item['unit_price']) ? $item['unit_price'] : 0), 2);
        if ($description === '' && $quantity == 0.0 && $unitPrice == 0.0) {
            continue;
        }
        if ($description === '') {
            continue;
        }
        $lineTotal = round($quantity * $unitPrice, 2);
        $subtotal += $lineTotal;
        $normalized[] = array(
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => round((float) $taxRate, 3),
            'line_total' => $lineTotal,
            'sort_order' => $order,
        );
        $order++;
    }

    $subtotal = round($subtotal, 2);
    $discountValue = round((float) $discountValue, 2);
    if ($discountType === 'percent') {
        $discountAmount = round($subtotal * ($discountValue / 100), 2);
    } else {
        $discountType = 'fixed';
        $discountAmount = $discountValue;
    }
    if ($discountAmount > $subtotal) {
        $discountAmount = $subtotal;
    }

    $taxRate = round((float) $taxRate, 3);
    $taxAmount = round(($subtotal - $discountAmount) * ($taxRate / 100), 2);
    $total = round($subtotal - $discountAmount + $taxAmount, 2);

    return array(
        'items' => $normalized,
        'subtotal' => $subtotal,
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total' => $total,
    );
}

function next_invoice_number()
{
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $tid = tenant_id();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ? FOR UPDATE');
        $stmt->execute(array($tid, 'next_invoice_number'));
        $row = $stmt->fetch();
        $next = $row ? max(1, (int) $row['setting_value']) : 1;
        $prefix = setting('invoice_prefix', 'INV-');
        $padding = max(1, (int) setting('invoice_number_padding', '4'));
        $number = $prefix . str_pad((string) $next, $padding, '0', STR_PAD_LEFT);

        if ($row) {
            $pdo->prepare('UPDATE settings SET setting_value = ? WHERE tenant_id = ? AND setting_key = ?')
                ->execute(array((string) ($next + 1), $tid, 'next_invoice_number'));
        } else {
            $pdo->prepare('INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)')
                ->execute(array($tid, 'next_invoice_number', (string) ($next + 1)));
        }
        if ($ownTransaction) {
            $pdo->commit();
        }
        settings_cache(true);
        return $number;
    } catch (Exception $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function peek_invoice_number()
{
    $next = max(1, (int) setting('next_invoice_number', '1'));
    $prefix = setting('invoice_prefix', 'INV-');
    $padding = max(1, (int) setting('invoice_number_padding', '4'));
    return $prefix . str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
}

function invoice_balance($invoice)
{
    return round((float) $invoice['total'] - (float) $invoice['amount_paid'], 2);
}

function ensure_invoice_viewed_at_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $col = db()->query("SHOW COLUMNS FROM invoices LIKE 'viewed_at'")->fetch();
    if (!$col) {
        db()->exec('ALTER TABLE invoices ADD COLUMN viewed_at DATETIME NULL DEFAULT NULL AFTER sent_at');
    }
    db()->exec("UPDATE invoices SET viewed_at = COALESCE(viewed_at, updated_at, NOW()), status = IF(due_date < CURDATE() AND amount_paid < total, 'overdue', 'sent') WHERE status = 'viewed'");
}

function mark_invoice_opened($invoice)
{
    if (!$invoice || empty($invoice['id'])) {
        return;
    }
    ensure_invoice_viewed_at_column();
    $sql = 'UPDATE invoices SET viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ?';
    $params = array((int) $invoice['id']);
    if (!empty($invoice['tenant_id'])) {
        $sql .= ' AND tenant_id = ?';
        $params[] = (int) $invoice['tenant_id'];
    }
    db()->prepare($sql)->execute($params);
}

function cancel_invoice($invoice)
{
    if (!$invoice) {
        return 'Invoice not found.';
    }
    if ($invoice['status'] === 'cancelled') {
        return 'Invoice is already cancelled.';
    }
    if ($invoice['status'] === 'paid') {
        return 'Paid invoices cannot be cancelled.';
    }
    $sql = "UPDATE invoices SET status = 'cancelled' WHERE id = ?";
    $params = array((int) $invoice['id']);
    if (function_exists('tenant_id') && tenant_id() > 0) {
        $sql .= ' AND tenant_id = ?';
        $params[] = tenant_id();
    }
    db()->prepare($sql)->execute($params);
    return true;
}

function sync_invoice_payment_status($invoiceId)
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, total, amount_paid, status, sent_at FROM invoices WHERE id = ?' . (tenant_id() > 0 ? ' AND tenant_id = ?' : ''));
    $stmt->execute(tenant_id() > 0 ? array($invoiceId, tenant_id()) : array($invoiceId));
    $invoice = $stmt->fetch();
    if (!$invoice || $invoice['status'] === 'cancelled') {
        return;
    }

    $paid = (float) $invoice['amount_paid'];
    $total = (float) $invoice['total'];
    $status = $invoice['status'];
    $paidAt = null;

    if ($status === 'draft' && $paid <= 0) {
        return;
    }

    if ($total > 0 && $paid >= $total) {
        $status = 'paid';
        $paidAt = date('Y-m-d H:i:s');
    } elseif ($paid > 0) {
        $status = 'partial';
    } elseif ($status === 'paid' || $status === 'partial') {
        $status = $invoice['sent_at'] === null ? 'draft' : 'sent';
    }

    if ($status !== 'paid' && $status !== 'cancelled' && $status !== 'draft') {
        $due = $pdo->prepare('SELECT due_date FROM invoices WHERE id = ?');
        $due->execute(array($invoiceId));
        $dueDate = $due->fetchColumn();
        if ($dueDate && $dueDate < date('Y-m-d') && $paid < $total) {
            $status = 'overdue';
        }
    }

    $pdo->prepare('UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?')
        ->execute(array($status, $paidAt, $invoiceId));
}

function mark_overdue_invoices()
{
    $tid = tenant_id();
    if ($tid > 0) {
        db()->prepare("
            UPDATE invoices
            SET status = 'overdue'
            WHERE tenant_id = ?
              AND due_date < CURDATE()
              AND status IN ('sent', 'partial')
              AND amount_paid < total
        ")->execute(array($tid));
    }
}

function refresh_invoice_paid_total($invoiceId)
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?');
    $stmt->execute(array($invoiceId));
    $sum = round((float) $stmt->fetchColumn(), 2);
    db()->prepare('UPDATE invoices SET amount_paid = ? WHERE id = ?')->execute(array($sum, $invoiceId));
    sync_invoice_payment_status($invoiceId);
}

function fetch_invoice($id)
{
    $sql = 'SELECT invoices.*, customers.name AS customer_name, customers.company AS customer_company,
                   customers.email AS customer_email, customers.phone AS customer_phone,
                   customers.address_line1, customers.address_line2, customers.city, customers.state,
                   customers.postal_code, customers.country, customers.tax_number
            FROM invoices
            INNER JOIN customers ON customers.id = invoices.customer_id
            WHERE invoices.id = ?';
    $params = array($id);
    if (tenant_id() > 0) {
        $sql .= ' AND invoices.tenant_id = ?';
        $params[] = tenant_id();
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function fetch_invoice_items($invoiceId)
{
    $stmt = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute(array($invoiceId));
    return $stmt->fetchAll();
}

function invoice_template_vars($invoice, $paymentAmount = '')
{
    $balance = invoice_balance($invoice);
    $vars = array(
        'company_name' => setting('company_name', 'My Company'),
        'customer_name' => $invoice['customer_name'],
        'invoice_number' => $invoice['invoice_number'],
        'invoice_total' => money($invoice['total']),
        'due_date' => format_date($invoice['due_date']),
        'issue_date' => format_date($invoice['issue_date']),
        'payment_amount' => $paymentAmount === '' ? '' : money($paymentAmount),
        'balance_due' => money($balance),
        'invoice_link' => invoice_public_url($invoice['id']),
    );
    foreach ($vars as $key => $value) {
        $vars[$key] = e($value);
    }
    return $vars;
}

function linkify_plain_url($html, $url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return $html;
    }
    $safe = e($url);
    if ($safe === '' || strpos($html, $safe) === false) {
        return $html;
    }
    if (preg_match('/href\s*=\s*["\']' . preg_quote($safe, '/') . '/i', $html)) {
        return $html;
    }
    $link = '<a href="' . $safe . '" style="color:#5851ea;text-decoration:underline;word-break:break-all">' . $safe . '</a>';
    return str_replace($safe, $link, $html);
}

function invoice_email_body($templateBody, $vars, $invoice, $items = null)
{
    $intro = apply_template($templateBody, $vars);
    $intro = linkify_plain_url($intro, invoice_public_url($invoice['id']));
    $invoiceHtml = render_invoice_email_html($invoice, $items);
    if (strpos($templateBody, '{{invoice_html}}') !== false) {
        $intro = str_replace('{{invoice_html}}', $invoiceHtml, $intro);
        $invoiceHtml = '';
    }
    $font = invoice_ui_font();
    $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f6f7fb">'
        . '<tr><td align="center" style="padding:24px 10px;font-family:' . $font . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
        . ' style="border-collapse:collapse;width:100%;max-width:700px;text-align:left;'
        . 'font-family:' . $font . ';color:#111827;font-size:15px;line-height:1.5">'
        . '<tr><td style="font-family:' . $font . '">'
        . render_email_brand_header()
        . $intro
        . $invoiceHtml
        . '</td></tr></table>'
        . '</td></tr></table>';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="' . e(invoice_ui_font_href()) . '" rel="stylesheet">'
        . '<style type="text/css">body,table,td,th,div,p,a,span{font-family:' . $font . ';}</style>'
        . '</head><body style="margin:0;padding:0;background:#f6f7fb;font-family:' . $font . ';color:#111827;font-size:15px;line-height:1.5">'
        . $inner
        . '</body></html>';
}

function render_email_brand_header()
{
    $company = setting('company_name', 'My Company');
    $logoSrc = company_logo_email_src();
    ob_start();
    ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 8px">
  <tr>
    <td align="center" style="padding:8px 0 20px;text-align:center">
      <?php if ($logoSrc !== ''): ?>
        <img src="<?php echo e($logoSrc); ?>" alt="<?php echo e($company); ?>" width="120" style="display:block;margin:0 auto 10px;max-width:120px;max-height:44px;width:auto;height:auto;border:0">
      <?php endif; ?>
      <div style="font-size:18px;font-weight:700;letter-spacing:-0.01em;color:#111827"><?php echo e($company); ?></div>
    </td>
  </tr>
</table>
    <?php
    return trim(ob_get_clean());
}

function render_invoice_paper($invoice, $items, $balance, $showStatus = true)
{
    $company = setting('company_name', 'My Company');
    $logo = company_logo_url();
    $blocks = company_contact_blocks();
    $footer = array_merge($blocks['contact'], $blocks['address']);
    $customerAddress = format_address($invoice);
    $discount = (float) $invoice['discount_amount'];
    $tax = (float) $invoice['tax_amount'];
    $paid = (float) $invoice['amount_paid'];
    ob_start();
    ?>
<div class="invoice-paper">
    <div class="invoice-head">
        <div class="invoice-brand">
            <?php if ($logo !== ''): ?>
                <img class="invoice-logo" src="<?php echo e($logo); ?>" alt="<?php echo e($company); ?>">
            <?php endif; ?>
            <div class="invoice-brand-name"><?php echo e($company); ?></div>
        </div>
        <div class="invoice-title">
            <h2>INVOICE</h2>
            <dl class="invoice-meta">
                <div><dt>Invoice No</dt><dd><?php echo e($invoice['invoice_number']); ?></dd></div>
                <div><dt>Invoice date</dt><dd><?php echo e(format_date($invoice['issue_date'])); ?></dd></div>
                <div><dt>Due date</dt><dd><?php echo e(format_date($invoice['due_date'])); ?></dd></div>
                <?php if ($showStatus): ?>
                    <div><dt>Status</dt><dd><span class="badge badge-<?php echo e($invoice['status']); ?>"><?php echo e(status_label($invoice['status'])); ?></span></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="invoice-parties">
        <div>
            <div class="invoice-label">Invoice to</div>
            <div class="invoice-party-name"><?php echo e($invoice['customer_name']); ?></div>
            <?php if ($invoice['customer_company']): ?>
                <div><?php echo e($invoice['customer_company']); ?></div>
            <?php endif; ?>
            <?php if ($customerAddress !== ''): ?>
                <div class="pre muted"><?php echo e($customerAddress); ?></div>
            <?php endif; ?>
            <?php if ($invoice['customer_email']): ?>
                <div class="muted"><?php echo e($invoice['customer_email']); ?></div>
            <?php endif; ?>
            <?php if ($invoice['customer_phone']): ?>
                <div class="muted"><?php echo e(format_phone($invoice['customer_phone'])); ?></div>
            <?php endif; ?>
        </div>
        <div class="invoice-due">
            <div class="invoice-label">Balance due</div>
            <div class="invoice-due-amount"><?php echo e(money($balance)); ?></div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data invoice-items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num">Qty</th>
                    <th class="num">Price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo e($item['description']); ?></td>
                    <td class="num"><?php echo e($item['quantity']); ?></td>
                    <td class="num"><?php echo e(money($item['unit_price'])); ?></td>
                    <td class="num"><?php echo e(money($item['line_total'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="totals-label">Subtotal</td>
                    <td class="num"><?php echo e(money($invoice['subtotal'])); ?></td>
                </tr>
                <?php if ($discount > 0): ?>
                <tr>
                    <td colspan="3" class="totals-label">Discount</td>
                    <td class="num"><?php echo e(money($discount)); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($tax > 0): ?>
                <tr>
                    <td colspan="3" class="totals-label">Tax (<?php echo e($invoice['tax_rate']); ?>%)</td>
                    <td class="num"><?php echo e(money($tax)); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand">
                    <td colspan="3" class="totals-label">Total</td>
                    <td class="num"><?php echo e(money($invoice['total'])); ?></td>
                </tr>
                <?php if ($paid > 0): ?>
                <tr>
                    <td colspan="3" class="totals-label">Paid</td>
                    <td class="num"><?php echo e(money($paid)); ?></td>
                </tr>
                <tr class="balance">
                    <td colspan="3" class="totals-label">Balance</td>
                    <td class="num"><?php echo e(money($balance)); ?></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </div>

    <div class="invoice-bottom">
        <div class="invoice-terms">
            <?php if ($invoice['notes']): ?>
                <h3>Notes</h3>
                <p class="pre"><?php echo e($invoice['notes']); ?></p>
            <?php endif; ?>
            <?php if ($invoice['terms']): ?>
                <h3>Terms and conditions</h3>
                <p class="pre muted"><?php echo e($invoice['terms']); ?></p>
            <?php endif; ?>
            <p class="invoice-thanks">Thank you for your business.</p>
        </div>
    </div>

    <?php if ($footer): ?>
        <div class="invoice-foot">
            <?php foreach ($footer as $line): ?>
                <span><?php echo e($line); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
    <?php
    return ob_get_clean();
}

function render_invoice_email_html($invoice, $items = null)
{
    if ($items === null) {
        $items = fetch_invoice_items($invoice['id']);
    }
    $balance = invoice_balance($invoice);
    $company = setting('company_name', 'My Company');
    $companyBlocks = company_contact_blocks();
    $footerLines = array_merge($companyBlocks['contact'], $companyBlocks['address']);
    $customerAddress = format_address($invoice);
    $discount = (float) $invoice['discount_amount'];
    $tax = (float) $invoice['tax_amount'];
    $paid = (float) $invoice['amount_paid'];
    $font = invoice_ui_font();
    $logoSrc = company_logo_email_src();
    $label = 'padding:3px 0;color:#6b7280;font-size:14px;font-family:' . $font . ';white-space:nowrap';
    $value = 'padding:3px 0 3px 14px;text-align:right;font-weight:600;font-size:14px;font-family:' . $font;
    $cell = 'padding:11px 8px 11px 0;border-bottom:1px solid #e5e7eb;color:#111827;font-family:' . $font;
    $numCell = 'padding:11px 0 11px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums;font-family:' . $font;
    $totLabel = 'padding:8px 12px 8px 0;border:0;text-align:right;color:#6b7280;white-space:nowrap;font-family:' . $font;
    $totAmt = 'padding:8px 0;border:0;white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums;font-family:' . $font;
    $head = 'padding:11px 8px;background:#f1f2f5;color:#111827;font-size:12px;'
        . 'text-transform:uppercase;letter-spacing:0.05em;font-weight:700;font-family:' . $font;

    ob_start();
    ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 0;width:100%;border-collapse:collapse">
  <tr>
    <td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;font-family:<?php echo invoice_ui_font(); ?>">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">
        <tr>
          <td valign="top" width="50%" style="width:50%;padding:0 16px 26px 0;font-size:14px;color:#111827;font-family:<?php echo $font; ?>">
            <?php if ($logoSrc !== ''): ?>
              <img src="<?php echo e($logoSrc); ?>" alt="<?php echo e($company); ?>" width="136"
                   style="display:block;margin:0 0 9px;max-width:136px;max-height:44px;width:auto;height:auto;border:0">
            <?php endif; ?>
            <div style="font-size:18px;font-weight:700;letter-spacing:-0.01em;line-height:1.3;font-family:<?php echo $font; ?>"><?php echo e($company); ?></div>
          </td>
          <td valign="top" align="right" width="50%" style="width:50%;padding:0 0 26px 16px;text-align:right;font-family:<?php echo $font; ?>">
            <div style="font-size:25px;font-weight:800;letter-spacing:0.06em;color:#111827;line-height:1;font-family:<?php echo $font; ?>">INVOICE</div>
            <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0 0 auto;font-size:14px;font-family:<?php echo $font; ?>">
              <tr>
                <td style="<?php echo $label; ?>">Invoice&nbsp;No</td>
                <td style="<?php echo $value; ?>"><?php echo e($invoice['invoice_number']); ?></td>
              </tr>
              <tr>
                <td style="<?php echo $label; ?>">Invoice&nbsp;date</td>
                <td style="<?php echo $value; ?>"><?php echo e(format_date($invoice['issue_date'])); ?></td>
              </tr>
              <tr>
                <td style="<?php echo $label; ?>">Due&nbsp;date</td>
                <td style="<?php echo $value; ?>"><?php echo e(format_date($invoice['due_date'])); ?></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:10px">
        <tr>
          <td valign="top" width="58%" style="width:58%;padding:0 16px 20px 0;font-size:14px;color:#111827">
            <div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:0.07em;font-weight:650;margin-bottom:5px;font-family:<?php echo $font; ?>">Invoice&nbsp;to</div>
            <div style="font-size:17px;font-weight:700;font-family:<?php echo $font; ?>"><?php echo e($invoice['customer_name']); ?></div>
            <?php if (!empty($invoice['customer_company'])): ?>
              <div><?php echo e($invoice['customer_company']); ?></div>
            <?php endif; ?>
            <?php if ($customerAddress !== ''): ?>
              <div style="color:#6b7280;line-height:1.5"><?php echo implode('<br>', array_map('e', explode("\n", $customerAddress))); ?></div>
            <?php endif; ?>
          </td>
          <td valign="top" align="right" width="42%" style="width:42%;padding:0 0 20px 16px;font-size:14px;color:#111827;text-align:right">
            <div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:0.07em;font-weight:650;margin-bottom:5px;font-family:<?php echo $font; ?>">Balance&nbsp;due</div>
            <div style="font-size:24px;font-weight:800;letter-spacing:-0.01em;font-family:<?php echo $font; ?>"><?php echo e(money($balance)); ?></div>
          </td>
        </tr>
      </table>

      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;width:100%;table-layout:fixed">
        <thead>
          <tr>
            <th align="left" width="52%" bgcolor="#f1f2f5" style="width:52%;<?php echo $head; ?>;text-align:left">Description</th>
            <th align="right" width="10%" bgcolor="#f1f2f5" style="width:10%;<?php echo $head; ?>;white-space:nowrap;text-align:right">Qty</th>
            <th align="right" width="19%" bgcolor="#f1f2f5" style="width:19%;<?php echo $head; ?>;white-space:nowrap;text-align:right">Price</th>
            <th align="right" width="19%" bgcolor="#f1f2f5" style="width:19%;<?php echo $head; ?>;white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums">Amount</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td style="<?php echo $cell; ?>;word-wrap:break-word"><?php echo e($item['description']); ?></td>
            <td align="right" style="<?php echo $numCell; ?>"><?php echo e($item['quantity']); ?></td>
            <td align="right" style="<?php echo $numCell; ?>"><?php echo e(money($item['unit_price'])); ?></td>
            <td align="right" style="<?php echo $numCell; ?>"><?php echo e(money($item['line_total'])); ?></td>
          </tr>
        <?php endforeach; ?>
          <tr>
            <td colspan="3" align="right" style="<?php echo $totLabel; ?>;padding-top:18px">Subtotal</td>
            <td align="right" style="<?php echo $totAmt; ?>;padding-top:18px"><?php echo e(money($invoice['subtotal'])); ?></td>
          </tr>
          <?php if ($discount > 0): ?>
          <tr>
            <td colspan="3" align="right" style="<?php echo $totLabel; ?>">Discount</td>
            <td align="right" style="<?php echo $totAmt; ?>"><?php echo e(money($discount)); ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($tax > 0): ?>
          <tr>
            <td colspan="3" align="right" style="<?php echo $totLabel; ?>">Tax (<?php echo e($invoice['tax_rate']); ?>%)</td>
            <td align="right" style="<?php echo $totAmt; ?>"><?php echo e(money($tax)); ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td colspan="3" align="right" bgcolor="#f1f2f5" style="<?php echo $totLabel; ?>;padding:9px 12px 9px 0;background:#f1f2f5;font-weight:800;color:#111827">Total</td>
            <td align="right" bgcolor="#f1f2f5" style="<?php echo $totAmt; ?>;padding:9px 0;background:#f1f2f5;font-weight:800;color:#111827"><?php echo e(money($invoice['total'])); ?></td>
          </tr>
          <?php if ($paid > 0): ?>
          <tr>
            <td colspan="3" align="right" style="<?php echo $totLabel; ?>">Paid</td>
            <td align="right" style="<?php echo $totAmt; ?>"><?php echo e(money($paid)); ?></td>
          </tr>
          <tr>
            <td colspan="3" align="right" style="<?php echo $totLabel; ?>;font-weight:700;color:#111827">Balance</td>
            <td align="right" style="<?php echo $totAmt; ?>;font-weight:700"><?php echo e(money($balance)); ?></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if (!empty($invoice['notes'])): ?>
        <div style="margin-top:28px">
          <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.06em;font-weight:700;margin-bottom:5px">Notes</div>
          <div style="font-size:14px"><?php echo nl2br(e($invoice['notes']), false); ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($invoice['terms'])): ?>
        <div style="margin-top:18px">
          <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.06em;font-weight:700;margin-bottom:5px">Terms and conditions</div>
          <div style="color:#6b7280;font-size:14px"><?php echo nl2br(e($invoice['terms']), false); ?></div>
        </div>
      <?php endif; ?>
      <div style="margin-top:18px;font-size:14px;font-weight:700">Thank you for your business.</div>
      <?php if ($footerLines): ?>
        <div style="margin-top:24px;padding-top:14px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;line-height:1.6">
          <?php echo implode(' &nbsp;·&nbsp; ', array_map('e', $footerLines)); ?>
        </div>
      <?php endif; ?>
    </td>
  </tr>
</table>
    <?php
    return trim(ob_get_clean());
}

function save_invoice($id, $data, $items)
{
    $calc = calculate_invoice_totals(
        $items,
        $data['discount_type'],
        $data['discount_value'],
        $data['tax_rate']
    );
    if (count($calc['items']) === 0) {
        throw new InvalidArgumentException('Add at least one invoice item.');
    }

    $pdo = db();
    $isNew = !$id;
    $pdo->beginTransaction();
    try {
        if ($isNew) {
            $number = next_invoice_number();
            $sql = 'INSERT INTO invoices (
                        tenant_id, invoice_number, customer_id, created_by, status, issue_date, due_date,
                        subtotal, discount_type, discount_value, discount_amount, tax_rate, tax_amount,
                        total, amount_paid, currency_code, notes, internal_notes, terms
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $pdo->prepare($sql)->execute(array(
                tenant_id(),
                $number,
                $data['customer_id'],
                $data['created_by'],
                $data['status'],
                $data['issue_date'],
                $data['due_date'],
                $calc['subtotal'],
                $calc['discount_type'],
                $calc['discount_value'],
                $calc['discount_amount'],
                $calc['tax_rate'],
                $calc['tax_amount'],
                $calc['total'],
                0,
                setting('currency_code', 'USD'),
                $data['notes'],
                isset($data['internal_notes']) ? $data['internal_notes'] : '',
                $data['terms'],
            ));
            $id = (int) $pdo->lastInsertId();
            if ($data['status'] === 'sent') {
                $pdo->prepare('UPDATE invoices SET sent_at = NOW() WHERE id = ? AND tenant_id = ?')->execute(array($id, tenant_id()));
            }
        } else {
            $existing = fetch_invoice($id);
            if (!$existing) {
                throw new InvalidArgumentException('Invoice not found.');
            }
            $status = $data['status'];
            $sentAtSql = '';
            if ($existing['status'] === 'draft' && $status === 'sent') {
                $sentAtSql = ', sent_at = NOW()';
            }
            $sql = 'UPDATE invoices SET
                        customer_id = ?, status = ?, issue_date = ?, due_date = ?,
                        subtotal = ?, discount_type = ?, discount_value = ?, discount_amount = ?,
                        tax_rate = ?, tax_amount = ?, total = ?, notes = ?, internal_notes = ?, terms = ?
                        ' . $sentAtSql . '
                    WHERE id = ? AND tenant_id = ?';
            $pdo->prepare($sql)->execute(array(
                $data['customer_id'],
                $status,
                $data['issue_date'],
                $data['due_date'],
                $calc['subtotal'],
                $calc['discount_type'],
                $calc['discount_value'],
                $calc['discount_amount'],
                $calc['tax_rate'],
                $calc['tax_amount'],
                $calc['total'],
                $data['notes'],
                isset($data['internal_notes']) ? $data['internal_notes'] : '',
                $data['terms'],
                $id,
                tenant_id(),
            ));
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute(array($id));
        }

        $itemSql = 'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, tax_rate, line_total, sort_order)
                    VALUES (?,?,?,?,?,?,?)';
        $itemStmt = $pdo->prepare($itemSql);
        foreach ($calc['items'] as $item) {
            $itemStmt->execute(array(
                $id,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['tax_rate'],
                $item['line_total'],
                $item['sort_order'],
            ));
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    refresh_invoice_paid_total($id);
    return $id;
}

require_once __DIR__ . '/invoice_pdf.php';
