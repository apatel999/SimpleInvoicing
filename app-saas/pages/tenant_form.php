<?php

$currentPage = 'tenants';
$id = (int) query('id');
$plans = fetch_plans();
$tenant = array(
    'name' => '',
    'status' => 'active',
    'notes' => '',
);
$sub = array(
    'plan_id' => $plans ? $plans[0]['id'] : 0,
    'status' => 'trial',
    'starts_at' => date('Y-m-d'),
    'ends_at' => date('Y-m-d', strtotime('+14 days')),
);
$owner = array(
    'name' => '',
    'email' => '',
);

if ($id) {
    $stmt = db()->prepare('SELECT * FROM tenants WHERE id = ?');
    $stmt->execute(array($id));
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Tenant not found.');
        redirect('tenants');
    }
    $tenant = $found;
    $subStmt = db()->prepare('SELECT * FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1');
    $subStmt->execute(array($id));
    $foundSub = $subStmt->fetch();
    if ($foundSub) {
        $sub = $foundSub;
    }
    $ownStmt = db()->prepare('SELECT id, name, email FROM users WHERE tenant_id = ? ORDER BY id ASC LIMIT 1');
    $ownStmt->execute(array($id));
    $foundOwner = $ownStmt->fetch();
    if ($foundOwner) {
        $owner = $foundOwner;
    }
}

