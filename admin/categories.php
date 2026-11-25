<?php
/**
 * lk_2002 Manage categories and age limits (Module 2.0, sub-process 2.2). Screen: Figure 4.4.
 */
require __DIR__ . '/../includes/init.php';
require_login('admin');
handle_context_switch($pdo);
$comp = current_competition($pdo);

$editId = (int) ($_GET['edit'] ?? $_POST['category_id'] ?? 0);
$form = ['name' => '', 'born_on_or_after' => '', 'min_squad' => '11', 'max_squad' => '20', 'half_minutes' => '30', 'reg_closes' => ''];
$errors = [];

if ($comp && $editId) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE category_id = ? AND competition_id = ?');
    $stmt->execute([$editId, $comp['competition_id']]);
    if ($row = $stmt->fetch()) {
        $form = array_merge($form, $row);
    } else {
        $editId = 0;
    }
}

if ($comp && is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM teams WHERE category_id = ?) + (SELECT COUNT(*) FROM matches WHERE category_id = ?)');
        $stmt->execute([$id, $id]);
        if ($stmt->fetchColumn() > 0) {
            flash('danger', 'This category cannot be deleted because teams are registered in it.');
        } else {
            $pdo->prepare('DELETE FROM `groups` WHERE category_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM categories WHERE category_id = ? AND competition_id = ?')->execute([$id, $comp['competition_id']]);
            flash('success', 'Category deleted.');
        }
        redirect('admin/categories.php');
    }

    foreach (array_keys($form) as $k) {
        $form[$k] = input($k);
    }
    $form['name'] = strtoupper($form['name']);
    if ($form['name'] === '' || mb_strlen($form['name']) > 20) {
        $errors['name'] = 'Category name is required (up to 20 characters), e.g. U15.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE competition_id = ? AND name = ? AND category_id <> ?');
        $stmt->execute([$comp['competition_id'], $form['name'], $editId]);
        if ($stmt->fetchColumn() > 0) {
            $errors['name'] = 'This competition already has a category with this name.';
        }
    }
    if (!valid_date($form['born_on_or_after'])) {
        $errors['born_on_or_after'] = 'Enter the earliest date of birth allowed.';
    }
    $min = filter_var($form['min_squad'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
    $max = filter_var($form['max_squad'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
    if ($min === false) {
        $errors['min_squad'] = 'Enter a number from 1 to 30.';
    }
    if ($max === false) {
        $errors['max_squad'] = 'Enter a number from 1 to 30.';
    } elseif ($min !== false && $min > $max) {
        $errors['max_squad'] = 'Maximum cannot be less than minimum.';
    }
    if (!in_array($form['half_minutes'], ['20', '25', '30', '35', '40', '45'], true)) {
        $errors['half_minutes'] = 'Choose the match duration.';
    }
    $closes = valid_date($form['reg_closes']);
    if (!$closes) {
        $errors['reg_closes'] = 'Enter the last day of registration.';
    } elseif ($closes > $comp['start_date']) {
        $errors['reg_closes'] = 'Registration must close before the competition starts (' . format_date($comp['start_date']) . ').';
    }

    if (!$errors) {
        $values = [$form['name'], $form['born_on_or_after'], $min, $max, (int) $form['half_minutes'], $closes];
        if ($editId) {
            $pdo->prepare('UPDATE categories SET name = ?, born_on_or_after = ?, min_squad = ?, max_squad = ?, half_minutes = ?, reg_closes = ?
                           WHERE category_id = ? AND competition_id = ?')->execute([...$values, $editId, $comp['competition_id']]);
            flash('success', 'Category ' . $form['name'] . ' updated.');
        } else {
            $pdo->prepare('INSERT INTO categories (name, born_on_or_after, min_squad, max_squad, half_minutes, reg_closes, competition_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $comp['competition_id']]);
            $_SESSION['ctx_cat'] = (int) $pdo->lastInsertId();
            flash('success', 'Category ' . $form['name'] . ' added. Next, set its competition rules.');
        }
        redirect('admin/categories.php');
    }
}

$cats = [];
if ($comp) {
    $stmt = $pdo->prepare('SELECT k.*, (SELECT COUNT(*) FROM teams t WHERE t.category_id = k.category_id) AS n_team,
                                  (SELECT COUNT(*) FROM competition_rules r WHERE r.category_id = k.category_id) AS has_rules
                             FROM categories k WHERE k.competition_id = ? ORDER BY k.name');
    $stmt->execute([$comp['competition_id']]);
    $cats = $stmt->fetchAll();
}

$pageTitle = 'Categories and Age Limits';
$active = 'Categories';
$crumb = 'Competition Setup / Categories';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Categories and Age Limits</h1>
    <p><?= $comp ? e($comp['name']) . ' · Each category has its own age limit, squad size and teams.' : 'Each category has its own age limit, squad size and teams.' ?></p>
</div>
<?php if (!$comp): need_setup('competition'); else: ?>
<div class="row g-4">
    <div class="col-lg-5">
        <form method="post" class="card" novalidate>
            <div class="card-header"><?= $editId ? 'Edit Category' : 'Add Category' ?></div>
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="category_id" value="<?= $editId ?>">
                <div class="mb-3">
                    <label class="form-label req" for="name">Category name</label>
                    <input class="form-control <?= inv($errors, 'name') ?>" id="name" name="name" value="<?= e($form['name']) ?>" placeholder="e.g. U15">
                    <?= err($errors, 'name') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label req" for="born_on_or_after">Players born on or after</label>
                    <input class="form-control <?= inv($errors, 'born_on_or_after') ?>" type="date" id="born_on_or_after" name="born_on_or_after" value="<?= e($form['born_on_or_after']) ?>">
                    <?= err($errors, 'born_on_or_after') ?: '<div class="form-text">Used to check each player\'s age at registration.</div>' ?>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label req" for="min_squad">Minimum squad size</label>
                        <input class="form-control <?= inv($errors, 'min_squad') ?>" type="number" min="1" max="30" id="min_squad" name="min_squad" value="<?= e((string) $form['min_squad']) ?>">
                        <?= err($errors, 'min_squad') ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label req" for="max_squad">Maximum squad size</label>
                        <input class="form-control <?= inv($errors, 'max_squad') ?>" type="number" min="1" max="30" id="max_squad" name="max_squad" value="<?= e((string) $form['max_squad']) ?>">
                        <?= err($errors, 'max_squad') ?>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label req" for="half_minutes">Match duration</label>
                        <select class="form-select <?= inv($errors, 'half_minutes') ?>" id="half_minutes" name="half_minutes">
                            <?php foreach ([20, 25, 30, 35, 40, 45] as $m): ?>
                                <option value="<?= $m ?>" <?= (string) $m === (string) $form['half_minutes'] ? 'selected' : '' ?>>2 × <?= $m ?> minutes</option>
                            <?php endforeach; ?>
                        </select>
                        <?= err($errors, 'half_minutes') ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label req" for="reg_closes">Registration closes</label>
                        <input class="form-control <?= inv($errors, 'reg_closes') ?>" type="date" id="reg_closes" name="reg_closes" value="<?= e($form['reg_closes']) ?>">
                        <?= err($errors, 'reg_closes') ?>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <a class="btn btn-outline-secondary" href="<?= url('admin/categories.php') ?>">Cancel</a>
                <button class="btn btn-primary" type="submit">Save Category</button>
            </div>
        </form>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Categories in this competition</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Category</th><th>Born on/after</th><th>Squad size</th><th>Match length</th><th>Teams</th><th>Rules</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$cats): ?><tr><td colspan="7" class="text-center text-muted py-4">No categories yet.</td></tr><?php endif; ?>
                    <?php foreach ($cats as $c): ?>
                        <tr>
                            <td><strong><?= e($c['name']) ?></strong></td>
                            <td><?= format_date($c['born_on_or_after']) ?></td>
                            <td><?= $c['min_squad'] ?> – <?= $c['max_squad'] ?></td>
                            <td>2 × <?= $c['half_minutes'] ?> min</td>
                            <td><?= $c['n_team'] ?></td>
                            <td><?= $c['has_rules'] ? '<span class="badge text-bg-success">Set</span>' : '<a class="badge text-bg-warning text-decoration-none" href="' . url('admin/rules.php?ctx_cat=' . $c['category_id']) . '">Not set</a>' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $c['category_id'] ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['category_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" <?= $c['n_team'] ? 'disabled' : '' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="alert alert-light border mt-3 small"><i class="bi bi-info-circle me-1"></i>A category cannot be deleted once teams are registered in it.</div>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
