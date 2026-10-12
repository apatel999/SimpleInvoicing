<?php

$pageTitle = 'Settings';
$currentPage = 'settings';
$user = current_user();
$error = '';

$keys = array(
    'company_name', 'company_email', 'company_phone', 'company_website',
    'company_address', 'company_city', 'company_state', 'company_postal_code',
    'company_country', 'tax_id', 'currency_code', 'currency_symbol', 'tax_rate',
    'invoice_prefix', 'next_invoice_number', 'invoice_number_padding', 'invoice_due_days',
    'default_notes', 'default_terms', 'date_format',
    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
    'smtp_from_email', 'smtp_from_name', 'email_bcc',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $section = post('section');

    if ($section === 'account') {
        $name = post('name');
        $email = post('email');
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($name === '' || $email === '') {
            $error = 'Name and email are required.';
        } else {
            try {
                db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute(array($name, $email, $user['id']));
                if ($password !== '') {
                    if (strlen($password) < 8) {
                        $error = 'New password must be at least 8 characters.';
                    } else {
                        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                            ->execute(array(password_hash($password, PASSWORD_BCRYPT), $user['id']));
                    }
                }
                if ($error === '') {
                    flash('success', 'Account updated.');
                    redirect('settings');
                }
            } catch (Exception $e) {
                $error = 'That email is already in use.';
            }
        }
    } else {
        $phoneError = null;
        $phone = normalize_phone(post('company_phone'), $phoneError);
        foreach ($keys as $key) {
            if ($key === 'smtp_password' && post($key) === '') {
                continue;
            }
            if ($key === 'company_phone' && $phoneError) {
                continue;
            }
            $value = $key === 'company_phone' ? $phone : post($key);
            set_setting($key, $value);
        }
        $logoError = handle_company_logo_upload();
        if ($phoneError) {
            flash('error', $phoneError);
        } elseif ($logoError !== '') {
            flash('error', $logoError);
        } else {
            flash('success', 'Settings saved.');
        }
        redirect('settings');
    }
}
?>
<div class="split">
    <form method="post" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="section" value="app">
        <div class="card">
            <h2>Company</h2>
            <div class="form-grid">
                <p>
                    <label for="company_name">Company name</label>
                    <input id="company_name" name="company_name" value="<?php echo e(setting('company_name')); ?>">
                </p>
                <p>
                    <label for="company_email">Email</label>
                    <input id="company_email" type="email" name="company_email" value="<?php echo e(setting('company_email')); ?>">
                </p>
                <p>
                    <label for="company_phone">Phone</label>
                    <input id="company_phone" type="tel" name="company_phone" inputmode="numeric" autocomplete="tel"
                           maxlength="14" placeholder="(555) 555-5555 or 1-800-234-1234" data-phone
                           value="<?php echo e(format_phone(setting('company_phone'))); ?>">
                </p>
                <p>
                    <label for="company_website">Website</label>
                    <input id="company_website" name="company_website" value="<?php echo e(setting('company_website')); ?>">
                </p>
                <p class="full">
                    <label for="company_address">Address</label>
                    <input id="company_address" name="company_address" value="<?php echo e(setting('company_address')); ?>">
                </p>
                <p>
                    <label for="company_city">City</label>
                    <input id="company_city" name="company_city" value="<?php echo e(setting('company_city')); ?>">
                </p>
                <p>
                    <label for="company_state">State / region</label>
                    <input id="company_state" name="company_state" value="<?php echo e(setting('company_state')); ?>">
                </p>
                <p>
                    <label for="company_postal_code">Postal code</label>
                    <input id="company_postal_code" name="company_postal_code" value="<?php echo e(setting('company_postal_code')); ?>">
                </p>
                <p>
                    <label for="company_country">Country</label>
                    <input id="company_country" name="company_country" value="<?php echo e(setting('company_country')); ?>">
                </p>
                <p>
                    <label for="tax_id">Tax ID</label>
                    <input id="tax_id" name="tax_id" value="<?php echo e(setting('tax_id')); ?>">
                </p>
                <p class="full">
                    <label for="logo">Company logo</label>
                    <?php if (company_logo_url() !== ''): ?>
                        <img class="settings-logo-preview" src="<?php echo e(company_logo_url()); ?>" alt="Current logo">
                        <label class="muted"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                    <?php endif; ?>
                    <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                </p>
            </div>
        </div>

        <div class="card">
            <h2>Invoices</h2>
            <div class="form-grid">
                <p>
                    <label for="currency_code">Currency code</label>
                    <input id="currency_code" name="currency_code" maxlength="3" value="<?php echo e(setting('currency_code')); ?>">
                </p>
                <p>
                    <label for="currency_symbol">Currency symbol</label>
                    <input id="currency_symbol" name="currency_symbol" value="<?php echo e(setting('currency_symbol')); ?>">
                </p>
                <p>
                    <label for="tax_rate">Default tax rate (%)</label>
                    <input id="tax_rate" type="number" min="0" step="0.001" name="tax_rate" value="<?php echo e(setting('tax_rate')); ?>">
                </p>
                <p>
                    <label for="invoice_due_days">Default due days</label>
                    <input id="invoice_due_days" type="number" min="0" name="invoice_due_days" value="<?php echo e(setting('invoice_due_days')); ?>">
                </p>
                <p>
                    <label for="invoice_prefix">Invoice prefix</label>
                    <input id="invoice_prefix" name="invoice_prefix" value="<?php echo e(setting('invoice_prefix')); ?>">
                </p>
                <p>
                    <label for="next_invoice_number">Next number</label>
                    <input id="next_invoice_number" type="number" min="1" name="next_invoice_number" value="<?php echo e(setting('next_invoice_number')); ?>">
                </p>
                <p>
                    <label for="invoice_number_padding">Number padding</label>
                    <input id="invoice_number_padding" type="number" min="1" name="invoice_number_padding" value="<?php echo e(setting('invoice_number_padding')); ?>">
                </p>
                <p>
                    <label for="date_format">Date format</label>
                    <input id="date_format" name="date_format" value="<?php echo e(setting('date_format')); ?>">
                </p>
                <p>
                    <label for="default_notes">Default notes</label>
                    <textarea id="default_notes" name="default_notes"><?php echo e(setting('default_notes')); ?></textarea>
                </p>
                <p>
                    <label for="default_terms">Default terms</label>
                    <textarea id="default_terms" name="default_terms"><?php echo e(setting('default_terms')); ?></textarea>
                </p>
            </div>
        </div>

        <div class="card">
            <h2>Email / SMTP</h2>
            <p class="muted" style="margin:0 0 0.5rem">Leave the host blank to use PHP <code>mail()</code>. Many free hosts require SMTP.</p>
            <div class="form-grid">
                <p>
                    <label for="smtp_host">SMTP host</label>
                    <input id="smtp_host" name="smtp_host" value="<?php echo e(setting('smtp_host')); ?>">
                </p>
                <p>
                    <label for="smtp_port">Port</label>
                    <input id="smtp_port" name="smtp_port" value="<?php echo e(setting('smtp_port')); ?>">
                </p>
                <p>
                    <label for="smtp_username">Username</label>
                    <input id="smtp_username" name="smtp_username" value="<?php echo e(setting('smtp_username')); ?>">
                </p>
                <p>
                    <label for="smtp_password">Password</label>
                    <input id="smtp_password" type="password" name="smtp_password" placeholder="Unchanged if left blank">
                </p>
                <p>
                    <label for="smtp_encryption">Encryption</label>
                    <select id="smtp_encryption" name="smtp_encryption">
                        <option value="tls"<?php echo setting('smtp_encryption') === 'tls' ? ' selected' : ''; ?>>TLS</option>
                        <option value="ssl"<?php echo setting('smtp_encryption') === 'ssl' ? ' selected' : ''; ?>>SSL</option>
                        <option value="none"<?php echo setting('smtp_encryption') === 'none' ? ' selected' : ''; ?>>None</option>
                    </select>
                </p>
                <p>
                    <label for="smtp_from_email">From email</label>
                    <input id="smtp_from_email" type="email" name="smtp_from_email" value="<?php echo e(setting('smtp_from_email')); ?>">
                </p>
                <p>
                    <label for="smtp_from_name">From name</label>
                    <input id="smtp_from_name" name="smtp_from_name" value="<?php echo e(setting('smtp_from_name')); ?>">
                </p>
                <p>
                    <label for="email_bcc">Send me a copy (BCC)</label>
                    <input id="email_bcc" type="email" name="email_bcc" placeholder="<?php echo e(setting('company_email')); ?>"
                           value="<?php echo e(setting('email_bcc')); ?>">
                </p>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Save settings</button>
            </div>
        </div>
    </form>

    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="section" value="account">
        <div class="card">
            <h2>Your login</h2>
            <?php if ($error): ?>
                <div class="alert alert-err"><?php echo e($error); ?></div>
            <?php endif; ?>
            <div class="form-stack">
            <p>
                <label for="name">Name</label>
                <input id="name" name="name" required value="<?php echo e($user['name']); ?>">
            </p>
            <p>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required value="<?php echo e($user['email']); ?>">
            </p>
            <p>
                <label for="password">New password</label>
                <input id="password" type="password" name="password" placeholder="Leave blank to keep current">
            </p>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" type="submit">Update account</button>
            </div>
        </div>
    </form>
</div>
