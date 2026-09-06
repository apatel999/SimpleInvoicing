<?php

function set_tenant_context($tenantId)
{
    $GLOBALS['tenant_context_id'] = (int) $tenantId;
    settings_cache(true);
}

function tenant_id()
{
    if (!empty($GLOBALS['tenant_context_id'])) {
        return (int) $GLOBALS['tenant_context_id'];
    }
    $user = current_user();
    if ($user && !empty($user['tenant_id'])) {
        return (int) $user['tenant_id'];
    }
    return 0;
}

function is_platform()
{
    $user = current_user();
    return $user && $user['role'] === 'platform';
}

function require_platform()
{
    require_login();
    if (!is_platform()) {
        flash('error', 'That page is for the platform admin.');
        redirect('dashboard');
    }
}

function require_tenant()
{
    require_login();
    if (is_platform()) {
        redirect('tenants');
    }
    if (tenant_id() <= 0) {
        logout_user();
        redirect('login');
    }
}

function current_subscription()
{
    static $cache = false;
    $tid = tenant_id();
    if ($tid <= 0) {
        return null;
    }
    if ($cache !== false && isset($cache['_tid']) && (int) $cache['_tid'] === $tid) {
        return $cache;
    }
    $sql = 'SELECT subscriptions.*, plans.name AS plan_name, plans.slug AS plan_slug,
                   plans.price, plans.interval, plans.invoice_limit, plans.customer_limit
            FROM subscriptions
            INNER JOIN plans ON plans.id = subscriptions.plan_id
            WHERE subscriptions.tenant_id = ?
            ORDER BY subscriptions.id DESC
            LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute(array($tid));
    $row = $stmt->fetch();
    if ($row) {
        $row['_tid'] = $tid;
        $row['is_usable'] = subscription_is_usable($row);
    }
    $cache = $row ?: null;
    return $cache;
}

function subscription_is_usable($sub)
{
    if (!$sub) {
        return false;
    }
    if (in_array($sub['status'], array('cancelled', 'expired', 'suspended'), true)) {
        return false;
    }
    if (!empty($sub['ends_at']) && $sub['ends_at'] < date('Y-m-d')) {
        return false;
    }
    $tenant = current_tenant();
    if ($tenant && $tenant['status'] !== 'active') {
        return false;
    }
    return in_array($sub['status'], array('trial', 'active'), true);
}

