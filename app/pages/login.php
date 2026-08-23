<?php

$GLOBALS['auth_layout'] = true;
$pageTitle = 'Sign in';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = post('email');
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if (attempt_login($email, $password)) {
        redirect('dashboard');
    }
    $error = 'Invalid email or password.';
}
?>
<div class="login-card">
    <h1>Welcome back</h1>
    <p class="lede">Sign in to manage invoices, customers, and payments.</p>
    <?php if ($error): ?>
        <div class="alert alert-err"><?php echo e($error); ?></div>
    <?php endif; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <p>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autofocus value="<?php echo e(post('email')); ?>">
        </p>
        <p>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>
        </p>
        <div class="form-actions">
            <button class="btn" type="submit">Sign in</button>
        </div>
    </form>
</div>
