<?php

$currentPage = 'invoices';
upgrade_stock_email_templates();
$id = (int) query('id');
$invoice = fetch_invoice($id);
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('invoices');
}

$pageTitle = $invoice['invoice_number'];
$items = fetch_invoice_items($id);
$tplList = db()->prepare('SELECT id, name, slug FROM email_templates WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$tplList->execute(array(tenant_id()));
$templates = $tplList->fetchAll();
$sendTemplateId = 0;
foreach ($templates as $template) {
    if ($template['slug'] === 'invoice_sent') {
        $sendTemplateId = (int) $template['id'];
        break;
    }
}
$payStmt = db()->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC, id DESC');
$payStmt->execute(array($id));
$payments = $payStmt->fetchAll();
$balance = invoice_balance($invoice);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');

    if ($action === 'cancel' && $invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid') {
        db()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ? AND tenant_id = ?")->execute(array($id, tenant_id()));
        flash('success', 'Invoice cancelled.');
        redirect('invoice', array('id' => $id));
    }

    if ($action === 'save_internal_note') {
        db()->prepare('UPDATE invoices SET internal_notes = ? WHERE id = ? AND tenant_id = ?')
            ->execute(array(post('internal_notes'), $id, tenant_id()));
        flash('success', 'Internal note saved.');
        redirect('invoice', array('id' => $id));
    }

    if ($action === 'add_payment' && $invoice['status'] !== 'cancelled') {
        $amount = round((float) post('amount'), 2);
        $date = post('payment_date', date('Y-m-d'));
        $method = post('method', 'other');
        $allowed = array('cash', 'check', 'bank_transfer', 'credit_card', 'paypal', 'other');
        if (!in_array($method, $allowed, true)) {
            $method = 'other';
        }
        if ($amount <= 0) {
            flash('error', 'Enter a payment amount greater than zero.');
        } else {
            db()->prepare('INSERT INTO payments (tenant_id, invoice_id, customer_id, amount, payment_date, method, reference, notes)
                           VALUES (?,?,?,?,?,?,?,?)')->execute(array(
                tenant_id(),
                $id,
                $invoice['customer_id'],
                $amount,
                $date,
                $method,
                post('reference'),
                post('payment_notes'),
            ));
            refresh_invoice_paid_total($id);
            $invoice = fetch_invoice($id);
            if (post('email_receipt') === '1' && $invoice['customer_email']) {
                $tplStmt = db()->prepare("SELECT * FROM email_templates WHERE tenant_id = ? AND slug = 'payment_received' AND is_active = 1 LIMIT 1");
                $tplStmt->execute(array(tenant_id()));
                $tpl = $tplStmt->fetch();
                if ($tpl) {
                    $vars = invoice_template_vars($invoice, $amount);
                    $result = send_app_mail(
                        $invoice['customer_email'],
                        apply_template($tpl['subject'], $vars),
                        invoice_email_body($tpl['body'], $vars, $invoice)
                    );
                    if ($result !== true) {
                        flash('error', 'Payment saved, but email failed: ' . $result);
                        redirect('invoice', array('id' => $id));
                    }
                }
            }
            flash('success', 'Payment recorded.');
        }
        redirect('invoice', array('id' => $id));
    }

    if ($action === 'send_email') {
        if ($invoice['customer_email'] === null || $invoice['customer_email'] === '') {
            flash('error', 'This customer has no email address.');
            redirect('invoice', array('id' => $id));
        }
        $tplStmt = db()->prepare('SELECT * FROM email_templates WHERE id = ? AND tenant_id = ? AND is_active = 1');
        $tplStmt->execute(array((int) post('template_id'), tenant_id()));
        $tpl = $tplStmt->fetch();
        if (!$tpl) {
            flash('error', 'Choose an email template.');
        } else {
            $vars = invoice_template_vars($invoice);
            $result = send_app_mail(
                $invoice['customer_email'],
                apply_template($tpl['subject'], $vars),
                invoice_email_body($tpl['body'], $vars, $invoice)
            );
            if ($result === true) {
                if ($invoice['status'] === 'draft') {
                    db()->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ? AND tenant_id = ?")->execute(array($id, tenant_id()));
                } elseif ($invoice['status'] === 'sent' || $invoice['sent_at'] === null) {
                    db()->prepare('UPDATE invoices SET sent_at = COALESCE(sent_at, NOW()) WHERE id = ? AND tenant_id = ?')->execute(array($id, tenant_id()));
                }
                flash('success', 'Email sent to ' . $invoice['customer_email'] . '.');
            } else {
                flash('error', $result);
            }
        }
        redirect('invoice', array('id' => $id));
    }
}