function current_tenant()
{
    $tid = tenant_id();
    if ($tid <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute(array($tid));
    return $stmt->fetch() ?: null;
}

function require_writable_subscription()
{
    $sub = current_subscription();
    if (!subscription_is_usable($sub)) {
        flash('error', 'This account’s subscription is not active. Ask the platform admin to renew it.');
        redirect('dashboard');
    }
}

function plan_limit_reached($kind)
{
    $sub = current_subscription();
    if (!$sub) {
        return true;
    }
    $tid = tenant_id();
    if ($kind === 'invoices') {
        $limit = (int) $sub['invoice_limit'];
        if ($limit <= 0) {
            return false;
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM invoices WHERE tenant_id = ?');
        $stmt->execute(array($tid));
        return (int) $stmt->fetchColumn() >= $limit;
    }
    if ($kind === 'customers') {
        $limit = (int) $sub['customer_limit'];
        if ($limit <= 0) {
            return false;
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM customers WHERE tenant_id = ?');
        $stmt->execute(array($tid));
        return (int) $stmt->fetchColumn() >= $limit;
    }
    return false;
}

function fetch_plans()
{
    return db()->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY price ASC, id ASC')->fetchAll();
}

function slugify_tenant($name)
{
    $slug = strtolower(trim((string) $name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'tenant';
    }
    $base = substr($slug, 0, 60);
    $candidate = $base;
    $n = 2;
    while (true) {
        $stmt = db()->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
        $stmt->execute(array($candidate));
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $base . '-' . $n;
        $n++;
    }
}

function default_setting_rows()
{
    return array(
        'company_name' => 'My Company',
        'company_email' => '',
        'company_phone' => '',
        'company_website' => '',
        'company_address' => '',
        'company_city' => '',
        'company_state' => '',
        'company_postal_code' => '',
        'company_country' => '',
        'tax_id' => '',
        'logo_path' => '',
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'tax_rate' => '0',
        'invoice_prefix' => 'INV-',
        'next_invoice_number' => '1',
        'invoice_number_padding' => '4',
        'invoice_due_days' => '14',
        'default_notes' => 'Thank you for your business.',
        'default_terms' => 'Payment is due within 14 days of the invoice date.',
        'date_format' => 'Y-m-d',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'email_bcc' => '',
    );
}

function default_email_templates()
{
    return array(
        array(
            'slug' => 'invoice_sent',
            'name' => 'Invoice sent',
            'subject' => 'Invoice {{invoice_number}} from {{company_name}}',
            'body' => '<p>Hello {{customer_name}},</p>
<p>Please find invoice <strong>{{invoice_number}}</strong> dated {{issue_date}}.</p>
<p>Amount due: <strong>{{invoice_total}}</strong><br>
Due date: {{due_date}}</p>
<p>You can view the invoice here: {{invoice_link}}</p>
<p>Thank you,<br>{{company_name}}</p>',
        ),
        array(
            'slug' => 'payment_received',
            'name' => 'Payment received',
            'subject' => 'Payment received for invoice {{invoice_number}}',
            'body' => '<p>Hello {{customer_name}},</p>
<p>We have received a payment of <strong>{{payment_amount}}</strong> for invoice <strong>{{invoice_number}}</strong>.</p>
<p>Remaining balance: {{balance_due}}</p>
<p>Thank you,<br>{{company_name}}</p>',
        ),
        array(
            'slug' => 'invoice_reminder',
            'name' => 'Invoice reminder',
            'subject' => 'Reminder: invoice {{invoice_number}} is due on {{due_date}}',
            'body' => '<p>Hello {{customer_name}},</p>
<p>This is a friendly reminder that invoice <strong>{{invoice_number}}</strong> is due on {{due_date}}.</p>
<p>Amount due: <strong>{{balance_due}}</strong></p>
<p>View invoice: {{invoice_link}}</p>
<p>Thank you,<br>{{company_name}}</p>',
        ),
        array(
            'slug' => 'invoice_overdue',
            'name' => 'Overdue invoice',
            'subject' => 'Overdue: invoice {{invoice_number}}',
            'body' => '<p>Hello {{customer_name}},</p>
<p>Invoice <strong>{{invoice_number}}</strong> is now overdue. The original due date was {{due_date}}.</p>
<p>Outstanding balance: <strong>{{balance_due}}</strong></p>
<p>Please arrange payment at your earliest convenience.</p>
<p>{{company_name}}</p>',
        ),
    );
}

function seed_tenant_defaults($tenantId, $companyName = 'My Company')
{
    $tid = (int) $tenantId;
    $rows = default_setting_rows();
    $rows['company_name'] = $companyName;
    $ins = db()->prepare('INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?,?,?)');
    foreach ($rows as $key => $value) {
        $ins->execute(array($tid, $key, $value));
    }
    $tpl = db()->prepare('INSERT INTO email_templates (tenant_id, slug, name, subject, body, is_active) VALUES (?,?,?,?,?,1)');
    foreach (default_email_templates() as $row) {
        $tpl->execute(array($tid, $row['slug'], $row['name'], $row['subject'], $row['body']));
    }
}

function subscription_status_label($status)
{
    $map = array(
        'trial' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    );
    return isset($map[$status]) ? $map[$status] : ucfirst($status);
}

function expire_due_subscriptions()
{
    db()->exec("
        UPDATE subscriptions
        SET status = 'expired'
        WHERE ends_at IS NOT NULL
          AND ends_at < CURDATE()
          AND status IN ('trial', 'active')
    ");
}
