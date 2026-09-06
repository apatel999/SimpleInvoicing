<?php

$pageTitle = 'Your account';
$currentPage = 'account';
$user = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = post('name');
    $email = post('email');
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if ($name === '' || $email === '') {
        $error = 'Name and email are required.';
    } else {
        try {
            db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ? AND role = ?')
                ->execute(array($name, $email, $user['id'], 'platform'));
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $error = 'New password must be at least 8 characters.';
                } else {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute(array(password_hash($password, PASSWORD_BCRYPT), $user['id']));
                }
            }
            if ($error === '') {
                flash('success', 'Account updated.');
                redirect('account');
            }
        } catch (Exception $e) {
            $error = 'That email is already in use.';
        }
    }
}
?>
<div class="card">
    <h2>Platform login</h2>
    <p class="muted">This account manages tenants and subscriptions. It cannot open tenant invoices.</p>
    <?php if ($error): ?>
        <div class="alert alert-err"><?php echo e($error); ?></div>
    <?php endif; ?>
    <form method="post" class="form-stack">
        <?php echo csrf_field(); ?>
        <p>
            <label for="name">Name</label>
            <input id="name" name="name" required value="<?php echo e($user['name']); ?>">
        </p>
        <p>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required value="<?php echo e($user['email']); ?>">
        </p>
        <p>
            <label for="password">New password</label>
            <input id="password" type="password" name="password" placeholder="Leave blank to keep current">
        </p>
        <div class="form-actions">
            <button class="btn" type="submit">Update account</button>
        </div>
    </form>
</div>
