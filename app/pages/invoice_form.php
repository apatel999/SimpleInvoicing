<?php

$currentPage = 'invoices';
$id = (int) query('id');
$user = current_user();
$dueDays = max(0, (int) setting('invoice_due_days', '14'));
$defaultTax = setting('tax_rate', '0');
$currency = setting('currency_symbol', '$');

$invoice = array(
    'customer_id' => (int) query('customer_id'),
    'status' => 'draft',
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+' . $dueDays . ' days')),
    'discount_type' => 'fixed',
    'discount_value' => '0',
    'tax_rate' => $defaultTax,
    'notes' => setting('default_notes'),
    'terms' => setting('default_terms'),
    'invoice_number' => peek_invoice_number(),
);
$items = array(
    array('description' => '', 'quantity' => '1', 'unit_price' => '0.00'),
);
$found = null;

if ($id) {
    $found = fetch_invoice($id);
    if (!$found) {
        flash('error', 'Invoice not found.');
        redirect('invoices');
    }
    if ($found['status'] === 'cancelled') {
        flash('error', 'Cancelled invoices cannot be edited.');
        redirect('invoice', array('id' => $id));
    }
    $invoice = $found;
    $loaded = fetch_invoice_items($id);
    if ($loaded) {
        $items = $loaded;
    }
}

$customerMode = ((int) $invoice['customer_id'] > 0) ? 'existing' : 'new';
$newCustomer = array(
    'name' => '',
    'company' => '',
    'email' => '',
    'phone' => '',
);

$GLOBALS['page_class'] = 'page-invoice';
$pageTitle = $id ? 'Edit Invoice' : 'New Invoice';
$GLOBALS['hide_page_title'] = true;
$GLOBALS['breadcrumbs'] = array(
    array('Home', url('dashboard')),
    array('Invoices', url('invoices')),
    array($pageTitle, ''),
);
$error = '';

$customers = db()->query('SELECT id, name, company, email, phone FROM customers WHERE is_active = 1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postedItems = array();
    $descriptions = isset($_POST['items']['description']) ? $_POST['items']['description'] : array();
    $quantities = isset($_POST['items']['quantity']) ? $_POST['items']['quantity'] : array();
    $prices = isset($_POST['items']['unit_price']) ? $_POST['items']['unit_price'] : array();
    foreach ($descriptions as $i => $desc) {
        $postedItems[] = array(
            'description' => $desc,
            'quantity' => isset($quantities[$i]) ? $quantities[$i] : 0,
            'unit_price' => isset($prices[$i]) ? $prices[$i] : 0,
        );
    }
    $items = $postedItems ?: $items;
    $invoice['customer_id'] = (int) post('customer_id');
    $invoice['status'] = $found ? $found['status'] : 'draft';
    $invoice['issue_date'] = post('issue_date');
    $invoice['due_date'] = post('due_date');
    $invoice['discount_type'] = post('discount_type', 'fixed');
    $invoice['discount_value'] = post('discount_value', '0');
    $invoice['tax_rate'] = post('add_tax') === '1' ? post('tax_rate', '0') : '0';
    $invoice['notes'] = post('notes');
    $invoice['terms'] = post('terms');
    $customerMode = post('customer_mode', 'existing');
    $newCustomer = array(
        'name' => post('new_name'),
        'company' => post('new_company'),
        'email' => post('new_email'),
        'phone' => post('new_phone'),
    );

    if (!in_array($invoice['status'], array('draft', 'sent'), true)) {
        $invoice['status'] = 'draft';
    }
    if ($id && $found && in_array($found['status'], array('partial', 'paid', 'overdue', 'viewed', 'sent'), true) && $invoice['status'] === 'draft') {
        $invoice['status'] = $found['status'] === 'draft' ? 'draft' : 'sent';
    }

    if ($customerMode === 'new') {
        $phoneError = null;
        $phone = normalize_phone($newCustomer['phone'], $phoneError);
        $newCustomer['phone'] = $phone;
        if (trim($newCustomer['name']) === '') {
            $error = 'Customer name is required.';
        } elseif ($phoneError) {
            $error = $phoneError;
        } else {
            db()->prepare('INSERT INTO customers (name, company, email, phone, is_active) VALUES (?,?,?,?,1)')
                ->execute(array($newCustomer['name'], $newCustomer['company'], $newCustomer['email'], $phone));
            $invoice['customer_id'] = (int) db()->lastInsertId();
            $customerMode = 'existing';
            $customers[] = array(
                'id' => $invoice['customer_id'],
                'name' => $newCustomer['name'],
                'company' => $newCustomer['company'],
                'email' => $newCustomer['email'],
                'phone' => $phone,
            );
        }
    }

    if ($error === '' && $invoice['customer_id'] <= 0) {
        $error = 'Choose a customer.';
    } elseif ($error === '' && ($invoice['issue_date'] === '' || $invoice['due_date'] === '')) {
        $error = 'Invoice date and due date are required.';
    } elseif ($error === '') {
        try {
            $savedId = save_invoice($id ?: null, array(
                'customer_id' => $invoice['customer_id'],
                'created_by' => $user['id'],
                'status' => $invoice['status'],
                'issue_date' => $invoice['issue_date'],
                'due_date' => $invoice['due_date'],
                'discount_type' => $invoice['discount_type'],
                'discount_value' => $invoice['discount_value'],
                'tax_rate' => $invoice['tax_rate'],
                'notes' => $invoice['notes'],
                'terms' => $invoice['terms'],
            ), $items);
            flash('success', $id ? 'Invoice updated.' : 'Invoice created.');
            $params = array('id' => $savedId);
            if (post('after_save') === 'ask_send') {
                $params['ask_send'] = 1;
            }
            redirect('invoice', $params);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Exception $e) {
            $error = 'Could not save the invoice.';
        }
    }
}

