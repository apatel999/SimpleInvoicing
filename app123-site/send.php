<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST.']);
    exit;
}

$configFile = __DIR__ . '/brevo-config.php';
if (!is_readable($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email is not configured yet.']);
    exit;
}

$config = require $configFile;
$apiKey = trim((string) ($config['api_key'] ?? ''));
$senderEmail = trim((string) ($config['sender_email'] ?? ''));
$senderName = trim((string) ($config['sender_name'] ?? 'App123'));
$toEmail = trim((string) ($config['to_email'] ?? ''));
$toName = trim((string) ($config['to_name'] ?? 'App123'));

if ($apiKey === '' || strpos($apiKey, 'paste-your-key') !== false || $senderEmail === '' || $toEmail === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email is not configured yet.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

if (!empty($data['company_website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$name = trim((string) ($data['name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$service = trim((string) ($data['service'] ?? ''));
$notes = trim((string) ($data['notes'] ?? ''));

if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a name and a valid email.']);
    exit;
}

$name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
$email = function_exists('mb_substr') ? mb_substr($email, 0, 180) : substr($email, 0, 180);
$phone = function_exists('mb_substr') ? mb_substr($phone, 0, 40) : substr($phone, 0, 40);
$service = function_exists('mb_substr') ? mb_substr($service, 0, 80) : substr($service, 0, 80);
$notes = function_exists('mb_substr') ? mb_substr($notes, 0, 4000) : substr($notes, 0, 4000);

$safe = static function ($value) {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$subject = 'App123 website inquiry' . ($service !== '' ? ' — ' . $service : '');
$html = '<p>New message from the App123.ca contact form.</p>'
    . '<p><strong>Name:</strong> ' . $safe($name) . '<br>'
    . '<strong>Email:</strong> ' . $safe($email) . '<br>'
    . '<strong>Phone:</strong> ' . $safe($phone !== '' ? $phone : '—') . '<br>'
    . '<strong>Need:</strong> ' . $safe($service !== '' ? $service : '—') . '</p>'
    . '<p><strong>Message:</strong><br>' . nl2br($safe($notes !== '' ? $notes : '—')) . '</p>';

$payload = [
    'sender' => ['name' => $senderName, 'email' => $senderEmail],
    'to' => [['email' => $toEmail, 'name' => $toName]],
    'replyTo' => ['email' => $email, 'name' => $name],
    'subject' => $subject,
    'htmlContent' => $html,
    'textContent' => "Name: $name\nEmail: $email\nPhone: " . ($phone !== '' ? $phone : '—') . "\nNeed: " . ($service !== '' ? $service : '—') . "\n\n$notes",
];

$result = brevo_send($apiKey, $payload);
$debug = brevo_debug($apiKey, $senderEmail, $toEmail, $result);

if (!$result['ok']) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $result['error'],
        'debug' => $debug,
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['ok' => true, 'debug' => $debug], JSON_PRETTY_PRINT);

function brevo_redact($text, $apiKey)
{
    $text = (string) $text;
    if ($apiKey !== '') {
        $text = str_replace($apiKey, '[REDACTED_API_KEY]', $text);
    }
    $text = preg_replace('/xkeysib-[A-Za-z0-9\-]+/', '[REDACTED_API_KEY]', $text);
    return $text;
}

function brevo_debug($apiKey, $senderEmail, $toEmail, array $result)
{
    $keyLen = strlen($apiKey);
    return [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? '',
        'server_name' => $_SERVER['SERVER_NAME'] ?? '',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'curl' => function_exists('curl_init'),
        'curl_version' => function_exists('curl_version') ? curl_version() : null,
        'openssl' => extension_loaded('openssl'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'disabled_functions' => ini_get('disable_functions'),
        'sender_email' => $senderEmail,
        'to_email' => $toEmail,
        'api_key_set' => $keyLen > 10,
        'api_key_prefix' => $keyLen > 8 ? substr($apiKey, 0, 8) . '...' : '',
        'api_key_length' => $keyLen,
        'attempts' => $result['attempts'] ?? [],
        'last_error' => $result['error'] ?? '',
    ];
}

function brevo_send($apiKey, array $payload)
{
    $body = json_encode($payload);
    $headers = [
        'accept: application/json',
        'content-type: application/json',
        'api-key: ' . $apiKey,
    ];
    $attempts = [];

    if (function_exists('curl_init')) {
        $first = brevo_curl($body, $headers, true);
        $attempts[] = brevo_attempt_debug($apiKey, 'curl_ssl_verify', $first);
        if ($first['reachable']) {
            return brevo_finish($first, $attempts);
        }
        $second = brevo_curl($body, $headers, false);
        $attempts[] = brevo_attempt_debug($apiKey, 'curl_ssl_off', $second);
        if ($second['reachable']) {
            return brevo_finish($second, $attempts);
        }
        $curlError = $second['error'] !== '' ? $second['error'] : $first['error'];
    } else {
        $curlError = 'cURL is not available on this host.';
        $attempts[] = ['method' => 'curl', 'reachable' => false, 'error' => $curlError];
    }

    $fopen = brevo_fopen($body, $headers);
    $attempts[] = brevo_attempt_debug($apiKey, 'file_get_contents', $fopen);
    if ($fopen['reachable']) {
        return brevo_finish($fopen, $attempts);
    }

    $detail = $fopen['error'] !== '' ? $fopen['error'] : $curlError;
    return [
        'ok' => false,
        'error' => 'Could not reach Brevo from this host' . ($detail !== '' ? ': ' . $detail : '.'),
        'attempts' => $attempts,
    ];
}

function brevo_finish(array $attempt, array $attempts)
{
    if ($attempt['status'] >= 200 && $attempt['status'] < 300) {
        return ['ok' => true, 'error' => '', 'attempts' => $attempts];
    }
    return [
        'ok' => false,
        'error' => 'Brevo rejected the message. HTTP ' . $attempt['status'] . '.',
        'attempts' => $attempts,
    ];
}

function brevo_attempt_debug($apiKey, $method, array $attempt)
{
    return [
        'method' => $method,
        'reachable' => !empty($attempt['reachable']),
        'status' => $attempt['status'] ?? 0,
        'error' => brevo_redact($attempt['error'] ?? '', $apiKey),
        'errno' => $attempt['errno'] ?? 0,
        'primary_ip' => $attempt['primary_ip'] ?? '',
        'total_time' => $attempt['total_time'] ?? 0,
        'response' => brevo_redact($attempt['response'] ?? '', $apiKey),
        'http_headers' => $attempt['http_headers'] ?? [],
    ];
}

function brevo_curl($body, array $headers, $verifySsl)
{
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($ch === false) {
        return ['reachable' => false, 'status' => 0, 'error' => 'cURL failed to start.', 'errno' => 0, 'response' => ''];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => $verifySsl ? true : false,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    $response = curl_exec($ch);
    $error = trim((string) curl_error($ch));
    $errno = (int) curl_errno($ch);
    $info = curl_getinfo($ch);
    $status = (int) ($info['http_code'] ?? 0);
    curl_close($ch);

    return [
        'reachable' => $response !== false || $status > 0,
        'status' => $status,
        'error' => $error,
        'errno' => $errno,
        'primary_ip' => $info['primary_ip'] ?? '',
        'total_time' => $info['total_time'] ?? 0,
        'response' => is_string($response) ? substr($response, 0, 1500) : '',
    ];
}

function brevo_fopen($body, array $headers)
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    $status = 0;
    $httpHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    if (isset($httpHeaders[0]) && preg_match('/\s(\d{3})\s/', $httpHeaders[0], $match)) {
        $status = (int) $match[1];
    }

    $phpError = error_get_last();
    $error = '';
    if ($response === false && $status === 0) {
        $error = 'Outbound HTTPS may be blocked on this hosting plan.';
        if (is_array($phpError) && !empty($phpError['message'])) {
            $error .= ' ' . $phpError['message'];
        }
    }

    return [
        'reachable' => $response !== false || $status > 0,
        'status' => $status,
        'error' => $error,
        'response' => is_string($response) ? substr($response, 0, 1500) : '',
        'http_headers' => $httpHeaders,
    ];
}
