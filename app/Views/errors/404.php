<?php require __DIR__ . '/../layouts/header.php'; ?>

<section class="card narrow">
    <h1>404 — Not Found</h1>
    <p>The page or record you requested does not exist.</p>
    <p><a class="btn btn-primary" href="<?= e(url('accounts')) ?>">Back to accounts</a></p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>