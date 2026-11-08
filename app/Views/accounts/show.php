<?php require __DIR__ . '/../layouts/header.php'; ?>

<div class="page-head">
    <h1>Account #<?= e((string) $account->id) ?></h1>
    <div>
        <a class="btn btn-secondary" href="<?= e(url('accounts')) ?>">Back</a>
        <a class="btn btn-primary" href="<?= e(url('accounts/' . $account->id . '/edit')) ?>">Edit</a>
    </div>
</div>

<dl class="detail card">
    <dt>Owner</dt><dd><?= e($account->ownerName) ?></dd>
    <dt>Email</dt><dd><?= e($account->email) ?></dd>
    <dt>Balance</dt><dd>$<?= e(number_format($account->balance, 2)) ?></dd>
    <dt>Status</dt><dd><span class="badge badge-<?= e($account->status) ?>"><?= e($account->status) ?></span></dd>
    <dt>Created</dt><dd><?= e($account->createdAt) ?></dd>
    <dt>Updated</dt><dd><?= e($account->updatedAt) ?></dd>
</dl>

<?php require __DIR__ . '/../layouts/footer.php'; ?>