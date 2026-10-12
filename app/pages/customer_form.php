<?php

$currentPage = 'customers';
$id = (int) query('id');
$customer = array(
    'customer_code' => '',
    'name' => '',
    'company' => '',
    'email' => '',
    'phone' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'country' => '',
    'tax_number' => '',
    'notes' => '',
    'is_active' => 1,
);

if ($id) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute(array($id));
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Customer not found.');
        redirect('customers');
    }
    $customer = $found;
}

$pageTitle = $id ? 'Edit customer' : 'New customer';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = array(
        'customer_code' => post('customer_code') === '' ? null : post('customer_code'),
        'name' => post('name'),
        'company' => post('company'),
        'email' => post('email'),
        'phone' => normalize_phone(post('phone'), $error),
        'address_line1' => post('address_line1'),
        'address_line2' => post('address_line2'),
        'city' => post('city'),
        'state' => post('state'),
        'postal_code' => post('postal_code'),
        'country' => post('country'),
        'tax_number' => post('tax_number'),
        'notes' => post('notes'),
        'is_active' => post('is_active') === '1' ? 1 : 0,
    );
    $customer = array_merge($customer, $data);
    $customer['is_active'] = $data['is_active'];

    if ($data['name'] === '') {
        $error = 'Name is required.';
    } elseif ($error === '') {
        try {
            if ($id) {
                db()->prepare('UPDATE customers SET
                    customer_code=?, name=?, company=?, email=?, phone=?, address_line1=?, address_line2=?,
                    city=?, state=?, postal_code=?, country=?, tax_number=?, notes=?, is_active=?
                    WHERE id=?')->execute(array(
                    $data['customer_code'], $data['name'], $data['company'], $data['email'], $data['phone'],
                    $data['address_line1'], $data['address_line2'], $data['city'], $data['state'],
                    $data['postal_code'], $data['country'], $data['tax_number'], $data['notes'],
                    $data['is_active'], $id
                ));
                flash('success', 'Customer updated.');
            } else {
                db()->prepare('INSERT INTO customers (
                    customer_code, name, company, email, phone, address_line1, address_line2,
                    city, state, postal_code, country, tax_number, notes, is_active
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array(
                    $data['customer_code'], $data['name'], $data['company'], $data['email'], $data['phone'],
                    $data['address_line1'], $data['address_line2'], $data['city'], $data['state'],
                    $data['postal_code'], $data['country'], $data['tax_number'], $data['notes'],
                    $data['is_active']
                ));
                flash('success', 'Customer created.');
            }
            redirect('customers');
        } catch (Exception $e) {
            $error = 'Could not save customer. The customer code may already be in use.';
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
        <div class="form-grid">
            <p>
                <label for="name">Name</label>
                <input id="name" type="text" name="name" required value="<?php echo e($customer['name']); ?>">
            </p>
            <p>
                <label for="company">Company</label>
                <input id="company" type="text" name="company" value="<?php echo e($customer['company']); ?>">
            </p>
            <p>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo e($customer['email']); ?>">
            </p>
            <p>
                <label for="phone">Phone</label>
                <input id="phone" type="tel" name="phone" inputmode="numeric" autocomplete="tel"
                       maxlength="14" placeholder="(555) 555-5555 or 1-800-234-1234" data-phone
                       value="<?php echo e(format_phone($customer['phone'])); ?>">
            </p>
            <p>
                <label for="tax_number">Tax / VAT number</label>
                <input id="tax_number" type="text" name="tax_number" value="<?php echo e($customer['tax_number']); ?>">
            </p>
            <input type="hidden" name="customer_code" value="<?php echo e($customer['customer_code']); ?>">
            <p>
                <label for="address_line1">Address</label>
                <input id="address_line1" type="text" name="address_line1" value="<?php echo e($customer['address_line1']); ?>">
            </p>
            <p>
                <label for="address_line2">Address line 2</label>
                <input id="address_line2" type="text" name="address_line2" value="<?php echo e($customer['address_line2']); ?>">
            </p>
            <p>
                <label for="city">City</label>
                <input id="city" type="text" name="city" value="<?php echo e($customer['city']); ?>">
            </p>
            <p>
                <label for="state">State / region</label>
                <input id="state" type="text" name="state" value="<?php echo e($customer['state']); ?>">
            </p>
            <p>
                <label for="postal_code">Postal code</label>
                <input id="postal_code" type="text" name="postal_code" value="<?php echo e($customer['postal_code']); ?>">
            </p>
            <p>
                <label for="country">Country</label>
                <input id="country" type="text" name="country" value="<?php echo e($customer['country']); ?>">
            </p>
            <p class="full">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes"><?php echo e($customer['notes']); ?></textarea>
            </p>
            <p>
                <label for="is_active">Status</label>
                <select id="is_active" name="is_active">
                    <option value="1"<?php echo (int) $customer['is_active'] ? ' selected' : ''; ?>>Active</option>
                    <option value="0"<?php echo !(int) $customer['is_active'] ? ' selected' : ''; ?>>Inactive</option>
                </select>
            </p>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Save customer</button>
            <a class="btn btn-ghost" href="<?php echo e(url('customers')); ?>">Cancel</a>
        </div>
    </form>
</div>
