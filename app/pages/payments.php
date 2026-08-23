<?php

$pageTitle = 'Payments';
$currentPage = 'payments';
$methods = array(
    'bank_transfer' => 'Bank transfer',
    'credit_card' => 'Card',
    'paypal' => 'PayPal',
    'cash' => 'Cash',
    'check' => 'Check',
    'other' => 'Other',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');

    if ($action === 'delete') {
        $paymentId = (int) post('id');
        $stmt = db()->prepare('SELECT invoice_id FROM payments WHERE id = ?');
        $stmt->execute(array($paymentId));
        $invoiceId = $stmt->fetchColumn();
        if ($invoiceId) {
            db()->prepare('DELETE FROM payments WHERE id = ?')->execute(array($paymentId));
            refresh_invoice_paid_total($invoiceId);
            flash('success', 'Payment deleted.');
        }
        redirect('payments');
    }

    if ($action === 'create') {
        $invoiceId = (int) post('invoice_id');
        $amount = round((float) post('amount'), 2);
        $invoice = fetch_invoice($invoiceId);
        if (!$invoice || $invoice['status'] === 'cancelled') {
            flash('error', 'Choose a valid invoice.');
        } elseif ($amount <= 0) {
            flash('error', 'Enter a payment amount greater than zero.');
        } else {
            $method = post('method', 'other');
            if (!isset($methods[$method])) {
                $method = 'other';
            }
            db()->prepare('INSERT INTO payments (invoice_id, customer_id, amount, payment_date, method, reference, notes)
                           VALUES (?,?,?,?,?,?,?)')->execute(array(
                $invoiceId,
                $invoice['customer_id'],
                $amount,
                post('payment_date', date('Y-m-d')),
                $method,
                post('reference'),
                post('notes'),
            ));
            refresh_invoice_paid_total($invoiceId);
            flash('success', 'Payment recorded.');
        }
        redirect('payments');
    }
}

$q = query('q');
$where = '';
$params = array();
if ($q !== '') {
    $where = 'WHERE invoices.invoice_number LIKE ? OR customers.name LIKE ? OR payments.reference LIKE ?';
    $like = '%' . $q . '%';
    $params = array($like, $like, $like);
}

$countStmt = db()->prepare("
    SELECT COUNT(*)
    FROM payments
    INNER JOIN invoices ON invoices.id = payments.invoice_id
    INNER JOIN customers ON customers.id = payments.customer_id
    $where
");
$countStmt->execute($params);
$pager = paginate((int) $countStmt->fetchColumn());

$stmt = db()->prepare("
    SELECT payments.*, invoices.invoice_number, customers.name AS customer_name
    FROM payments
    INNER JOIN invoices ON invoices.id = payments.invoice_id
    INNER JOIN customers ON customers.id = payments.customer_id
    $where
    ORDER BY payments.payment_date DESC, payments.id DESC
    LIMIT " . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$openInvoices = db()->query("
    SELECT invoices.id, invoices.invoice_number, invoices.total, invoices.amount_paid, customers.name AS customer_name
    FROM invoices
    INNER JOIN customers ON customers.id = invoices.customer_id
    WHERE invoices.status NOT IN ('cancelled', 'paid')
    ORDER BY invoices.id DESC
")->fetchAll();
?>
<div class="split">
    <div class="card">
        <div class="page-head">
            <form class="search" method="get">
                <input type="hidden" name="page" value="payments">
                <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search invoice, customer, reference">
                <button class="btn btn-secondary" type="submit">Search</button>
            </form>
        </div>
        <?php if (!$payments): ?>
            <p class="empty">No payments recorded yet.</p>
        <?php else: ?>
            <div class="data-grid data-grid-payments">
                <div class="data-grid-head" aria-hidden="true">
                    <span>Date</span>
                    <span>Invoice</span>
                    <span>Customer</span>
                    <span>Method</span>
                    <span>Amount</span>
                    <span></span>
                </div>
                <?php foreach ($payments as $payment): ?>
                    <article class="data-grid-row">
                        <span class="cell-date"><?php echo e(format_date($payment['payment_date'])); ?></span>
                        <a class="cell-title" href="<?php echo e(url('invoice', array('id' => $payment['invoice_id']))); ?>"><?php echo e($payment['invoice_number']); ?></a>
                        <span class="cell-customer"><?php echo e($payment['customer_name']); ?></span>
                        <span class="cell-method"><?php echo e(isset($methods[$payment['method']]) ? $methods[$payment['method']] : $payment['method']); ?></span>
                        <span class="cell-total"><?php echo e(money($payment['amount'])); ?></span>
                        <div class="cell-actions">
                            <a class="icon-btn" href="<?php echo e(url('invoice', array('id' => $payment['invoice_id']))); ?>" title="View" aria-label="View invoice"><?php echo action_icon('view'); ?></a>
                            <form method="post" data-confirm="Delete this payment?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $payment['id']; ?>">
                                <button class="icon-btn icon-btn-danger" type="submit" title="Delete" aria-label="Delete payment"><?php echo action_icon('delete'); ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php render_pagination($pager, 'payments', array('q' => $q)); ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Record payment</h2>
        <?php if (!$openInvoices): ?>
            <p class="empty">There are no sent invoices with a remaining balance.</p>
        <?php else: ?>
            <form method="post" class="form-stack">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <p>
                    <label for="invoice_id">Invoice</label>
                    <select id="invoice_id" name="invoice_id" required>
                        <?php foreach ($openInvoices as $inv): ?>
                            <option value="<?php echo (int) $inv['id']; ?>">
                                <?php echo e($inv['invoice_number'] . ' · ' . $inv['customer_name'] . ' · due ' . money($inv['total'] - $inv['amount_paid'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="amount">Amount</label>
                    <input id="amount" type="number" name="amount" min="0.01" step="0.01" required>
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
                    <label for="notes">Notes</label>
                    <input id="notes" name="notes">
                </p>
                <div class="form-actions">
                    <button class="btn" type="submit">Save payment</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