$pageTitle = $id ? 'Edit tenant' : 'New tenant';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $tenant['name'] = post('name');
    $tenant['status'] = post('status', 'active');
    $tenant['notes'] = post('notes');
    $sub['plan_id'] = (int) post('plan_id');
    $sub['status'] = post('sub_status', 'trial');
    $sub['starts_at'] = post('starts_at', date('Y-m-d'));
    $sub['ends_at'] = post('ends_at') === '' ? null : post('ends_at');
    $owner['name'] = post('owner_name');
    $owner['email'] = post('owner_email');
    $password = isset($_POST['owner_password']) ? (string) $_POST['owner_password'] : '';

    if (!in_array($tenant['status'], array('active', 'suspended', 'cancelled'), true)) {
        $tenant['status'] = 'active';
    }
    if (!in_array($sub['status'], array('trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired'), true)) {
        $sub['status'] = 'trial';
    }

    if ($tenant['name'] === '') {
        $error = 'Company name is required.';
    } elseif ($owner['name'] === '' || $owner['email'] === '') {
        $error = 'Owner name and email are required.';
    } elseif (!$id && strlen($password) < 8) {
        $error = 'Owner password must be at least 8 characters.';
    } elseif ($id && $password !== '' && strlen($password) < 8) {
        $error = 'New password must be at least 8 characters.';
    } else {
        $planOk = false;
        foreach ($plans as $plan) {
            if ((int) $plan['id'] === (int) $sub['plan_id']) {
                $planOk = true;
                break;
            }
        }
        if (!$planOk) {
            $error = 'Choose a plan.';
        }
    }

    if ($error === '') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!$id) {
                $slug = slugify_tenant($tenant['name']);
                $pdo->prepare('INSERT INTO tenants (name, slug, status, notes) VALUES (?,?,?,?)')
                    ->execute(array($tenant['name'], $slug, $tenant['status'], $tenant['notes']));
                $id = (int) $pdo->lastInsertId();
                seed_tenant_defaults($id, $tenant['name']);
                $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at) VALUES (?,?,?,?,?)')
                    ->execute(array($id, $sub['plan_id'], $sub['status'], $sub['starts_at'], $sub['ends_at']));
                $pdo->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, is_active) VALUES (?,?,?,?,?,1)')
                    ->execute(array(
                        $id,
                        $owner['name'],
                        $owner['email'],
                        password_hash($password, PASSWORD_BCRYPT),
                        'admin',
                    ));
            } else {
                $pdo->prepare('UPDATE tenants SET name = ?, status = ?, notes = ? WHERE id = ?')
                    ->execute(array($tenant['name'], $tenant['status'], $tenant['notes'], $id));
                $pdo->prepare('UPDATE settings SET setting_value = ? WHERE tenant_id = ? AND setting_key = ?')
                    ->execute(array($tenant['name'], $id, 'company_name'));
                if (!empty($sub['id'])) {
                    $pdo->prepare('UPDATE subscriptions SET plan_id = ?, status = ?, starts_at = ?, ends_at = ? WHERE id = ? AND tenant_id = ?')
                        ->execute(array($sub['plan_id'], $sub['status'], $sub['starts_at'], $sub['ends_at'], $sub['id'], $id));
                } else {
                    $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at) VALUES (?,?,?,?,?)')
                        ->execute(array($id, $sub['plan_id'], $sub['status'], $sub['starts_at'], $sub['ends_at']));
                }
                if (!empty($owner['id'])) {
                    $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ? AND tenant_id = ?')
                        ->execute(array($owner['name'], $owner['email'], $owner['id'], $id));
                    if ($password !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
                            ->execute(array(password_hash($password, PASSWORD_BCRYPT), $owner['id'], $id));
                    }
                }
            }
            $pdo->commit();
            flash('success', post('id') ? 'Tenant updated.' : 'Tenant created. They can sign in with the owner email.');
            redirect('tenants');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save. The owner email may already be in use.';
        }
    }
}
?>
<div class="card">
    <?php if ($error): ?>
        <div class="alert alert-err"><?php echo e($error); ?></div>
    <?php endif; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <h2>Company</h2>
        <div class="form-grid">
            <p>
                <label for="name">Company name</label>
                <input id="name" name="name" required value="<?php echo e($tenant['name']); ?>">
            </p>
            <p>
                <label for="status">Tenant status</label>
                <select id="status" name="status">
                    <option value="active"<?php echo $tenant['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
                    <option value="suspended"<?php echo $tenant['status'] === 'suspended' ? ' selected' : ''; ?>>Suspended</option>
                    <option value="cancelled"<?php echo $tenant['status'] === 'cancelled' ? ' selected' : ''; ?>>Cancelled</option>
                </select>
            </p>
            <p class="full">
                <label for="notes">Internal notes</label>
                <input id="notes" name="notes" value="<?php echo e($tenant['notes']); ?>">
            </p>
        </div>

        <h2>Subscription</h2>
        <div class="form-grid">
            <p>
                <label for="plan_id">Plan</label>
                <select id="plan_id" name="plan_id" required>
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?php echo (int) $plan['id']; ?>"<?php echo (int) $sub['plan_id'] === (int) $plan['id'] ? ' selected' : ''; ?>>
                            <?php echo e($plan['name'] . ' · $' . number_format((float) $plan['price'], 2) . '/' . $plan['interval']); ?>
                            <?php echo (int) $plan['invoice_limit'] === 0 ? ' · unlimited invoices' : ' · ' . (int) $plan['invoice_limit'] . ' invoices'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="sub_status">Subscription status</label>
                <select id="sub_status" name="sub_status">
                    <?php foreach (array('trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired') as $st): ?>
                        <option value="<?php echo e($st); ?>"<?php echo $sub['status'] === $st ? ' selected' : ''; ?>><?php echo e(subscription_status_label($st)); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="starts_at">Starts</label>
                <input id="starts_at" type="date" name="starts_at" required value="<?php echo e($sub['starts_at']); ?>">
            </p>
            <p>
                <label for="ends_at">Ends</label>
                <input id="ends_at" type="date" name="ends_at" value="<?php echo e($sub['ends_at']); ?>">
            </p>
        </div>

        <h2>Owner login</h2>
        <div class="form-grid">
            <p>
                <label for="owner_name">Name</label>
                <input id="owner_name" name="owner_name" required value="<?php echo e($owner['name']); ?>">
            </p>
            <p>
                <label for="owner_email">Email</label>
                <input id="owner_email" type="email" name="owner_email" required value="<?php echo e($owner['email']); ?>">
            </p>
            <p class="full">
                <label for="owner_password"><?php echo $id ? 'New password (blank to keep)' : 'Password'; ?></label>
                <input id="owner_password" type="password" name="owner_password" <?php echo $id ? '' : 'required'; ?> minlength="8">
            </p>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Save tenant</button>
            <a class="btn btn-ghost" href="<?php echo e(url('tenants')); ?>">Cancel</a>
        </div>
    </form>
</div>