$selectedCustomer = null;
foreach ($customers as $customer) {
    if ((int) $customer['id'] === (int) $invoice['customer_id']) {
        $selectedCustomer = $customer;
        break;
    }
}
$showTax = (float) $invoice['tax_rate'] > 0;
$displayNumber = $id ? $invoice['invoice_number'] : peek_invoice_number();
$detailOrDash = function ($value) {
    $text = trim((string) $value);
    return $text === '' ? '—' : $text;
};
?>
<form method="post" class="invoice-builder" data-currency="<?php echo e($currency); ?>" id="invoice-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="customer_id" id="customer_id" value="<?php echo (int) $invoice['customer_id']; ?>">
    <input type="hidden" name="customer_mode" id="customer_mode" value="<?php echo e($customerMode); ?>">
    <input type="hidden" name="add_tax" id="add_tax" value="<?php echo $showTax ? '1' : '0'; ?>">

    <?php if ($error): ?>
        <div class="alert alert-err"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="inv-top">
        <div class="inv-card inv-customer" id="customer-card">
            <button type="button" class="inv-customer-trigger" id="customer-trigger">
                <span class="inv-avatar" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M5 19c1.2-3.2 3.7-5 7-5s5.8 1.8 7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </span>
                <span class="inv-customer-copy">
                    <strong id="customer-label">
                        <?php echo $selectedCustomer ? e($selectedCustomer['name']) : 'New Customer'; ?>
                    </strong>
                    <small id="customer-sub">
                        <?php
                        if ($selectedCustomer) {
                            echo e(trim($selectedCustomer['company'] . ($selectedCustomer['email'] ? ' · ' . $selectedCustomer['email'] : '')));
                        } else {
                            echo 'Select or create a customer';
                        }
                        ?>
                    </small>
                </span>
                <span class="req">*</span>
                <span class="inv-caret" aria-hidden="true"></span>
            </button>

            <div class="inv-picker" id="customer-panel" hidden>
                <input type="search" id="customer-search" class="inv-search" placeholder="Search customers" autocomplete="off">
                <button type="button" class="inv-picker-new" id="choose-new-customer">+ New customer</button>
                <div class="inv-picker-list" id="customer-list">
                    <?php foreach ($customers as $customer): ?>
                        <button type="button" class="inv-picker-item"
                                data-id="<?php echo (int) $customer['id']; ?>"
                                data-name="<?php echo e($customer['name']); ?>"
                                data-company="<?php echo e($customer['company']); ?>"
                                data-email="<?php echo e($customer['email']); ?>"
                                data-phone="<?php echo e($customer['phone']); ?>">
                            <strong><?php echo e($customer['name']); ?></strong>
                            <small><?php echo e(trim($customer['company'] . ($customer['email'] ? ' · ' . $customer['email'] : ''))); ?></small>
                        </button>
                    <?php endforeach; ?>
                    <?php if (!$customers): ?>
                        <p class="inv-picker-empty">No customers yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="inv-new-customer" id="selected-customer-fields"<?php echo ($customerMode === 'existing' && $selectedCustomer) ? '' : ' hidden'; ?>>
                <div class="inv-new-grid inv-customer-details">
                    <p>
                        <span class="inv-detail-label">Name</span>
                        <span class="inv-detail-value" id="view-customer-name"><?php echo e($selectedCustomer ? $detailOrDash($selectedCustomer['name']) : '—'); ?></span>
                    </p>
                    <p>
                        <span class="inv-detail-label">Company</span>
                        <span class="inv-detail-value" id="view-customer-company"><?php echo e($selectedCustomer ? $detailOrDash($selectedCustomer['company']) : '—'); ?></span>
                    </p>
                    <p>
                        <span class="inv-detail-label">Email</span>
                        <span class="inv-detail-value" id="view-customer-email"><?php echo e($selectedCustomer ? $detailOrDash($selectedCustomer['email']) : '—'); ?></span>
                    </p>
                    <p>
                        <span class="inv-detail-label">Phone</span>
                        <span class="inv-detail-value" id="view-customer-phone"><?php echo e($selectedCustomer ? $detailOrDash(format_phone($selectedCustomer['phone'])) : '—'); ?></span>
                    </p>
                </div>
            </div>

            <div class="inv-new-customer" id="new-customer-fields"<?php echo $customerMode === 'new' ? '' : ' hidden'; ?>>
                <div class="inv-new-grid">
                    <p>
                        <label for="new_name">Name <span class="req">*</span></label>
                        <input id="new_name" type="text" name="new_name" value="<?php echo e($newCustomer['name']); ?>">
                    </p>
                    <p>
                        <label for="new_company">Company</label>
                        <input id="new_company" type="text" name="new_company" value="<?php echo e($newCustomer['company']); ?>">
                    </p>
                    <p>
                        <label for="new_email">Email</label>
                        <input id="new_email" type="email" name="new_email" value="<?php echo e($newCustomer['email']); ?>">
                    </p>
                    <p>
                        <label for="new_phone">Phone</label>
                        <input id="new_phone" type="tel" name="new_phone" inputmode="numeric" autocomplete="tel"
                               maxlength="14" placeholder="(555) 555-5555 or 1-800-234-1234" data-phone
                               value="<?php echo e(format_phone($newCustomer['phone'])); ?>">
                    </p>
                </div>
            </div>
        </div>

        <div class="inv-card inv-meta">
            <p>
                <label for="issue_date">Invoice Date <span class="req">*</span></label>
                <span class="inv-date">
                    <input id="issue_date" type="date" name="issue_date" required value="<?php echo e($invoice['issue_date']); ?>">
                </span>
            </p>
            <p>
                <label for="due_date">Due Date</label>
                <span class="inv-date">
                    <input id="due_date" type="date" name="due_date" required value="<?php echo e($invoice['due_date']); ?>">
                </span>
            </p>
            <p>
                <label for="invoice_number_display">Invoice Number <span class="req">*</span></label>
                <input id="invoice_number_display" type="text" value="<?php echo e($displayNumber); ?>" readonly>
            </p>
        </div>
    </div>

    <div class="inv-card inv-items-card">
        <table class="inv-items">
            <thead>
                <tr>
                    <th class="inv-col-grip"></th>
                    <th>Items</th>
                    <th class="inv-col-qty">Quantity</th>
                    <th class="inv-col-price">Price</th>
                    <th class="inv-col-amt">Amount</th>
                    <th class="inv-col-remove"></th>
                </tr>
            </thead>
            <tbody id="item-rows">
            <?php foreach ($items as $item): ?>
                <tr class="inv-item">
                    <td class="inv-col-grip">
                        <span class="inv-grip" title="Item" aria-hidden="true">
                            <span></span><span></span><span></span><span></span><span></span><span></span>
                        </span>
                    </td>
                    <td class="inv-col-desc" data-label="Item">
                        <input type="text" name="items[description][]" placeholder="Type item description (optional)" value="<?php echo e($item['description']); ?>">
                    </td>
                    <td class="inv-col-qty" data-label="Quantity">
                        <input type="number" name="items[quantity][]" min="0" step="0.01" value="<?php echo e($item['quantity']); ?>">
                    </td>
                    <td class="inv-col-price" data-label="Price">
                        <span class="inv-price">
                            <em><?php echo e($currency); ?></em>
                            <input type="number" name="items[unit_price][]" min="0" step="0.01" value="<?php echo e($item['unit_price']); ?>">
                        </span>
                    </td>
                    <td class="num inv-col-amt" data-label="Amount"><span class="line-total"><?php echo e($currency); ?> 0.00</span></td>
                    <td class="inv-col-remove"><button type="button" class="inv-remove remove-item" title="Remove item">&times;</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="inv-add" id="add-item">+ Add New Item</button>
    </div>

    <div class="inv-bottom">
        <div class="inv-card inv-notes">
            <div class="inv-notes-head">
                <h2>Notes</h2>
                <button type="button" class="inv-link" id="insert-note" data-note="<?php echo e(setting('default_notes')); ?>">+ Insert Note</button>
            </div>
            <textarea id="notes" name="notes" placeholder="Visible to your customer"><?php echo e($invoice['notes']); ?></textarea>
            <div class="inv-terms">
                <h2>Terms</h2>
                <textarea id="terms" name="terms"><?php echo e($invoice['terms']); ?></textarea>
            </div>
        </div>

        <div class="inv-card inv-summary">
            <div class="inv-sum-row">
                <span>Sub Total</span>
                <strong id="live-subtotal"><?php echo e($currency); ?> 0.00</strong>
            </div>
            <div class="inv-sum-row inv-discount">
                <span>Discount</span>
                <span class="inv-discount-controls">
                    <input id="discount_value" type="number" min="0" step="0.01" name="discount_value" value="<?php echo e($invoice['discount_value']); ?>">
                    <select id="discount_type" name="discount_type">
                        <option value="fixed"<?php echo $invoice['discount_type'] === 'fixed' ? ' selected' : ''; ?>><?php echo e($currency); ?></option>
                        <option value="percent"<?php echo $invoice['discount_type'] === 'percent' ? ' selected' : ''; ?>>%</option>
                    </select>
                </span>
            </div>
            <div class="inv-sum-row" id="tax-row"<?php echo $showTax ? '' : ' hidden'; ?>>
                <span>Tax (%)</span>
                <input id="tax_rate" type="number" min="0" step="0.001" name="tax_rate" value="<?php echo e($invoice['tax_rate']); ?>">
            </div>
            <button type="button" class="inv-link" id="add-tax-btn"<?php echo $showTax ? ' hidden' : ''; ?>>+ Add Tax</button>
            <div class="inv-sum-row muted" id="tax-amount-row"<?php echo $showTax ? '' : ' hidden'; ?>>
                <span>Tax</span>
                <span id="live-tax"><?php echo e($currency); ?> 0.00</span>
            </div>
            <div class="inv-sum-row inv-total">
                <span>Total Amount</span>
                <strong id="live-total"><?php echo e($currency); ?> 0.00</strong>
            </div>
            <span id="live-discount" hidden>0.00</span>
        </div>
    </div>

    <div class="inv-actions">
        <a class="btn btn-ghost" href="<?php echo e($id ? url('invoice', array('id' => $id)) : url('invoices')); ?>">Cancel</a>
        <div class="inv-actions-right">
            <button class="btn btn-ghost" type="submit" name="after_save" value="stay">Save Draft</button>
            <button class="btn inv-primary" type="submit" name="after_save" value="ask_send">Save Invoice</button>
        </div>
    </div>
</form>
