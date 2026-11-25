<?php
/**
 * lk_1002 Manage team manager accounts (Module 1.0, sub-process 1.2). Screen: Figure 4.2.
 * The administrator creates, edits, deactivates and resets the accounts of team managers.
 */
require __DIR__ . '/../includes/init.php';
$admin = require_login('admin');
handle_context_switch($pdo);

$editId = (int) ($_GET['edit'] ?? $_POST['user_id'] ?? 0);
$form = ['full_name' => '', 'club_name' => '', 'phone' => '', 'email' => '', 'username' => '', 'temp_password' => '', 'must_change_pw' => 1];
$errors = [];

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'team_manager'");
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
    if (!$editing) {
        flash('danger', 'Account not found.');
        redirect('admin/accounts.php');
    }
    $form = array_merge($form, $editing);
}

if (is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        foreach (['full_name', 'club_name', 'phone', 'email', 'username', 'temp_password'] as $k) {
            $form[$k] = input($k);
        }
        $form['must_change_pw'] = isset($_POST['must_change_pw']) ? 1 : 0;

        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
            $errors['full_name'] = 'Full name is required (up to 100 characters).';
        }
        if ($form['club_name'] === '' || mb_strlen($form['club_name']) > 150) {
            $errors['club_name'] = 'Club / team name is required (up to 150 characters).';
        }
        if (!preg_match('/^[0-9][0-9\- ]{8,13}$/', $form['phone'])) {
            $errors['phone'] = 'Enter a valid phone number, e.g. 012-345 6789.';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid e-mail address or leave it empty.';
        }
        if (!preg_match('/^[A-Za-z0-9_]{4,50}$/', $form['username'])) {
            $errors['username'] = 'Username must be 4 to 50 letters, digits or underscores.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND user_id <> ?');
            $stmt->execute([$form['username'], $editId]);
            if ($stmt->fetchColumn() > 0) {
                $errors['username'] = 'This username is already used by another account.';
            }
        }
        if (!$editId && strlen($form['temp_password']) < 8) {
            $errors['temp_password'] = 'Temporary password must have at least 8 characters. Press Generate.';
        }

        if (!$errors) {
            if ($editId) {
                $pdo->prepare('UPDATE users SET full_name = ?, club_name = ?, phone = ?, email = ?, username = ? WHERE user_id = ?')
                    ->execute([$form['full_name'], $form['club_name'], $form['phone'], $form['email'] ?: null, $form['username'], $editId]);
                flash('success', 'Account of ' . $form['full_name'] . ' has been updated.');
            } else {
                $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, phone, email, club_name, status, must_change_pw)
                               VALUES (?, ?, ?, 'team_manager', ?, ?, ?, 'active', ?)")
                    ->execute([$form['full_name'], $form['username'], password_hash($form['temp_password'], PASSWORD_DEFAULT),
                        $form['phone'], $form['email'] ?: null, $form['club_name'], $form['must_change_pw']]);
                flash('success', 'Account created. Give these login details to the team manager: username <strong>'
                    . e($form['username']) . '</strong>, temporary password <code class="fs-6">' . e($form['temp_password']) . '</code>. '
                    . 'The password is shown only once.', true);
            }
            redirect('admin/accounts.php');
        }
    } elseif ($action === 'reset' || $action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'team_manager'");
        $stmt->execute([$id]);
        if ($acc = $stmt->fetch()) {
            if ($action === 'reset') {
                $pw = temp_password();
                $pdo->prepare('UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE user_id = ?')
                    ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                flash('success', 'Password of <strong>' . e($acc['username']) . '</strong> has been reset. New temporary password: <code class="fs-6">'
                    . e($pw) . '</code> (shown only once).', true);
            } else {
                $new = $acc['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$new, $id]);
                flash('success', 'Account of ' . $acc['full_name'] . ($new === 'active' ? ' has been activated.' : ' has been deactivated.'));
            }
        }
        redirect('admin/accounts.php');
    }
}

