<?php

$pageTitle = 'Invoices';
$currentPage = 'invoices';
$GLOBALS['breadcrumbs'] = array(
    array('Home', url('dashboard')),
    array('Invoices', ''),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_verify();
    $result = cancel_invoice(fetch_invoice((int) post('id')));
    if ($result === true) {
        flash('success', 'Invoice cancelled.');
    } else {
        flash('error', $result);
    }
    redirect('invoices');
}

$q = query('q');
$status = query('status');
$where = array('1=1');
$params = array();
if ($q !== '') {
    $where[] = '(invoices.invoice_number LIKE ? OR customers.name LIKE ? OR customers.company LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($status !== '') {
    $where[] = 'invoices.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare("
    SELECT COUNT(*)
    FROM invoices
    INNER JOIN customers ON customers.id = invoices.customer_id
    WHERE $whereSql
");
$countStmt->execute($params);
$pager = paginate((int) $countStmt->fetchColumn());

$stmt = db()->prepare("
    SELECT invoices.*, customers.name AS customer_name
    FROM invoices
    INNER JOIN customers ON customers.id = invoices.customer_id
    WHERE $whereSql
    ORDER BY invoices.id DESC
    LIMIT " . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$invoices = $stmt->fetchAll();
?>
<div class="page-head">
    <form class="search" method="get">
        <input type="hidden" name="page" value="invoices">
        <input type="hidden" name="status" value="<?php echo e($status); ?>">
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search number or customer">
        <button class="btn btn-secondary" type="submit">Filter</button>
    </form>
    <a class="btn" href="<?php echo e(url('invoice_form')); ?>">+ New Invoice</a>
</div>
<nav class="tabs">
    <?php
    $tabs = array(
        '' => 'All',
        'draft' => 'Draft',
        'sent' => 'Sent',
        'overdue' => 'Due',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
    );
    foreach ($tabs as $tabStatus => $tabLabel):
        $active = $status === $tabStatus;
    ?>
        <a class="tab<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo e(url('invoices', array('q' => $q, 'status' => $tabStatus))); ?>"><?php echo e($tabLabel); ?></a>
    <?php endforeach; ?>
</nav>

<div class="card">
    <?php if (!$invoices): ?>
        <p class="empty">No invoices found.</p>
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
            <?php foreach ($invoices as $invoice): ?>
                <article class="data-grid-row">
                    <a class="cell-title" href="<?php echo e(url('invoice', array('id' => $invoice['id']))); ?>"><?php echo e($invoice['invoice_number']); ?></a>
                    <span class="cell-customer"><?php echo e($invoice['customer_name']); ?></span>
                    <span class="cell-issue hide-lg"><?php echo e(format_date($invoice['issue_date'])); ?></span>
                    <span class="cell-due"><?php echo e(format_date($invoice['due_date'])); ?></span>
                    <span class="cell-status"><span class="badge badge-<?php echo e($invoice['status']); ?>"><?php echo e(status_label($invoice['status'])); ?></span></span>
                    <span class="cell-total num"><?php echo e(money($invoice['total'])); ?></span>
                    <span class="cell-balance num hide-lg"><?php echo e(money(invoice_balance($invoice))); ?></span>
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
        <?php render_pagination($pager, 'invoices', array('q' => $q, 'status' => $status)); ?>
    <?php endif; ?>
</div>
