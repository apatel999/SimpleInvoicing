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
              AND status IN ('sent', 'viewed', 'partial')
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

function invoice_email_body($templateBody, $vars, $invoice, $items = null)
{
    $intro = apply_template($templateBody, $vars);
    $invoiceHtml = render_invoice_email_html($invoice, $items);
    if (strpos($templateBody, '{{invoice_html}}') !== false) {
        $intro = str_replace('{{invoice_html}}', $invoiceHtml, $intro);
        $invoiceHtml = '';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f6f7fb">'
        . '<tr><td align="center" style="padding:24px 10px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
        . ' style="border-collapse:collapse;width:100%;max-width:700px;text-align:left;'
        . 'font-family:Inter,Segoe UI,Arial,sans-serif;color:#111827;font-size:15px;line-height:1.5">'
        . '<tr><td>'
        . render_email_brand_header()
        . $intro
        . $invoiceHtml
        . '</td></tr></table>'
        . '</td></tr></table>';
}

function render_email_brand_header()
{
    $company = setting('company_name', 'My Company');
    $logoSrc = company_logo_file() !== '' ? 'cid:company-logo' : '';
    ob_start();
    ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 8px">
  <tr>
    <td align="center" style="padding:8px 0 20px;text-align:center">
      <?php if ($logoSrc !== ''): ?>
        <img src="<?php echo e($logoSrc); ?>" alt="<?php echo e($company); ?>" width="120" style="display:block;margin:0 auto 10px;max-width:120px;max-height:44px;width:auto;height:auto;border:0">
      <?php endif; ?>
      <div style="font-size:20px;font-weight:700;letter-spacing:0.01em;color:#111827"><?php echo e($company); ?></div>
      <div style="margin-top:18px;font-size:22px;font-weight:700;color:#111827;line-height:1.3">Your invoice from <?php echo e($company); ?></div>
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
        </table>
    </div>

    <div class="invoice-bottom">
        <div class="totals">
            <div><span>Subtotal</span><span><?php echo e(money($invoice['subtotal'])); ?></span></div>
            <?php if ($discount > 0): ?>
                <div><span>Discount</span><span><?php echo e(money($discount)); ?></span></div>
            <?php endif; ?>
            <?php if ($tax > 0): ?>
                <div><span>Tax (<?php echo e($invoice['tax_rate']); ?>%)</span><span><?php echo e(money($tax)); ?></span></div>
            <?php endif; ?>
            <div class="grand"><span>Total</span><span><?php echo e(money($invoice['total'])); ?></span></div>
            <?php if ($paid > 0): ?>
                <div><span>Paid</span><span><?php echo e(money($paid)); ?></span></div>
                <div class="balance"><span>Balance</span><span><?php echo e(money($balance)); ?></span></div>
            <?php endif; ?>
        </div>
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
    $label = 'padding:3px 0;color:#6b7280';
    $value = 'padding:3px 0 3px 14px;text-align:right;font-weight:600';
    $cell = 'padding:11px 8px 11px 0;border-bottom:1px solid #e5e7eb;color:#111827';
    $numCell = 'padding:11px 0 11px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;text-align:right';
    $head = 'padding:11px 8px;background:#f1f2f5;color:#111827;font-size:12px;'
        . 'text-transform:uppercase;letter-spacing:0.05em;font-weight:700';

    ob_start();
    ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 0;width:100%;border-collapse:collapse">
  <tr>
    <td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;padding:24px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">
        <tr>
          <td valign="top" width="50%" style="width:50%;padding:0 16px 26px 0;font-size:14px;color:#111827">
            <div style="font-size:18px;font-weight:700;line-height:1.3"><?php echo e($company); ?></div>
          </td>
          <td valign="top" align="right" width="50%" style="width:50%;padding:0 0 26px 16px;text-align:right">
            <div style="font-size:30px;font-weight:800;letter-spacing:0.06em;color:#111827;line-height:1">INVOICE</div>
            <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0 0 auto;font-size:13px">
              <tr>
                <td style="<?php echo $label; ?>">Invoice No</td>
                <td style="<?php echo $value; ?>"><?php echo e($invoice['invoice_number']); ?></td>
              </tr>
              <tr>
                <td style="<?php echo $label; ?>">Invoice date</td>
                <td style="<?php echo $value; ?>"><?php echo e(format_date($invoice['issue_date'])); ?></td>
              </tr>
              <tr>
                <td style="<?php echo $label; ?>">Due date</td>
                <td style="<?php echo $value; ?>"><?php echo e(format_date($invoice['due_date'])); ?></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:10px">
        <tr>
          <td valign="top" width="58%" style="width:58%;padding:0 16px 20px 0;font-size:14px;color:#111827">
            <div style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.07em;font-weight:700;margin-bottom:5px">Invoice to</div>
            <div style="font-size:16px;font-weight:700"><?php echo e($invoice['customer_name']); ?></div>
            <?php if (!empty($invoice['customer_company'])): ?>
              <div><?php echo e($invoice['customer_company']); ?></div>
            <?php endif; ?>
            <?php if ($customerAddress !== ''): ?>
              <div style="color:#6b7280;line-height:1.5"><?php echo implode('<br>', array_map('e', explode("\n", $customerAddress))); ?></div>
            <?php endif; ?>
          </td>
          <td valign="top" align="right" width="42%" style="width:42%;padding:0 0 20px 16px;font-size:14px;color:#111827;text-align:right">
            <div style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.07em;font-weight:700;margin-bottom:5px">Balance due</div>
            <div style="font-size:22px;font-weight:800"><?php echo e(money($balance)); ?></div>
          </td>
        </tr>
      </table>

      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;width:100%;table-layout:fixed">
        <thead>
          <tr>
            <th align="left" width="52%" bgcolor="#f1f2f5" style="width:52%;<?php echo $head; ?>;text-align:left">Description</th>
            <th align="right" width="10%" bgcolor="#f1f2f5" style="width:10%;<?php echo $head; ?>;white-space:nowrap;text-align:right">Qty</th>
            <th align="right" width="19%" bgcolor="#f1f2f5" style="width:19%;<?php echo $head; ?>;white-space:nowrap;text-align:right">Price</th>
            <th align="right" width="19%" bgcolor="#f1f2f5" style="width:19%;<?php echo $head; ?>;white-space:nowrap;text-align:right">Amount</th>
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
        </tbody>
      </table>

      <table role="presentation" cellpadding="0" cellspacing="0" align="right" width="280" style="margin:18px 0 0 auto;width:280px;border-collapse:collapse;font-size:14px">
        <tr>
          <td style="padding:4px 0;color:#6b7280">Subtotal</td>
          <td align="right" style="padding:4px 0;text-align:right"><?php echo e(money($invoice['subtotal'])); ?></td>
        </tr>
        <?php if ($discount > 0): ?>
          <tr>
            <td style="padding:4px 0;color:#6b7280">Discount</td>
            <td align="right" style="padding:4px 0;text-align:right"><?php echo e(money($discount)); ?></td>
          </tr>
        <?php endif; ?>
        <?php if ($tax > 0): ?>
          <tr>
            <td style="padding:4px 0;color:#6b7280">Tax (<?php echo e($invoice['tax_rate']); ?>%)</td>
            <td align="right" style="padding:4px 0;text-align:right"><?php echo e(money($tax)); ?></td>
          </tr>
        <?php endif; ?>
        <tr>
          <td bgcolor="#f1f2f5" style="padding:9px 10px;background:#f1f2f5;font-weight:800">Total</td>
          <td align="right" bgcolor="#f1f2f5" style="padding:9px 10px;background:#f1f2f5;font-weight:800;text-align:right"><?php echo e(money($invoice['total'])); ?></td>
        </tr>
        <?php if ($paid > 0): ?>
          <tr>
            <td style="padding:7px 0 4px;color:#6b7280">Paid</td>
            <td align="right" style="padding:7px 0 4px;text-align:right"><?php echo e(money($paid)); ?></td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-weight:700">Balance</td>
            <td align="right" style="padding:4px 0;font-weight:700;text-align:right"><?php echo e(money($balance)); ?></td>
          </tr>
        <?php endif; ?>
      </table>
      <div style="clear:both"></div>

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
