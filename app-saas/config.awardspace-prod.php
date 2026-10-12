<?php
/**
 * Copy this file to config.php on AwardSpace (inside the invoices folder).
 * Docker is not used on shared hosting — fill in the values from the control panel.
 *
 * AwardSpace: Hosting → MySQL Databases
 *   Host is usually fdbXX.awardspace.net, not localhost.
 */
function si_env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }
    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }
    return $value === false ? $default : $value;
}

define('DB_HOST', si_env('DB_HOST', 'pdb1054.awardspace.net'));
define('DB_NAME', si_env('DB_NAME', '1459861_craterinvoic'));
define('DB_USER', si_env('DB_USER', '1459861_craterinvoic'));
define('DB_PASS', si_env('DB_PASS', 'M0c00hay@'));

/** Public URL of this folder, no trailing slash. */
define('APP_URL', si_env('APP_URL', 'https://app123.ca/invoices'));

/** Must match this PC so existing public invoice links keep working. Copy APP_SECRET from local .env */
define('APP_SECRET', si_env('APP_SECRET', 'local-dev-change-this-secret'));

define('SI_DEBUG', false);
