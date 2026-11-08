<?php
/**
 * Layout header. Included by every page template.
 * Expects $title (string). Uses $appName from config for the navbar brand.
 */
$config  = require __DIR__ . '/../../../config/config.php';
$appName = $config['app']['name'];
$user    = $_SESSION['user'] ?? null;
$flash   = flash();  // read-and-pop once per request
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>

<header class="topbar">
    <a class="brand" href="<?= e(url('')) ?>"><?= e($appName) ?></a>

    <nav>
        <?php if ($user !== null): ?>
            <span class="hello">Hi, <?= e($user['full_name']) ?></span>
            <form method="post" action="<?= e(url('logout')) ?>" class="inline-form">
                <button type="submit" class="link-btn">Log out</button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<main class="container">

<?php if ($flash !== null): ?>
    <div class="flash flash-<?= e($flash['type']) ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>
