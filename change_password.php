<?php
/**
 * Change password (Module 1.0). Team managers must use it after their first login
 * because their account starts with a temporary password.
 */
require __DIR__ . '/includes/init.php';
$user = require_login();

$errors = [];
if (is_post()) {
    check_csrf();
    $current = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($current, (string) $hash)) {
        $errors['current'] = 'Current password is wrong.';
    }
    if (strlen($new) < 8) {
        $errors['new'] = 'New password must have at least 8 characters.';
    } elseif ($new === $current) {
        $errors['new'] = 'New password must be different from the current one.';
    }
    if ($new !== $confirm) {
        $errors['confirm'] = 'The two new passwords do not match.';
    }
    if (!$errors) {
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_pw = 0 WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
        $_SESSION['user']['must_change_pw'] = 0;
        flash('success', 'Your password has been changed.');
        redirect(home_for($user));
    }
}

$pageTitle = 'Change Password';
$active = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Change Password</h1>
    <p><?= $user['must_change_pw'] ? 'You are using a temporary password. Please choose your own password to continue.' : 'Choose a new password for your account.' ?></p>
</div>
<div class="card" style="max-width: 480px;">
    <div class="card-body">
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <?php foreach (['current' => 'Current password', 'new' => 'New password', 'confirm' => 'Confirm new password'] as $name => $label): ?>
                <div class="mb-3">
                    <label class="form-label req" for="<?= $name ?>"><?= $label ?></label>
                    <input class="form-control <?= isset($errors[$name]) ? 'is-invalid' : '' ?>" type="password" id="<?= $name ?>" name="<?= $name ?>" required>
                    <?php if (isset($errors[$name])): ?><div class="invalid-feedback"><?= e($errors[$name]) ?></div><?php endif; ?>
                    <?php if ($name === 'new'): ?><div class="form-text">At least 8 characters.</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <button class="btn btn-primary" type="submit">Save Password</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
