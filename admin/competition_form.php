<?php
/**
 * lk_2001 Create competition (Module 2.0, sub-process 2.1). Screen: Figure 4.3.
 * Also used to edit an existing competition.
 */
require __DIR__ . '/../includes/init.php';
$admin = require_login('admin');
handle_context_switch($pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$form = ['name' => '', 'season' => date('Y'), 'start_date' => '', 'end_date' => '', 'organiser' => '', 'format' => 'group_knockout',
    'description' => '', 'status' => 'upcoming'];
$errors = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM competitions WHERE competition_id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Competition not found.');
        redirect('admin/competitions.php');
    }
    $form = array_merge($form, $row);
}

if (is_post()) {
    check_csrf();
    foreach (array_keys($form) as $k) {
        $form[$k] = input($k, (string) $form[$k]);
    }
    if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
        $errors['name'] = 'Competition name is required (up to 150 characters).';
    }
    if (!preg_match('/^(19|20)\d\d$/', $form['season'])) {
        $errors['season'] = 'Choose the season.';
    }
    $start = valid_date($form['start_date']);
    $end = valid_date($form['end_date']);
    if (!$start) {
        $errors['start_date'] = 'Start date is required.';
    }
    if (!$end) {
        $errors['end_date'] = 'End date is required.';
    } elseif ($start && $end < $start) {
        $errors['end_date'] = 'End date cannot be earlier than start date.';
    }
    if (!isset(FORMATS[$form['format']])) {
        $errors['format'] = 'Choose the competition format.';
    }
    if (!in_array($form['status'], ['upcoming', 'ongoing', 'completed'], true)) {
        $form['status'] = 'upcoming';
    }

    if (!$errors) {
        $values = [$form['name'], $form['season'], $form['organiser'] ?: null, $form['format'], $start, $end, $form['description'] ?: null, $form['status']];
        if ($id) {
            $pdo->prepare('UPDATE competitions SET name = ?, season = ?, organiser = ?, format = ?, start_date = ?, end_date = ?, description = ?, status = ?
                           WHERE competition_id = ?')->execute([...$values, $id]);
            flash('success', 'Competition updated.');
        } else {
            $pdo->prepare('INSERT INTO competitions (name, season, organiser, format, start_date, end_date, description, status, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $admin['user_id']]);
            $id = (int) $pdo->lastInsertId();
            flash('success', 'Competition created. Next, add its categories and age limits.');
        }
        $_SESSION['ctx_comp'] = $id;
        unset($_SESSION['ctx_cat']);
        redirect('admin/competition_form.php?id=' . $id);
    }
}

$existing = $pdo->query('SELECT name, status FROM competitions ORDER BY start_date DESC LIMIT 5')->fetchAll();

$pageTitle = $id ? 'Edit Competition' : 'Create Competition';
$active = 'Competitions';
$crumb = 'Competitions / ' . $pageTitle;
require __DIR__ . '/../includes/header.php';

$formatHelp = ['league' => 'Round robin, all teams play each other', 'knockout' => 'Single elimination, byes added automatically',
    'group_knockout' => 'Groups first, then knockout rounds'];
?>
<div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <p>Enter the competition details and select its format.</p>
</div>
<div class="row g-4">
    <div class="col-xl-8">
        <form method="post" class="card" novalidate>
            <div class="card-header">Competition Details</div>
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="mb-3">
                    <label class="form-label req" for="name">Competition name</label>
                    <input class="form-control <?= inv($errors, 'name') ?>" id="name" name="name" value="<?= e($form['name']) ?>" placeholder="e.g. Pontian District Youth Cup 2026">
                    <?= err($errors, 'name') ?>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label req" for="season">Season</label>
                        <select class="form-select <?= inv($errors, 'season') ?>" id="season" name="season">
                            <?php for ($y = (int) date('Y') + 2; $y >= (int) date('Y') - 5; $y--): ?>
                                <option <?= (string) $y === (string) $form['season'] ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                        <?= err($errors, 'season') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label req" for="start_date">Start date</label>
                        <input class="form-control <?= inv($errors, 'start_date') ?>" type="date" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>">
                        <?= err($errors, 'start_date') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label req" for="end_date">End date</label>
                        <input class="form-control <?= inv($errors, 'end_date') ?>" type="date" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>">
                        <?= err($errors, 'end_date') ?>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label" for="organiser">Organiser</label>
                        <input class="form-control" id="organiser" name="organiser" value="<?= e($form['organiser']) ?>" placeholder="e.g. Pontian District Football Association">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach (['upcoming', 'ongoing', 'completed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label class="form-label req">Competition format</label>
                <div class="row g-2 mb-1">
                    <?php foreach (FORMATS as $value => $label): ?>
                        <div class="col-md-4">
                            <label class="format-option d-block">
                                <input class="form-check-input me-1" type="radio" name="format" value="<?= $value ?>" <?= $form['format'] === $value ? 'checked' : '' ?>>
                                <strong><?= e($label) ?></strong>
                                <div class="small text-muted mt-1"><?= e($formatHelp[$value]) ?></div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= err($errors, 'format') ?>
                <div class="mt-3">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3" placeholder="Optional notes about the competition"><?= e($form['description']) ?></textarea>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <a class="btn btn-outline-secondary" href="<?= url('admin/competitions.php') ?>">Cancel</a>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header">Next steps</div>
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action <?= $id ? '' : 'disabled' ?>" href="<?= url('admin/categories.php') ?>">1. Add categories and age limits</a>
                <a class="list-group-item list-group-item-action <?= $id ? '' : 'disabled' ?>" href="<?= url('admin/rules.php') ?>">2. Set competition rules</a>
                <a class="list-group-item list-group-item-action <?= $id ? '' : 'disabled' ?>" href="<?= url('admin/venues.php') ?>">3. Add venues and match slots</a>
                <span class="list-group-item text-muted">4. Open team registration <small>(Module 4)</small></span>
                <a class="list-group-item list-group-item-action <?= $id ? '' : 'disabled' ?>" href="<?= url('admin/groups.php') ?>">5. Create groups after the draw</a>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Existing competitions</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($existing as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center"><?= e($c['name']) ?>
                        <span class="badge text-bg-light border"><?= ucfirst($c['status']) ?></span></li>
                <?php endforeach; ?>
                <?php if (!$existing): ?><li class="list-group-item text-muted">None yet</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