$askSend = query('ask_send') === '1'
    && $sendTemplateId > 0
    && $invoice['customer_email'] !== null
    && $invoice['customer_email'] !== ''
    && $invoice['sent_at'] === null
    && $invoice['status'] !== 'cancelled';

$methods = array(
    'bank_transfer' => 'Bank transfer',
    'credit_card' => 'Card',
    'paypal' => 'PayPal',
    'cash' => 'Cash',
    'check' => 'Check',
    'other' => 'Other',
);
?>
<div class="page-head no-print">
    <div>
        <p class="muted"><?php echo e($invoice['customer_name']); ?></p>
    </div>
    <div class="row-actions">
        <?php if ($invoice['status'] !== 'cancelled'): ?>
        <a class="btn btn-ghost" href="<?php echo e(url('invoice_form', array('id' => $id))); ?>">Edit</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?php echo e(invoice_public_url($id)); ?>" target="_blank" rel="noopener">Public link</a>
        <a class="btn" href="<?php echo e(url('invoice_download', array('id' => $id))); ?>">Download PDF</a>
        <button class="btn btn-secondary" type="button" onclick="window.print()">Print</button>
        <?php if ($invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid'): ?>
            <form method="post" data-confirm="Cancel this invoice?">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-danger" type="submit">Cancel invoice</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="split">
    <?php echo render_invoice_paper($invoice, $items, $balance); ?>

    <div class="no-print">
        <div class="card internal-note-card">
            <h2>Internal note</h2>
            <p class="muted">Only your team can see this. Customers never see it on the invoice, PDF, or email.</p>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_internal_note">
                <p>
                    <textarea id="internal_notes" name="internal_notes" rows="4" placeholder="Private reminder for your team"><?php echo e(isset($invoice['internal_notes']) ? $invoice['internal_notes'] : ''); ?></textarea>
                </p>
                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit">Save note</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Send email</h2>
            <p class="muted"><?php echo $invoice['customer_email'] ? e($invoice['customer_email']) : 'No customer email on file.'; ?></p>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="send_email">
                <p>
                    <label for="template_id">Template</label>
                    <select id="template_id" name="template_id" required>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo (int) $template['id']; ?>"><?php echo e($template['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <div class="form-actions">
                    <button class="btn" type="submit"<?php echo $invoice['customer_email'] ? '' : ' disabled'; ?>>Send</button>
                </div>
            </form>
        </div>

        <?php if ($invoice['status'] !== 'cancelled'): ?>
        <div class="card">
            <h2>Record payment</h2>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_payment">
                <p>
                    <label for="amount">Amount</label>
                    <input id="amount" type="number" name="amount" min="0.01" step="0.01" required value="<?php echo e(max($balance, 0)); ?>">
                </p>
                <p>
                    <label for="payment_date">Date</label>
                    <input id="payment_date" type="date" name="payment_date" required value="<?php echo e(date('Y-m-d')); ?>">
                </p>
                <p>
                    <label for="method">Method</label>
                    <select id="method" name="method">
                        <?php foreach ($methods as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="reference">Reference</label>
                    <input id="reference" name="reference">
                </p>
                <p>
                    <label><input type="checkbox" name="email_receipt" value="1"> Email payment receipt</label>
                </p>
                <div class="form-actions">
                    <button class="btn btn-secondary" type="submit">Save payment</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Payments</h2>
            <?php if (!$payments): ?>
                <p class="empty">No payments yet.</p>
            <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                    <p>
                        <strong><?php echo e(money($payment['amount'])); ?></strong>
                        <span class="muted"><?php echo e(format_date($payment['payment_date'])); ?> · <?php echo e($payment['method']); ?></span>
                        <?php if ($payment['reference']): ?><div class="muted"><?php echo e($payment['reference']); ?></div><?php endif; ?>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($askSend): ?>
<div class="modal-overlay no-print" id="send-prompt">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="send-prompt-title">
        <h2 id="send-prompt-title">Invoice saved</h2>
        <p>Email <?php echo e($invoice['invoice_number']); ?> to <?php echo e($invoice['customer_email']); ?> now?</p>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send_email">
            <input type="hidden" name="template_id" value="<?php echo $sendTemplateId; ?>">
            <div class="modal-actions">
                <a class="btn btn-ghost" href="<?php echo e(url('invoice', array('id' => $id))); ?>">Not now</a>
                <button class="btn" type="submit">Send now</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
