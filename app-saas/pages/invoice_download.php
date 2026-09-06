<?php

$GLOBALS['skip_layout'] = true;
$id = (int) query('id');
$invoice = fetch_invoice($id);
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('invoices');
}

$items = fetch_invoice_items($id);
$balance = invoice_balance($invoice);
output_invoice_pdf($invoice, $items, $balance);
