<?php
/**
 * SimpleInvoicing configuration.
 *
 * Docker: leave these defaults; docker-compose injects DB_* and APP_* from .env.
 * Hosting: change the second argument of each si_env() call, then import sql/install.sql.
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

define('DB_HOST', si_env('DB_HOST', 'localhost'));
define('DB_NAME', si_env('DB_NAME', 'your_database'));
define('DB_USER', si_env('DB_USER', 'your_username'));
define('DB_PASS', si_env('DB_PASS', 'your_password'));

/** Public URL of this app, no trailing slash. Example: https://mydomain.com */
define('APP_URL', si_env('APP_URL', ''));

/** Random string used to sign public invoice links. Change this on hosting. */
define('APP_SECRET', si_env('APP_SECRET', 'change-this-to-a-long-random-string'));

/** Set SI_DEBUG=0 on the server so PHP errors are not shown to visitors. */
define('SI_DEBUG', filter_var(si_env('SI_DEBUG', '1'), FILTER_VALIDATE_BOOLEAN));
