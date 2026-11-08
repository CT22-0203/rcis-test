<?php
require __DIR__ . '/../layouts/header.php';

// Pre-fill helper: use old input if available, else defaults.
$val = fn(string $k, string $default = '') => e((string) ($old[$k] ?? $default));
$err = fn(string $k) => $errors[$k] ?? null;
?>

<div class="page-head">
    <h1>New Account</h1>
</div>

<form method="post" action="<?= e(url('accounts')) ?>" class="card stack" novalidate>

    <label>
        Owner name
        <input type="text" name="owner_name" value="<?= $val('owner_name') ?>">
        <?php if ($err('owner_name')): ?>
            <span class="error"><?= e($err('owner_name')) ?></span>
        <?php endif; ?>
    </label>

    <label>
        Email
        <input type="email" name="email" value="<?= $val('email') ?>">
        <?php if ($err('email')): ?>
            <span class="error"><?= e($err('email')) ?></span>
        <?php endif; ?>
    </label>

    <label>
        Balance
        <input type="number" step="0.01" name="balance" value="<?= $val('balance', '0.00') ?>">
        <?php if ($err('balance')): ?>
            <span class="error"><?= e($err('balance')) ?></span>
        <?php endif; ?>
    </label>

    <label>
        Status
        <select name="status">
            <?php foreach (['active','inactive','suspended'] as $s): ?>
                <option value="<?= e($s) ?>" <?= ($old['status'] ?? '') === $s ? 'selected' : '' ?>>
                    <?= e($s) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('status')): ?>
            <span class="error"><?= e($err('status')) ?></span>
        <?php endif; ?>
    </label>

    <div class="form-actions">
        <a class="btn btn-secondary" href="<?= e(url('accounts')) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary">Create account</button>
    </div>
</form>

<?php require __DIR__ . '/../layouts/footer.php'; ?>