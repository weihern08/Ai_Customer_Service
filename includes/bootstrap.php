<?php
/**
 * Application bootstrap. Every entry script includes this file first.
 *
 * Optional constants an entry script may define before including:
 *   NS_API        - request is a JSON API call (errors are returned as JSON)
 *   NS_PASSIVE    - background polling request (does not extend the idle session timer)
 *   NS_INSTALLER  - the installer is running (no database / session yet)
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
const APP_VERSION = '1.0.0';

spl_autoload_register(static function (string $class): void {
    foreach (['includes/core', 'includes/services', 'includes/ai'] as $dir) {
        $file = APP_ROOT . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

require APP_ROOT . '/includes/helpers.php';

$configFile = APP_ROOT . '/config/config.php';
$configExists = is_file($configFile);

Config::load($configExists ? require $configFile : []);
ErrorHandler::register(Config::get('app.env') === 'development');
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kuala_Lumpur'));

// Security headers (also set in .htaccess; repeated here for servers without mod_headers)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: microphone=(self), camera=(), geolocation=()');
}

if (defined('NS_INSTALLER')) {
    return;
}

if (!$configExists || !is_file(APP_ROOT . '/storage/installed.lock')) {
    header('Location: ' . url('install.php'));
    exit;
}

Session::start((int) Settings::get('session_timeout_minutes', 30), !defined('NS_PASSIVE'));
Lang::boot();
