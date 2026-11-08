<?php require __DIR__ . '/../layouts/header.php'; ?>

<div class="page-head">
    <h1>Accounts</h1>
    <a class="btn btn-primary" href="<?= e(url('accounts/create')) ?>">+ New Account</a>
</div>

<?php if (empty($accounts)): ?>
    <p class="empty">No accounts yet. Click <strong>New Account</strong> to add one.</p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr>
            <th>#</th><th>Owner</th><th>Email</th><th>Balance</th><th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
            <tr>
                <td><?= e((string) $a->id) ?></td>
                <td><?= e($a->ownerName) ?></td>
                <td><?= e($a->email) ?></td>
                <td><?= e(number_format($a->balance, 2)) ?></td>
                <td><span class="badge badge-<?= e($a->status) ?>"><?= e($a->status) ?></span></td>
                <td class="actions">
                    <a href="<?= e(url('accounts/' . $a->id)) ?>">View</a>
                    <a href="<?= e(url('accounts/' . $a->id . '/edit')) ?>">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>