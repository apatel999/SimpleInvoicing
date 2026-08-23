<?php

function send_app_mail($to, $subject, $htmlBody)
{
    $fromEmail = setting('smtp_from_email') !== '' ? setting('smtp_from_email') : setting('company_email');
    $fromName = setting('smtp_from_name') !== '' ? setting('smtp_from_name') : setting('company_name', 'SimpleInvoicing');
    if ($fromEmail === '' || $to === '') {
        return 'Set a from address in Settings before sending email.';
    }

    $inline = mail_inline_images();
    $copy = mail_copy_address($to);
    $host = setting('smtp_host');
    if ($host !== '') {
        return smtp_send($to, $subject, $htmlBody, $fromEmail, $fromName, $inline, $copy);
    }

    if ($inline) {
        $htmlBody = str_replace('cid:company-logo', company_logo_absolute_url(), $htmlBody);
    }

    $encodedFrom = encode_mail_header($fromName) . ' <' . $fromEmail . '>';
    $headers = array(
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $encodedFrom,
        'Reply-To: ' . $fromEmail,
    );
    if ($copy !== '') {
        $headers[] = 'Bcc: ' . $copy;
    }
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, implode("\r\n", $headers));
    return $ok ? true : 'The server rejected mail(). Configure SMTP in Settings.';
}

function mail_copy_address($to = '')
{
    $copy = trim(setting('email_bcc'));
    if (strpos($copy, '@') === false || strcasecmp($copy, (string) $to) === 0) {
        return '';
    }
    return $copy;
}

function mail_inline_images()
{
    $file = company_logo_file();
    if ($file === '') {
        return array();
    }
    $info = @getimagesize($file);
    return array(array(
        'path' => $file,
        'cid' => 'company-logo',
        'name' => basename($file),
        'mime' => ($info && !empty($info['mime'])) ? $info['mime'] : 'image/png',
    ));
}

function encode_mail_header($value)
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_send($to, $subject, $htmlBody, $fromEmail, $fromName, $inline = array(), $copy = '')
{
    $host = setting('smtp_host');
    $port = (int) setting('smtp_port', '587');
    $user = setting('smtp_username');
    $pass = setting('smtp_password');
    $enc = strtolower(setting('smtp_encryption', 'tls'));
    $timeout = 20;

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . ($port ? $port : 587);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return 'Could not connect to SMTP: ' . $errstr;
    }
    stream_set_timeout($fp, $timeout);

    $err = smtp_expect($fp, 220);
    if ($err) {
        fclose($fp);
        return $err;
    }

    smtp_cmd($fp, 'EHLO localhost');
    $err = smtp_expect($fp, 250);
    if ($err) {
        fclose($fp);
        return $err;
    }

    if ($enc === 'tls') {
        smtp_cmd($fp, 'STARTTLS');
        $err = smtp_expect($fp, 220);
        if ($err) {
            fclose($fp);
            return $err;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return 'SMTP STARTTLS failed.';
        }
        smtp_cmd($fp, 'EHLO localhost');
        $err = smtp_expect($fp, 250);
        if ($err) {
            fclose($fp);
            return $err;
        }
    }

    if ($user !== '') {
        smtp_cmd($fp, 'AUTH LOGIN');
        $err = smtp_expect($fp, 334);
        if ($err) {
            fclose($fp);
            return $err;
        }
        smtp_cmd($fp, base64_encode($user));
        $err = smtp_expect($fp, 334);
        if ($err) {
            fclose($fp);
            return $err;
        }
        smtp_cmd($fp, base64_encode($pass));
        $err = smtp_expect($fp, 235);
        if ($err) {
            fclose($fp);
            return $err;
        }
    }

    smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>');
    $err = smtp_expect($fp, 250);
    if ($err) {
        fclose($fp);
        return $err;
    }
    smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
    $err = smtp_expect($fp, 250);
    if ($err) {
        fclose($fp);
        return $err;
    }
    if ($copy !== '') {
        // A rejected copy address must not fail the customer's email.
        smtp_cmd($fp, 'RCPT TO:<' . $copy . '>');
        smtp_expect($fp, 250);
    }
    smtp_cmd($fp, 'DATA');
    $err = smtp_expect($fp, 354);
    if ($err) {
        fclose($fp);
        return $err;
    }

    $htmlBody = str_replace("\r\n.", "\r\n..", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $htmlBody)));
    $payload = 'From: ' . encode_mail_header($fromName) . ' <' . $fromEmail . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\n";

    if (!$inline) {
        $payload .= "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $htmlBody
            . "\r\n.";
    } else {
        $boundary = 'si' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
        $payload .= 'Content-Type: multipart/related; type="text/html"; boundary="' . $boundary . "\"\r\n"
            . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $htmlBody . "\r\n";
        foreach ($inline as $image) {
            $data = chunk_split(base64_encode((string) file_get_contents($image['path'])));
            $payload .= '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $image['mime'] . '; name="' . $image['name'] . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-ID: <' . $image['cid'] . ">\r\n"
                . 'Content-Disposition: inline; filename="' . $image['name'] . "\"\r\n"
                . "\r\n"
                . $data;
        }
        $payload .= '--' . $boundary . "--\r\n.";
    }
    fwrite($fp, $payload . "\r\n");
    $err = smtp_expect($fp, 250);
    smtp_cmd($fp, 'QUIT');
    fclose($fp);
    return $err ? $err : true;
}

function smtp_cmd($fp, $line)
{
    fwrite($fp, $line . "\r\n");
}

function smtp_expect($fp, $code)
{
    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp, 512);
        if ($line === false) {
            return 'Empty SMTP response.';
        }
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    if (strpos($response, (string) $code) !== 0) {
        return 'SMTP error: ' . trim($response);
    }
    return '';
}