// List with search and paging (8 per page, as in the design)
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;
$where = "role = 'team_manager'";
$params = [];
if ($q !== '') {
    $where .= ' AND (full_name LIKE ? OR club_name LIKE ? OR username LIKE ?)';
    $params = array_fill(0, 3, '%' . $q . '%');
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$stmt = $pdo->prepare("SELECT * FROM users WHERE $where ORDER BY full_name LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$accounts = $stmt->fetchAll();

$pageTitle = 'Team Manager Accounts';
$active = 'Team Manager Accounts';
$crumb = 'Teams / Team Manager Accounts';
require __DIR__ . '/../includes/header.php';

function field(string $name, string $label, array $form, array $errors, bool $req = true, string $ph = '', string $type = 'text'): void
{ ?>
    <label class="form-label <?= $req ? 'req' : '' ?>" for="<?= $name ?>"><?= e($label) ?></label>
    <input class="form-control <?= isset($errors[$name]) ? 'is-invalid' : '' ?>" type="<?= $type ?>" id="<?= $name ?>" name="<?= $name ?>"
           value="<?= e((string) $form[$name]) ?>" placeholder="<?= e($ph) ?>">
    <?php if (isset($errors[$name])): ?><div class="invalid-feedback"><?= e($errors[$name]) ?></div><?php endif;
}
?>
<div class="page-head">
    <h1>Team Manager Accounts</h1>
    <p>Create a login for each team manager. They use it to register their team and players.</p>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><?= $editId ? 'Edit Account' : 'New Team Manager Account' ?></div>
            <form method="post" novalidate>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" value="<?= $editId ?>">
                    <div class="mb-3"><?php field('full_name', 'Full name', $form, $errors, true, 'Team manager full name'); ?></div>
                    <div class="mb-3"><?php field('club_name', 'Club / team name', $form, $errors, true, 'e.g. Benut FC'); ?></div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6"><?php field('phone', 'Phone number', $form, $errors, true, '01X-XXX XXXX'); ?></div>
                        <div class="col-sm-6"><?php field('email', 'Email', $form, $errors, false, 'name@email.com', 'email'); ?></div>
                    </div>
                    <div class="mb-3"><?php field('username', 'Username', $form, $errors, true, 'e.g. benutfc_manager'); ?></div>
                    <?php if (!$editId): ?>
                        <label class="form-label req" for="temp_password">Temporary password</label>
                        <div class="input-group has-validation mb-1">
                            <input class="form-control <?= isset($errors['temp_password']) ? 'is-invalid' : '' ?>" id="temp_password" name="temp_password"
                                   value="<?= e($form['temp_password']) ?>" placeholder="Auto-generated">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('temp_password').value = makePassword()">Generate</button>
                            <?php if (isset($errors['temp_password'])): ?><div class="invalid-feedback"><?= e($errors['temp_password']) ?></div><?php endif; ?>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="must_change_pw" name="must_change_pw" value="1" <?= $form['must_change_pw'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="must_change_pw">Must change password at first login</label>
                        </div>
                    <?php else: ?>
                        <div class="form-text">To give a new password, use Reset in the list.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white text-end">
                    <a class="btn btn-outline-secondary" href="<?= url('admin/accounts.php') ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit"><?= $editId ? 'Save Changes' : 'Create Account' ?></button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>Accounts (<?= $total ?>)</span>
                <form method="get" class="d-flex gap-2">
                    <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search name or club">
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Name</th><th>Club</th><th>Username</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$accounts): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= $q !== '' ? 'No account matches your search.' : 'No team manager accounts yet.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $a): ?>
                        <tr>
                            <td><?= e($a['full_name']) ?></td>
                            <td><?= e($a['club_name']) ?></td>
                            <td><code><?= e($a['username']) ?></code></td>
                            <td><span class="badge rounded-pill <?= $a['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= ucfirst($a['status']) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $a['user_id'] ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Reset the password of <?= e($a['username']) ?>?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $a['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary">Reset</button>
                                </form>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $a['user_id'] ?>">
                                    <button class="btn btn-sm <?= $a['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $a['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center small">
                <span class="text-muted">Showing <?= count($accounts) ?> of <?= $total ?> accounts</span>
                <span>
                    <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?page=<?= $page - 1 ?>&q=<?= urlencode($q) ?>">‹ Prev</a>
                    <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="?page=<?= $page + 1 ?>&q=<?= urlencode($q) ?>">Next ›</a>
                </span>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
