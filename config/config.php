<?php

// ----------------------------------------------------------------------------
// Central configuration. Reads from environment variables (populated from
// .env by public/index.php) with sane defaults for local XAMPP.
// Returning an array keeps it trivially testable and injectable.
// ----------------------------------------------------------------------------

return [

    // ------------------------------------------------------------------
    // Application settings.
    // ------------------------------------------------------------------
    'app' => [
        // Display name used in <title> and the navbar.
        'name'  => getenv('APP_NAME')  ?: 'RCIS AMS',

        // "local" | "production". Used later to toggle verbose errors.
        'env'   => getenv('APP_ENV')   ?: 'local',

        // Boolean debug flag. filter_var(..., FILTER_VALIDATE_BOOLEAN)
        // accepts "1", "true", "on", "yes" (case-insensitive) as true.
        'debug' => filter_var(
            getenv('APP_DEBUG') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        ),

        // Base URL used by the url() helper in app/helpers.php.
        'url'   => getenv('APP_URL') ?: 'http://localhost:8080',
    ],

    // ------------------------------------------------------------------
    // Database settings.
    // ------------------------------------------------------------------
    'db' => [
        'host'     => getenv('DB_HOST')     ?: '127.0.0.1',
        'port'     => getenv('DB_PORT')     ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'rcis',
        'username' => getenv('DB_USERNAME') ?: 'rcis',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],
];
