<?php

$GLOBALS['skip_layout'] = true;
$id = (int) query('id');
$token = query('token');
$invoice = fetch_invoice($id);

if (!$invoice || strlen($token) !== 32 || !hash_equals(invoice_public_token($id), $token) || $invoice['status'] === 'cancelled' || $invoice['status'] === 'draft') {
    http_response_code(404);
    echo 'Invoice not found.';
    return;
}

if ($invoice['status'] === 'sent') {
    db()->prepare("UPDATE invoices SET status = 'viewed' WHERE id = ? AND status = 'sent'")->execute(array($id));
    $invoice['status'] = 'viewed';
}

$items = fetch_invoice_items($id);
$balance = invoice_balance($invoice);
$company = setting('company_name', 'My Company');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($invoice['invoice_number']); ?> · <?php echo e($company); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(asset('css/app.css')); ?>">
</head>
<body>
<div class="public-wrap">
    <p class="muted no-print"><?php echo e($company); ?></p>
    <?php echo render_invoice_paper($invoice, $items, $balance, false); ?>
    <p class="no-print"><button class="btn" type="button" onclick="window.print()">Print / save PDF</button></p>
</div>
</body>
</html>
