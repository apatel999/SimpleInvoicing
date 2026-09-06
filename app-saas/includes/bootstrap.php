<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (!file_exists(__DIR__ . '/../config.php')) {
    http_response_code(500);
    echo 'Missing config.php';
    exit;
}

require __DIR__ . '/../config.php';

if (!defined('SI_DEBUG') || !SI_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/tenant.php';
require __DIR__ . '/invoices.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('si_saas_session');
    session_start();
}

function render_setup_page($message)
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Setup</title>';
    echo '<style>body{font-family:Georgia,serif;background:#f3efe6;color:#1b241f;margin:0;padding:4rem 1.5rem}';
    echo '.box{max-width:36rem;margin:0 auto;background:#fffdf8;border:1px solid #e4ddd0;padding:2rem}';
    echo 'code{background:#f3efe6;padding:.1rem .3rem}</style></head><body><div class="box">';
    echo '<h1>InvoiceHost setup</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<ol><li>Create a new MySQL database (do not reuse the original <code>app</code> database).</li>';
    echo '<li>Edit <code>config.php</code>, then import <code>sql/install.sql</code> in phpMyAdmin.</li>';
    echo '<li>Platform: <code>platform@example.com</code> / <code>admin123</code>. Demo tenant: <code>admin@example.com</code> / <code>admin123</code>.</li></ol>';
    echo '</div></body></html>';
    exit;
}

if (config_needs_setup()) {
    render_setup_page('Database credentials are still the placeholders in config.php.');
}

try {
    db();
} catch (Exception $e) {
    render_setup_page('Could not connect to MySQL. Check config.php. ' . $e->getMessage());
}
