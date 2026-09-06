<?php

$pageTitle = 'Email templates';
$currentPage = 'email_templates';
$id = (int) query('id');

$tplList = db()->prepare('SELECT id, slug, name, subject, is_active, updated_at FROM email_templates WHERE tenant_id = ? ORDER BY name');
$tplList->execute(array(tenant_id()));
$templates = $tplList->fetchAll();
$template = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM email_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute(array($id, tenant_id()));
    $template = $stmt->fetch();
    if (!$template) {
        flash('error', 'Template not found.');
        redirect('email_templates');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) post('id');
    $stmt = db()->prepare('SELECT id FROM email_templates WHERE id = ? AND tenant_id = ?');
    $stmt->execute(array($id, tenant_id()));
    if (!$stmt->fetch()) {
        flash('error', 'Template not found.');
        redirect('email_templates');
    }
    db()->prepare('UPDATE email_templates SET name = ?, subject = ?, body = ?, is_active = ? WHERE id = ? AND tenant_id = ?')
        ->execute(array(
            post('name'),
            post('subject'),
            isset($_POST['body']) ? $_POST['body'] : '',
            post('is_active') === '1' ? 1 : 0,
            $id,
            tenant_id(),
        ));
    flash('success', 'Template saved.');
    redirect('email_templates', array('id' => $id));
}
?>
<div class="split">
    <div class="card">
        <h2>Templates</h2>
        <div class="list-cards">
            <?php foreach ($templates as $row): ?>
                <article class="list-card">
                    <a class="list-card-top" href="<?php echo e(url('email_templates', array('id' => $row['id']))); ?>">
                        <span class="list-card-copy">
                            <strong class="list-card-title"><?php echo e($row['name']); ?></strong>
                            <span class="list-card-sub"><?php echo e($row['slug']); ?></span>
                        </span>
                        <span class="list-card-end">
                            <span class="badge <?php echo (int) $row['is_active'] ? 'badge-paid' : 'badge-cancelled'; ?>">
                                <?php echo (int) $row['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="muted">Placeholders: {{company_name}} {{customer_name}} {{invoice_number}} {{invoice_total}} {{issue_date}} {{due_date}} {{payment_amount}} {{balance_due}} {{invoice_link}} {{invoice_html}}. The HTML invoice is added automatically if {{invoice_html}} is omitted.</p>
    </div>

    <div class="card">
        <?php if (!$template): ?>
            <p class="empty">Select a template to edit.</p>
        <?php else: ?>
            <h2><?php echo e($template['name']); ?></h2>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                <p>
                    <label for="name">Name</label>
                    <input id="name" name="name" required value="<?php echo e($template['name']); ?>">
                </p>
                <p>
                    <label for="subject">Subject</label>
                    <input id="subject" name="subject" required value="<?php echo e($template['subject']); ?>">
                </p>
                <p>
                    <label for="body">HTML body</label>
                    <textarea id="body" name="body" style="min-height:16rem"><?php echo e($template['body']); ?></textarea>
                </p>
                <p>
                    <label for="is_active">Status</label>
                    <select id="is_active" name="is_active">
                        <option value="1"<?php echo (int) $template['is_active'] ? ' selected' : ''; ?>>Active</option>
                        <option value="0"<?php echo !(int) $template['is_active'] ? ' selected' : ''; ?>>Inactive</option>
                    </select>
                </p>
                <div class="form-actions">
                    <button class="btn" type="submit">Save template</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
