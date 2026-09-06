<?php
/**
 * Copy this file to brevo-config.php and fill in your Brevo values.
 * Do not commit brevo-config.php — it holds the API key.
 */
return [
    // Settings → SMTP & API → API Keys → Generate a new API key
    'api_key' => 'xkeysib-paste-your-key-here',

    // Settings → Senders, domains & dedicated IPs → Senders
    // Must be a verified sender in Brevo (usually info@app123.ca).
    'sender_email' => 'info@app123.ca',
    'sender_name' => 'App123',

    // Where contact-form messages should land.
    'to_email' => 'info@app123.ca',
    'to_name' => 'App123',
];
