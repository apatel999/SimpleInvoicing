<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "PHP " . PHP_VERSION . "\n";
echo "PDO: " . (class_exists('PDO') ? 'yes' : 'NO') . "\n";
echo "pdo_mysql: " . (in_array('mysql', PDO::getAvailableDrivers(), true) ? 'yes' : 'NO') . "\n\n";

$config = __DIR__ . '/config.php';
if (!is_file($config)) {
    echo "config.php is missing.\n";
    exit;
}

require $config;
echo "DB_HOST=" . DB_HOST . "\n";
echo "DB_NAME=" . DB_NAME . "\n";
echo "DB_USER=" . DB_USER . "\n";
echo "APP_URL=" . (APP_URL === '' ? '(empty)' : APP_URL) . "\n\n";

if (DB_NAME === 'your_database' || DB_USER === 'your_username') {
    echo "config.php still has placeholder database values.\n";
    exit;
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    echo "Database: connected\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    echo "Tables: " . count($tables) . "\n";
} catch (Exception $e) {
    echo "Database error:\n" . $e->getMessage() . "\n";
}

$expected = array(
    'index.php',
    'includes/bootstrap.php', 'includes/db.php', 'includes/helpers.php',
    'includes/layout.php', 'includes/auth.php', 'includes/invoices.php', 'includes/mailer.php',
    'pages/login.php', 'pages/dashboard.php', 'pages/customers.php', 'pages/customer_form.php',
    'pages/invoices.php', 'pages/invoice_form.php', 'pages/invoice_view.php',
    'pages/payments.php', 'pages/settings.php', 'pages/email_templates.php', 'pages/public_invoice.php',
    'assets/css/app.css', 'assets/js/app.js',
);

echo "\nFiles (bytes):\n";
foreach ($expected as $relative) {
    $full = __DIR__ . '/' . $relative;
    if (!is_file($full)) {
        echo "  MISSING  " . $relative . "\n";
        continue;
    }
    $size = filesize($full);
    echo ($size < 10 ? '  EMPTY    ' : '  ok       ') . str_pad(number_format($size), 9, ' ', STR_PAD_LEFT) . '  ' . $relative . "\n";
}
