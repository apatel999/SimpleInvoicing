<?php

$pageTitle = 'Customers';
$currentPage = 'customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_verify();
    $id = (int) post('id');
    try {
        db()->prepare('DELETE FROM customers WHERE id = ?')->execute(array($id));
        flash('success', 'Customer deleted.');
    } catch (Exception $e) {
        flash('error', 'This customer has invoices or payments and cannot be deleted.');
    }
    redirect('customers');
}

$q = query('q');
$where = '';
$params = array();
if ($q !== '') {
    $where = 'WHERE name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR customer_code LIKE ?';
    $like = '%' . $q . '%';
    $params = array($like, $like, $like, $like, $like);
}

$countStmt = db()->prepare('SELECT COUNT(*) FROM customers ' . $where);
$countStmt->execute($params);
$pager = paginate((int) $countStmt->fetchColumn());

$sql = 'SELECT * FROM customers ' . $where . ' ORDER BY name ASC LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'];
$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<div class="page-head">
    <form class="search" method="get" action="<?php echo e(url('customers')); ?>">
        <input type="hidden" name="page" value="customers">
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search name, email, company">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    <a class="btn" href="<?php echo e(url('customer')); ?>">+ New Customer</a>
</div>

<div class="card">
    <?php if (!$customers): ?>
        <p class="empty">No customers found.</p>
    <?php else: ?>
        <div class="data-grid data-grid-customers">
            <div class="data-grid-head" aria-hidden="true">
                <span>Name</span>
                <span>Company</span>
                <span>Email</span>
                <span>Phone</span>
                <span></span>
            </div>
            <?php foreach ($customers as $customer): ?>
                <article class="data-grid-row">
                    <a class="cell-title" href="<?php echo e(url('customer', array('id' => $customer['id']))); ?>"><?php echo e($customer['name']); ?></a>
                    <span class="cell-company"><?php echo e($customer['company']); ?></span>
                    <span class="cell-email"><?php echo e($customer['email']); ?></span>
                    <span class="cell-phone"><?php echo e(format_phone($customer['phone'])); ?></span>
                    <div class="cell-actions">
                        <a class="icon-btn" href="<?php echo e(url('customer', array('id' => $customer['id']))); ?>" title="View" aria-label="View customer"><?php echo action_icon('view'); ?></a>
                        <a class="icon-btn" href="<?php echo e(url('customer', array('id' => $customer['id']))); ?>" title="Edit" aria-label="Edit customer"><?php echo action_icon('edit'); ?></a>
                        <a class="icon-btn" href="<?php echo e(url('invoice_form', array('customer_id' => $customer['id']))); ?>" title="New invoice" aria-label="Create invoice"><?php echo action_icon('invoice'); ?></a>
                        <form method="post" data-confirm="Delete this customer?">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $customer['id']; ?>">
                            <button class="icon-btn icon-btn-danger" type="submit" title="Delete" aria-label="Delete customer"><?php echo action_icon('delete'); ?></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php render_pagination($pager, 'customers', array('q' => $q)); ?>
    <?php endif; ?>
</div>
