<?php
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$targets = [
    'Brevo API' => 'https://api.brevo.com/v3/account',
    'HTTPS example' => 'https://example.com/',
    'HTTP example' => 'http://example.com/',
];

function row($label, $ok, $detail = '')
{
    $class = $ok ? 'ok' : 'fail';
    $mark = $ok ? 'YES' : 'NO';
    echo '<tr><th>' . htmlspecialchars($label) . '</th><td class="' . $class . '">' . $mark . '</td><td><pre>' . htmlspecialchars((string) $detail) . '</pre></td></tr>';
}

function try_curl($url, $verifySsl)
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'cURL not loaded', 'ip' => '', 'time' => 0, 'body' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'App123-outbound-check',
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $status = (int) ($info['http_code'] ?? 0);
    return [
        'ok' => $body !== false && $status > 0,
        'status' => $status,
        'error' => $error !== '' ? "$errno $error" : '',
        'ip' => $info['primary_ip'] ?? '',
        'time' => round((float) ($info['total_time'] ?? 0), 3),
        'body' => is_string($body) ? substr($body, 0, 180) : '',
    ];
}

function try_fopen($url)
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'ignore_errors' => true,
            'header' => "User-Agent: App123-outbound-check\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        $status = (int) $m[1];
    }
    $phpError = error_get_last();
    $error = '';
    if ($body === false && $status === 0 && is_array($phpError)) {
        $error = $phpError['message'] ?? '';
    }
    return [
        'ok' => $body !== false || $status > 0,
        'status' => $status,
        'error' => $error,
        'headers' => $headers,
        'body' => is_string($body) ? substr($body, 0, 180) : '',
    ];
}

$outbound = try_curl('https://api.ipify.org?format=json', false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Outbound API check</title>
  <style>
    body { font-family: Segoe UI, sans-serif; margin: 1.5rem; color: #111; background: #f6f7f9; }
    h1 { font-size: 1.2rem; }
    table { width: 100%; border-collapse: collapse; background: #fff; }
    th, td { border: 1px solid #ddd; padding: 0.55rem 0.7rem; text-align: left; vertical-align: top; }
    th { width: 28%; background: #f3f4f6; }
    .ok { color: #0a7a32; font-weight: 700; }
    .fail { color: #b42318; font-weight: 700; }
    pre { margin: 0; white-space: pre-wrap; font-size: 0.82rem; }
    .note { margin-top: 1rem; color: #444; max-width: 48rem; }
  </style>
</head>
<body>
  <h1>Outbound HTTP / API check</h1>
  <p>Run this on the free host. If Brevo and HTTPS fail, the contact form cannot send mail from this server.</p>
  <table>
    <?php
    row('PHP', true, PHP_VERSION);
    row('Server', true, ($_SERVER['SERVER_SOFTWARE'] ?? '') . "\n" . ($_SERVER['SERVER_ADDR'] ?? '') . ' ' . ($_SERVER['SERVER_NAME'] ?? ''));
    row('cURL loaded', function_exists('curl_init'), function_exists('curl_version') ? json_encode(curl_version()) : '');
    row('OpenSSL loaded', extension_loaded('openssl'));
    row('allow_url_fopen', (bool) ini_get('allow_url_fopen'), ini_get('allow_url_fopen') ? '1' : '0');
    row('disabled_functions', true, ini_get('disable_functions') ?: '(none listed)');
    row('Outbound IP (ipify)', $outbound['ok'], 'status=' . $outbound['status'] . ' ip=' . $outbound['ip'] . "\n" . ($outbound['error'] ?: $outbound['body']));

    foreach ($targets as $label => $url) {
        $ssl = try_curl($url, true);
        row($label . ' via cURL + SSL verify', $ssl['ok'], $url . "\nstatus={$ssl['status']} time={$ssl['time']}s ip={$ssl['ip']}\n" . ($ssl['error'] ?: $ssl['body']));
        $nossl = try_curl($url, false);
        row($label . ' via cURL, SSL verify off', $nossl['ok'], "status={$nossl['status']} time={$nossl['time']}s ip={$nossl['ip']}\n" . ($nossl['error'] ?: $nossl['body']));
        $fopen = try_fopen($url);
        row($label . ' via file_get_contents', $fopen['ok'], "status={$fopen['status']}\n" . ($fopen['error'] ?: $fopen['body']));
    }
    ?>
  </table>
  <p class="note">
    <strong>How to read it:</strong> if every HTTPS row is NO, this host blocks outbound API calls — Brevo will not work here.
    If cURL + SSL verify is NO but SSL verify off is YES, the host is missing CA certificates.
    If Brevo is NO but example.com is YES, only that API is blocked.
    Delete this file after you finish testing.
  </p>
</body>
</html>
