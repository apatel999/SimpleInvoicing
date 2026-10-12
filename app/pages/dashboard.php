<?php

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_verify();
    $result = cancel_invoice(fetch_invoice((int) post('id')));
    if ($result === true) {
        flash('success', 'Invoice cancelled.');
    } else {
        flash('error', $result);
    }
    redirect('dashboard');
}

$outstanding = (float) $pdo->query("
    SELECT COALESCE(SUM(total - amount_paid), 0)
    FROM invoices
    WHERE status NOT IN ('cancelled', 'draft', 'paid')
")->fetchColumn();

$paidMonth = (float) $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
")->fetchColumn();

$overdueCount = (int) $pdo->query("
    SELECT COUNT(*) FROM invoices WHERE status = 'overdue'
")->fetchColumn();

$draftCount = (int) $pdo->query("
    SELECT COUNT(*) FROM invoices WHERE status = 'draft'
")->fetchColumn();

$recent = $pdo->query("
    SELECT invoices.id, invoices.invoice_number, invoices.status, invoices.total,
           invoices.amount_paid, invoices.issue_date, invoices.due_date, customers.name AS customer_name
    FROM invoices
    INNER JOIN customers ON customers.id = invoices.customer_id
    ORDER BY invoices.id DESC
    LIMIT 8
")->fetchAll();
?>
<div class="stats">
    <div class="card stat">
        <span>Outstanding</span>
        <strong><?php echo e(money($outstanding)); ?></strong>
    </div>
    <div class="card stat">
        <span>Paid this month</span>
        <strong><?php echo e(money($paidMonth)); ?></strong>
    </div>
    <div class="card stat">
        <span>Overdue</span>
        <strong><?php echo (int) $overdueCount; ?></strong>
    </div>
    <div class="card stat">
        <span>Drafts</span>
        <strong><?php echo (int) $draftCount; ?></strong>
    </div>
</div>

<div class="card">
    <div class="page-head">
        <div>
            <h2>Recent invoices</h2>
            <p>Latest activity across your ledger.</p>
        </div>
        <a class="btn" href="<?php echo e(url('invoice_form')); ?>">+ New Invoice</a>
    </div>
    <?php if (!$recent): ?>
        <p class="empty">No invoices yet. Create a customer, then issue your first invoice.</p>
    <?php else: ?>
        <div class="data-grid data-grid-invoices">
            <div class="data-grid-head" aria-hidden="true">
                <span>Number</span>
                <span>Customer</span>
                <span class="hide-lg">Issue</span>
                <span>Due</span>
                <span>Status</span>
                <span class="num">Total</span>
                <span class="num hide-lg">Balance</span>
                <span></span>
            </div>
            <?php foreach ($recent as $invoice): ?>
                <article class="data-grid-row">
                    <a class="cell-title" href="<?php echo e(url('invoice', array('id' => $invoice['id']))); ?>"><?php echo e($invoice['invoice_number']); ?></a>
                    <span class="cell-customer"><?php echo e($invoice['customer_name']); ?></span>
                    <span class="cell-issue hide-lg"><?php echo e(format_date($invoice['issue_date'])); ?></span>
                    <span class="cell-due"><?php echo e(format_date($invoice['due_date'])); ?></span>
                    <span class="cell-status"><span class="badge badge-<?php echo e($invoice['status']); ?>"><?php echo e(status_label($invoice['status'])); ?></span></span>
                    <span class="cell-total num"><?php echo e(money($invoice['total'])); ?></span>
                    <span class="cell-balance num hide-lg"><?php echo e(money($invoice['total'] - $invoice['amount_paid'])); ?></span>
                    <div class="cell-actions">
                        <a class="icon-btn" href="<?php echo e(url('invoice', array('id' => $invoice['id']))); ?>" title="View" aria-label="View invoice"><?php echo action_icon('view'); ?></a>
                        <a class="icon-btn" href="<?php echo e(url('invoice_form', array('id' => $invoice['id']))); ?>" title="Edit" aria-label="Edit invoice"><?php echo action_icon('edit'); ?></a>
                        <?php if ($invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid'): ?>
                            <form method="post" data-confirm="Cancel this invoice? It will be kept with status Cancelled.">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $invoice['id']; ?>">
                                <button class="icon-btn icon-btn-danger" type="submit" title="Cancel invoice" aria-label="Cancel invoice"><?php echo action_icon('delete'); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
