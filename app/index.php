<?php

require __DIR__ . '/includes/bootstrap.php';

$page = query('page', 'dashboard');
if ($page === '') {
    $page = 'dashboard';
}

$routes = array(
    'login' => 'login.php',
    'logout' => 'logout.php',
    'dashboard' => 'dashboard.php',
    'customers' => 'customers.php',
    'customer' => 'customer_form.php',
    'invoices' => 'invoices.php',
    'invoice' => 'invoice_view.php',
    'invoice_form' => 'invoice_form.php',
    'payments' => 'payments.php',
    'settings' => 'settings.php',
    'email_templates' => 'email_templates.php',
    'public_invoice' => 'public_invoice.php',
);

if (!isset($routes[$page])) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

$publicPages = array('login' => true, 'public_invoice' => true);

try {
    if ($page === 'logout') {
        logout_user();
        redirect('login');
    }

    if (empty($publicPages[$page])) {
        require_login();
        mark_overdue_invoices();
    } elseif ($page === 'login' && current_user()) {
        redirect('dashboard');
    }

    $pageFile = __DIR__ . '/pages/' . $routes[$page];
    if (!is_file($pageFile) || filesize($pageFile) < 10) {
        http_response_code(500);
        echo 'pages/' . $routes[$page] . ' is missing or empty on the server. Re-upload it.';
        exit;
    }

    $pageTitle = 'SimpleInvoicing';
    $currentPage = $page;
    ob_start();
    require $pageFile;
    $content = ob_get_clean();

    if (!empty($GLOBALS['skip_layout'])) {
        echo $content;
        exit;
    }

    if (!empty($GLOBALS['auth_layout'])) {
        render_auth($pageTitle, $content);
        exit;
    }

    render_app($pageTitle, $content, $currentPage);
} catch (PDOException $e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    $message = $e->getMessage();
    if (stripos($message, 'exist') !== false) {
        render_setup_page('Connected to MySQL, but tables are missing. Import sql/install.sql in phpMyAdmin.');
    }
    render_setup_page('Database error: ' . $message);
}
