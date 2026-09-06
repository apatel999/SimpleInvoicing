<?php

$pageTitle = 'Tenants';
$currentPage = 'tenants';

$q = query('q');
$where = '1=1';
$params = array();
if ($q !== '') {
    $where .= ' AND (tenants.name LIKE ? OR tenants.slug LIKE ? OR users.email LIKE ?)';
    $like = '%' . $q . '%';
    $params = array($like, $like, $like);
}

$sql = "
    SELECT tenants.*,
           subscriptions.status AS sub_status,
           subscriptions.ends_at,
           plans.name AS plan_name,
           (SELECT email FROM users WHERE users.tenant_id = tenants.id ORDER BY users.id ASC LIMIT 1) AS owner_email,
           (SELECT COUNT(*) FROM invoices WHERE invoices.tenant_id = tenants.id) AS invoice_count,
           (SELECT COUNT(*) FROM customers WHERE customers.tenant_id = tenants.id) AS customer_count
    FROM tenants
    LEFT JOIN subscriptions ON subscriptions.id = (
        SELECT s.id FROM subscriptions s WHERE s.tenant_id = tenants.id ORDER BY s.id DESC LIMIT 1
    )
    LEFT JOIN plans ON plans.id = subscriptions.plan_id
    LEFT JOIN users ON users.tenant_id = tenants.id AND users.id = (
        SELECT MIN(u.id) FROM users u WHERE u.tenant_id = tenants.id
    )
    WHERE $where
    ORDER BY tenants.id DESC
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll();
?>
<div class="page-head">
    <form class="search" method="get">
        <input type="hidden" name="page" value="tenants">
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search company, slug, or owner email">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    <a class="btn" href="<?php echo e(url('tenant')); ?>">+ New tenant</a>
</div>

<div class="card">
    <p class="muted">Each tenant is one subscriber. You assign a plan and an end date. They sign in with their own email and only see their invoices.</p>
    <?php if (!$tenants): ?>
        <p class="empty">No tenants yet.</p>
    <?php else: ?>
        <div class="data-grid data-grid-customers">
            <div class="data-grid-head" aria-hidden="true">
                <span>Company</span>
                <span>Owner</span>
                <span>Plan</span>
                <span>Status</span>
                <span>Ends</span>
            </div>
            <?php foreach ($tenants as $row): ?>
                <article class="data-grid-row">
                    <a class="cell-title" href="<?php echo e(url('tenant', array('id' => $row['id']))); ?>"><?php echo e($row['name']); ?></a>
                    <span><?php echo e($row['owner_email']); ?></span>
                    <span><?php echo e($row['plan_name'] ? $row['plan_name'] : '—'); ?></span>
                    <span>
                        <span class="badge <?php echo $row['status'] === 'active' && in_array($row['sub_status'], array('trial', 'active'), true) ? 'badge-paid' : 'badge-overdue'; ?>">
                            <?php echo e($row['status'] === 'active' ? subscription_status_label($row['sub_status'] ? $row['sub_status'] : 'expired') : ucfirst($row['status'])); ?>
                        </span>
                    </span>
                    <span><?php echo e($row['ends_at'] ? $row['ends_at'] : '—'); ?></span>
                    <div class="cell-actions">
                        <a class="icon-btn" href="<?php echo e(url('tenant', array('id' => $row['id']))); ?>" title="Edit" aria-label="Edit tenant"><?php echo action_icon('edit'); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
