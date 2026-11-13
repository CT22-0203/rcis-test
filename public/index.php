<?php

/**
 * Front controller.
 * Every HTTP request lands here (via .htaccess rewrite).
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 1. Load Composer's autoloader.
//    Makes every "App\*" and "Tests\*" class available by name.
// -----------------------------------------------------------------------------
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Composer dependencies are missing. Run `composer install`.');
}
require $autoload;

// -----------------------------------------------------------------------------
// 2. Load .env (if present) into environment variables.
//    A hand-rolled parser keeps the tutorial dependency-free.
// -----------------------------------------------------------------------------
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue; // skip blank lines and comments
        }
        // Split on the first "=" only, so passwords containing "=" survive.
        [$key, $value] = explode('=', $line, 2) + [1 => ''];
        // Strip optional surrounding quotes.
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv(trim($key) . '=' . $value);
    }
}

// -----------------------------------------------------------------------------
// 3. Session + basic runtime settings.
// -----------------------------------------------------------------------------
// Sessions let us persist the logged-in user across requests.
session_start();

// All times / dates rendered by PHP use UTC. Change to your locale if needed.
date_default_timezone_set('UTC');

// Errors in local env should be visible; in production, log only.
$config = require __DIR__ . '/../config/config.php';
if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

// -----------------------------------------------------------------------------
// 4. Normalise the URI so routing works both at the web root and in a
//    sub-folder (the XAMPP default).
// -----------------------------------------------------------------------------
$appUrl    = (string) getenv('APP_URL');
$basePath  = rtrim((string) parse_url($appUrl, PHP_URL_PATH), '/');   // e.g. "/account-management-system/public"
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($basePath !== '' && str_starts_with($requestUri, $basePath)) {
    // Strip the base path so "/.../public/accounts/1" becomes "/accounts/1".
    $requestUri = substr($requestUri, strlen($basePath)) ?: '/';
}

// -----------------------------------------------------------------------------
// 5. Build the router, load routes, dispatch.
// -----------------------------------------------------------------------------
$router = new App\Core\Router();
require __DIR__ . '/../routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestUri);
