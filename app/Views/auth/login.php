<?php require __DIR__ . '/../layouts/header.php'; ?>

<section class="card narrow">
    <h1>Sign In</h1>

    <form method="post" action="<?= e(url('login')) ?>" class="stack">
        <label>
            Username
            <input type="text" name="username" required autofocus>
        </label>

        <label>
            Password
            <input type="password" name="password" required>
        </label>

        <button type="submit" class="btn btn-primary">Sign in</button>
    </form>

    <p class="hint">
        Default credentials: <code>admin</code> / <code>admin123</code>
    </p>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
