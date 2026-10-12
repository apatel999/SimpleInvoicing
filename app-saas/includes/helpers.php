<?php

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect($page, $params = array())
{
    header('Location: ' . url($page, $params));
    exit;
}

function base_path()
{
    if (APP_URL !== '') {
        $path = parse_url(rtrim(APP_URL, '/'), PHP_URL_PATH);
        return $path === null ? '' : rtrim($path, '/');
    }

    $dir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''));
    $dir = rtrim($dir, '/');
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }
    return $dir;
}

function base_url()
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    return $scheme . '://' . $host . base_path();
}

function url($page, $params = array())
{
    $params['page'] = $page;
    return base_path() . '/index.php?' . http_build_query($params);
}

function absolute_url($page, $params = array())
{
    $params['page'] = $page;
    return base_url() . '/index.php?' . http_build_query($params);
}

function asset($path)
{
    $relative = 'assets/' . ltrim($path, '/');
    $url = base_path() . '/' . $relative;
    $file = dirname(__DIR__) . '/' . $relative;
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }
    return $url;
}

function flash($key, $message = null)
{
    if ($message === null) {
        if (empty($_SESSION['flash'][$key])) {
            return '';
        }
        $value = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $value;
    }

    $_SESSION['flash'][$key] = $message;
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function action_icon($name)
{
    $icons = array(
        'view' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.6 12s3.6-6.4 9.4-6.4S21.4 12 21.4 12 17.8 18.4 12 18.4 2.6 12 2.6 12Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20h4.1L19.1 9a1.6 1.6 0 0 0 0-2.3l-1.8-1.8a1.6 1.6 0 0 0-2.3 0L4 15.9V20Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="m13.6 6.2 4.2 4.2" stroke="currentColor" stroke-width="1.6"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 7h14M9.5 7V5.4A1.4 1.4 0 0 1 10.9 4h2.2a1.4 1.4 0 0 1 1.4 1.4V7M8.2 7l.6 12.1a1.2 1.2 0 0 0 1.2 1.1h4a1.2 1.2 0 0 0 1.2-1.1L16 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'invoice' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3.8h7.2L19 8.6V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.8a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3.8V8.6h5M9 12.2h6M9 15.7h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'download' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v10.5M8.2 11.2 12 15l3.8-3.8M5.5 19.2h13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    );
    return isset($icons[$name]) ? $icons[$name] : '';
}

function csrf_verify()
{
    $token = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if ($token === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        echo 'Invalid security token. Please go back and try again.';
        exit;
    }
}

function settings_cache($reload = false)
{
    static $cache = null;
    static $cachedTenant = -1;
    $tid = function_exists('tenant_id') ? tenant_id() : 0;
    if ($reload || $cache === null || $cachedTenant !== $tid) {
        $cache = array();
        $cachedTenant = $tid;
        if ($tid > 0) {
            $stmt = db()->prepare('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?');
            $stmt->execute(array($tid));
            foreach ($stmt->fetchAll() as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    return $cache;
}

function setting($key, $default = '')
{
    $cache = settings_cache();
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting($key, $value)
{
    $tid = tenant_id();
    if ($tid <= 0) {
        return;
    }
    $sql = 'INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
    db()->prepare($sql)->execute(array($tid, $key, $value));
    settings_cache(true);
}

function company_logo_file()
{
    $path = setting('logo_path');
    if ($path === '') {
        return '';
    }
    $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    return is_file($full) ? $full : '';
}

function company_logo_url()
{
    if (company_logo_file() === '') {
        return '';
    }
    return base_path() . '/' . ltrim(str_replace('\\', '/', setting('logo_path')), '/');
}

function company_logo_absolute_url()
{
    if (company_logo_file() === '') {
        return '';
    }
    return base_url() . '/' . ltrim(str_replace('\\', '/', setting('logo_path')), '/');
}

function company_logo_email_src()
{
    $file = company_logo_file();
    if ($file === '') {
        return '';
    }
    $info = @getimagesize($file);
    $mime = ($info && !empty($info['mime'])) ? $info['mime'] : 'image/png';
    $data = @file_get_contents($file);
    if ($data !== false && $data !== '' && strlen($data) <= 200000) {
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
    return 'cid:company-logo';
}

function handle_company_logo_upload()
{
    $tid = tenant_id();
    $dir = dirname(__DIR__) . '/uploads' . ($tid > 0 ? '/t' . $tid : '');
    $hasFile = !empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name']);

    if (!$hasFile && post('remove_logo') === '1') {
        $old = company_logo_file();
        if ($old !== '') {
            @unlink($old);
        }
        set_setting('logo_path', '');
        return '';
    }

    if (!$hasFile) {
        return '';
    }
    if (!empty($_FILES['logo']['error']) && (int) $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        return 'Could not upload the logo.';
    }
    if ((int) $_FILES['logo']['size'] > 2 * 1024 * 1024) {
        return 'Logo must be 2 MB or smaller.';
    }
    $info = @getimagesize($_FILES['logo']['tmp_name']);
    $mimes = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    );
    if (!$info || empty($info['mime']) || !isset($mimes[$info['mime']])) {
        return 'Logo must be a PNG, JPG, GIF, or WebP image.';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return 'Could not create the uploads folder.';
    }
    foreach (glob($dir . '/logo.*') ?: array() as $old) {
        @unlink($old);
    }
    $path = 'uploads' . ($tid > 0 ? '/t' . $tid : '') . '/logo.' . $mimes[$info['mime']];
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], dirname(__DIR__) . '/' . $path)) {
        return 'Could not save the logo.';
    }
    set_setting('logo_path', $path);
    return '';
}

function money($amount)
{
    $symbol = setting('currency_symbol', '$');
    return $symbol . number_format((float) $amount, 2);
}

function format_date($date)
{
    if ($date === null || $date === '') {
        return '';
    }
    $format = setting('date_format', 'Y-m-d');
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : $date;
}

function phone_digits($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    $max = (isset($digits[0]) && $digits[0] === '1') ? 11 : 10;
    return substr($digits, 0, $max);
}

function format_phone($value)
{
    $digits = phone_digits($value);
    $len = strlen($digits);
    if ($len === 0) {
        return '';
    }
    if ($digits[0] === '1') {
        $out = '1';
        $rest = substr($digits, 1);
        if ($rest === '') {
            return $out;
        }
        $out .= '-' . substr($rest, 0, 3);
        if (strlen($rest) <= 3) {
            return $out;
        }
        $out .= '-' . substr($rest, 3, 3);
        if (strlen($rest) <= 6) {
            return $out;
        }
        return $out . '-' . substr($rest, 6, 4);
    }
    if ($len < 4) {
        return '(' . $digits;
    }
    if ($len < 7) {
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3);
    }
    return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
}

function normalize_phone($value, &$error = null)
{
    $digits = phone_digits($value);
    if ($digits === '') {
        return '';
    }
    $len = strlen($digits);
    $ok = ($digits[0] === '1' && $len === 11) || ($digits[0] !== '1' && $len === 10);
    if (!$ok) {
        $error = 'Phone must be 10 digits, or 11 digits starting with 1.';
        return format_phone($digits);
    }
    return format_phone($digits);
}

function post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function query($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function paginate($total, $perPage = 20)
{
    $page = max(1, (int) query('p', 1));
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    return array(
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'total' => $total,
    );
}

function render_pagination($pageInfo, $pageName, $params = array())
{
    if ($pageInfo['pages'] <= 1) {
        return;
    }
    echo '<nav class="pager">';
    for ($i = 1; $i <= $pageInfo['pages']; $i++) {
        $params['p'] = $i;
        $cls = $i === $pageInfo['page'] ? ' is-active' : '';
        echo '<a class="pager-link' . $cls . '" href="' . e(url($pageName, $params)) . '">' . $i . '</a>';
    }
    echo '</nav>';
}

function format_address($row)
{
    $cityLine = trim(
        (isset($row['city']) ? $row['city'] : '')
        . ((isset($row['state']) && $row['state'] !== '') ? ', ' . $row['state'] : '')
        . ' '
        . (isset($row['postal_code']) ? $row['postal_code'] : '')
    );
    $parts = array_filter(array(
        isset($row['address_line1']) ? $row['address_line1'] : '',
        isset($row['address_line2']) ? $row['address_line2'] : '',
        $cityLine,
        isset($row['country']) ? $row['country'] : '',
    ));
    return implode("\n", $parts);
}

function company_contact_blocks()
{
    $cityLine = trim(
        setting('company_city')
        . (setting('company_state') !== '' ? ', ' . setting('company_state') : '')
        . ' ' . setting('company_postal_code')
    );
    $phone = setting('company_phone');
    $website = preg_replace('#^https?://#i', '', setting('company_website'));

    $clean = function ($lines) {
        $out = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    };

    return array(
        'address' => $clean(array(
            setting('company_address'),
            $cityLine,
            setting('company_country'),
        )),
        'contact' => $clean(array(
            setting('company_email'),
            $phone === '' ? '' : format_phone($phone),
            $website,
        )),
    );
}

function status_label($status)
{
    $map = array(
        'draft' => 'Draft',
        'sent' => 'Sent',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
    );
    return isset($map[$status]) ? $map[$status] : ucfirst($status);
}

function invoice_public_token($id)
{
    return substr(hash_hmac('sha256', 'invoice:' . (int) $id, APP_SECRET), 0, 32);
}

function invoice_public_url($id)
{
    return absolute_url('public_invoice', array(
        'id' => (int) $id,
        'token' => invoice_public_token($id),
    ));
}

function invoice_ui_font()
{
    return 'Inter, system-ui, "Segoe UI", sans-serif';
}

function invoice_ui_font_href()
{
    return 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap';
}

function apply_template($text, $vars)
{
    $replace = array();
    foreach ($vars as $key => $value) {
        $replace['{{' . $key . '}}'] = $value;
    }
    return strtr($text, $replace);
}

function config_needs_setup()
{
    return DB_NAME === 'your_database' || DB_USER === 'your_username';
}
