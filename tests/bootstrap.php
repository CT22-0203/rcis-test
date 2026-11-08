<?php
declare(strict_types=1);

// Load Composer autoload so App\* and Tests\* resolve.
require __DIR__ . '/../vendor/autoload.php';

// Provide an APP_URL so url() works inside tests (not strictly needed for
// service-layer tests, but harmless and keeps things uniform).
putenv('APP_URL=http://localhost');