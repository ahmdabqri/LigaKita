<?php
/**
 * lk_1001 Log in and log out (Module 1.0, sub-process 1.1). Screen: Figure 4.1.
 */
require __DIR__ . '/includes/init.php';

if ($u = current_user()) {
    redirect(home_for($u));
}

$username = '';
$error = '';
if (is_post()) {
    check_csrf();
    $username = input('username');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $found = $stmt->fetch();
        if (!$found || !password_verify($password, $found['password_hash'])) {
            $error = 'Wrong username or password.';
        } elseif ($found['status'] !== 'active') {
            $error = 'This account has been deactivated. Please contact the committee.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'user_id' => (int) $found['user_id'],
                'full_name' => $found['full_name'],
                'username' => $found['username'],
                'role' => $found['role'],
                'must_change_pw' => (int) $found['must_change_pw'],
            ];
            if (!empty($_POST['remember'])) {
                // Keep the session cookie for 30 days on this device.
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), ['expires' => time() + 30 * 86400, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            if ($found['must_change_pw']) {
                redirect('change_password.php');
            }
            redirect(home_for($_SESSION['user']));
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · LigaKita</title>
    <link href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-page d-flex align-items-center justify-content-center p-3">
<div class="card shadow-sm" style="width: 100%; max-width: 400px;">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <span class="brand-logo mb-2" style="width:56px;height:56px;font-size:1.6rem"><i class="bi bi-trophy-fill"></i></span>
            <h1 class="h4 fw-bold mb-0">LigaKita</h1>
            <div class="text-muted small">Football Tournament Management System</div>
        </div>
        <?php foreach (take_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?> py-2"><?= $f['message'] ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label req" for="username">Username</label>
                <input class="form-control" id="username" name="username" value="<?= e($username) ?>" placeholder="Enter your username" autofocus required>
            </div>
            <div class="mb-3">
                <label class="form-label req" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" required>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
            <button class="btn btn-primary w-100" type="submit">Log In</button>
        </form>
        <hr>
        <div class="text-center small"><a href="<?= url('index.php') ?>" class="text-decoration-none">View fixtures and standings without logging in <i class="bi bi-arrow-right"></i></a></div>
        <div class="text-center small text-muted mt-2">Forgot your password? Ask the committee to reset it.</div>
    </div>
</div>
</body>
</html>
