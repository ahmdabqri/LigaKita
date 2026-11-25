<?php
/**
 * lk_4001 Register team (Module 4.0, sub-process 4.1). Screen: Figure 4.7.
 * A team manager enters the details of a team for a category that is open for registration.
 * ?new=1 starts a new team; otherwise the team chosen in the top bar is shown.
 */
require __DIR__ . '/../includes/init.php';
$user = require_login('team_manager');
$team = isset($_GET['new']) || ($_POST['team_id'] ?? '') === '0' ? null : current_team($pdo, $user);
if ($team && is_post() && (int) ($_POST['team_id'] ?? 0) !== (int) $team['team_id']) {
    flash('warning', 'The team was changed in another tab. Please try again.');
    redirect('manager/team.php');
}
$editable = !$team || team_editable($team);

// Categories still open for registration (reg_closes is today or later), plus the team's own category.
$s = $pdo->prepare('SELECT k.category_id, k.name, k.reg_closes, k.min_squad, k.max_squad, c.competition_id, c.name AS comp_name, c.start_date, c.end_date
                      FROM categories k JOIN competitions c ON c.competition_id = k.competition_id
                     WHERE k.reg_closes >= CURDATE() OR k.category_id = ?
                  ORDER BY c.start_date, c.name, k.name');
$s->execute([$team['category_id'] ?? 0]);
$cats = array_column($s->fetchAll(), null, 'category_id');
$comps = [];
foreach ($cats as $c) {
    $comps[$c['competition_id']] = $c['comp_name'];
}

$fields = ['category_id', 'team_name', 'short_name', 'organisation', 'coach_name', 'coach_phone', 'home_kit', 'away_kit'];
$form = array_fill_keys($fields, '');
$form['organisation'] = '';
if ($team) {
    foreach ($fields as $k) {
        $form[$k] = (string) ($team[$k] ?? '');
    }
} else {
    $s = $pdo->prepare('SELECT club_name FROM users WHERE user_id = ?');
    $s->execute([$user['user_id']]);
    $form['team_name'] = (string) $s->fetchColumn();
}
$errors = [];

if (is_post()) {
    check_csrf();
    if (!$editable) {
        flash('warning', 'This team can no longer be changed.');
        redirect('manager/team.php');
    }
    foreach ($fields as $k) {
        $form[$k] = input($k);
    }
    $full = ($_POST['action'] ?? '') === 'save_next';
    $catId = (int) $form['category_id'];
    $cat = $cats[$catId] ?? null;

    if (!$cat || ($cat['reg_closes'] < date('Y-m-d'))) {
        $errors['category_id'] = 'Choose a category that is open for registration.';
    } elseif ($team && $catId !== (int) $team['category_id'] && $team['n_player'] > 0) {
        $errors['category_id'] = 'Remove the players first to move the team to another category.';
    }
    if ($form['team_name'] === '' || mb_strlen($form['team_name']) > 100) {
        $errors['team_name'] = 'Team name is required (up to 100 characters).';
    } elseif ($cat) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE category_id = ? AND team_name = ? AND team_id <> ?');
        $s->execute([$catId, $form['team_name'], $team['team_id'] ?? 0]);
        if ($s->fetchColumn() > 0) {
            $errors['team_name'] = 'This team name is already used in ' . $cat['name'] . '.';
        }
    }
    if (mb_strlen($form['short_name']) > 10) {
        $errors['short_name'] = 'Up to 10 characters.';
    }
    if ($full && $form['organisation'] === '') {
        $errors['organisation'] = 'Enter the club, school or organisation.';
    } elseif (mb_strlen($form['organisation']) > 150) {
        $errors['organisation'] = 'Up to 150 characters.';
    }
    if ($full && $form['coach_name'] === '') {
        $errors['coach_name'] = 'Enter the name of the head coach.';
    } elseif (mb_strlen($form['coach_name']) > 100) {
        $errors['coach_name'] = 'Up to 100 characters.';
    }
    if ($form['coach_phone'] !== '' && !preg_match('/^[0-9][0-9\- ]{8,13}$/', $form['coach_phone'])) {
        $errors['coach_phone'] = 'Enter a valid phone number, e.g. 012-345 6789.';
    }
    foreach (['home_kit', 'away_kit'] as $k) {
        if (mb_strlen($form[$k]) > 50) {
            $errors[$k] = 'Up to 50 characters.';
        }
    }
    if ($full && empty($_POST['declaration'])) {
        $errors['declaration'] = 'Tick the declaration to continue.';
    }
    [$logo, $logoError] = $errors ? [null, null] : save_image_upload('logo', 'logos', 'team');
    if ($logoError) {
        $errors['logo'] = $logoError;
    }

    if (!$errors) {
        $null = fn ($v) => $v === '' ? null : $v;
        $values = [$catId, $form['team_name'], $null($form['short_name']), $form['organisation'], $form['coach_name'],
                   $null($form['coach_phone']), $null($form['home_kit']), $null($form['away_kit'])];
        if ($team) {
            $pdo->prepare('UPDATE teams SET category_id = ?, team_name = ?, short_name = ?, organisation = ?, coach_name = ?, coach_phone = ?, home_kit = ?, away_kit = ?
                           WHERE team_id = ? AND manager_id = ?')->execute([...$values, $team['team_id'], $user['user_id']]);
            $teamId = (int) $team['team_id'];
            if ($logo) {
                delete_upload($team['logo_path']);
            }
        } else {
            $pdo->prepare("INSERT INTO teams (category_id, team_name, short_name, organisation, coach_name, coach_phone, home_kit, away_kit, manager_id, reg_status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')")->execute([...$values, $user['user_id']]);
            $teamId = (int) $pdo->lastInsertId();
        }
        if ($logo) {
            $pdo->prepare('UPDATE teams SET logo_path = ? WHERE team_id = ?')->execute([$logo, $teamId]);
        }
        $_SESSION['ctx_team'] = $teamId;
        if ($full) {
            flash('success', 'Team details saved. Now add your players.');
            redirect('manager/players.php');
        }
        flash('success', 'Draft saved. You can come back and finish it before registration closes.');
        redirect('manager/team.php');
    }
}

$selCat = $cats[(int) $form['category_id']] ?? null;
$step = !$team ? 2 : ($team['reg_status'] === 'approved' ? 6 : ($team['reg_status'] === 'pending' ? 5 : ($team['n_player'] >= $team['min_squad'] ? 4 : 3)));
$steps = [
    'Account created by committee',
    'Team details' . ($editable ? ' (this page)' : ''),
    'Add players' . ($selCat ? " ({$selCat['min_squad']} to {$selCat['max_squad']})" : ''),
    'Submit for approval',
    'Approved by committee',
];

$pageTitle = 'Register Team';
$active = 'Team Registration';
$crumb = 'Team Registration';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
        <h1><?= $team ? e($team['team_name']) : 'Register Team' ?></h1>
        <p>Enter your team details. Add players next, then submit everything for the committee to approve.</p>
    </div>
    <?php if ($team && $cats): ?>
        <a class="btn btn-outline-secondary" href="?new=1"><i class="bi bi-plus-lg"></i> Register another team</a>
    <?php endif; ?>
</div>

<?php if ($team && $team['reg_status'] === 'rejected'): ?>
    <div class="alert alert-danger"><strong>The committee returned this registration.</strong> Reason: <?= e((string) $team['reject_reason']) ?><br>Fix the details or players, then submit again from the Players page.</div>
<?php elseif ($team && $team['reg_status'] === 'pending'): ?>
    <div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>Submitted on <?= format_date($team['submitted_at']) ?> and waiting for the committee. The details cannot be changed while it is being reviewed.</div>
<?php elseif ($team && $team['reg_status'] === 'approved'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>This team is approved. Contact the committee if any detail must be changed.</div>
<?php elseif ($team && !$editable): ?>
    <div class="alert alert-secondary">Registration for this category closed on <?= format_date($team['reg_closes']) ?>.</div>
<?php endif; ?>

<?php if (!$team && !$cats): ?>
    <div class="alert alert-info">No competition is open for registration now.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-xl-8">
        <form method="post" enctype="multipart/form-data" class="card" novalidate>
            <div class="card-header">Team Details</div>
            <fieldset class="card-body" <?= $editable ? '' : 'disabled' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="team_id" value="<?= (int) ($team['team_id'] ?? 0) ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= 2 * 1024 * 1024 ?>">
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label req" for="comp">Competition</label>
                        <select class="form-select" id="comp">
                            <?php foreach ($comps as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $selCat && $selCat['competition_id'] == $id ? 'selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label req" for="category_id">Category</label>
                        <select class="form-select <?= inv($errors, 'category_id') ?>" id="category_id" name="category_id">
                            <?php foreach ($cats as $c): ?>
                                <option value="<?= $c['category_id'] ?>" data-comp="<?= $c['competition_id'] ?>" <?= $selCat && $selCat['category_id'] == $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= err($errors, 'category_id') ?>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label req" for="team_name">Team name</label>
                        <input class="form-control <?= inv($errors, 'team_name') ?>" id="team_name" name="team_name" maxlength="100" value="<?= e($form['team_name']) ?>">
                        <?= err($errors, 'team_name') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="short_name">Short name</label>
                        <input class="form-control <?= inv($errors, 'short_name') ?>" id="short_name" name="short_name" maxlength="10" value="<?= e($form['short_name']) ?>" placeholder="e.g. BFC">
                        <?= err($errors, 'short_name') ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label req" for="organisation">Club / school / organisation</label>
                    <input class="form-control <?= inv($errors, 'organisation') ?>" id="organisation" name="organisation" maxlength="150" value="<?= e($form['organisation']) ?>" placeholder="e.g. Kelab Bola Sepak Benut">
                    <?= err($errors, 'organisation') ?>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="logo">Team logo</label>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="upload-thumb"><?= !empty($team['logo_path']) ? '<img src="' . e(url($team['logo_path'])) . '" alt="Team logo">' : '<i class="bi bi-shield"></i>' ?></div>
                            <div class="flex-grow-1">
                                <input class="form-control <?= inv($errors, 'logo') ?>" type="file" id="logo" name="logo" accept="image/png,image/jpeg">
                                <?= err($errors, 'logo') ?: '<div class="form-text">JPG or PNG, max 2 MB</div>' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label req" for="coach_name">Head coach name</label>
                        <input class="form-control mb-3 <?= inv($errors, 'coach_name') ?>" id="coach_name" name="coach_name" maxlength="100" value="<?= e($form['coach_name']) ?>" placeholder="Coach full name">
                        <?= err($errors, 'coach_name') ?>
                        <label class="form-label" for="coach_phone">Coach phone number</label>
                        <input class="form-control <?= inv($errors, 'coach_phone') ?>" id="coach_phone" name="coach_phone" maxlength="15" value="<?= e($form['coach_phone']) ?>" placeholder="01X-XXX XXXX">
                        <?= err($errors, 'coach_phone') ?>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="home_kit">Home kit colour</label>
                        <input class="form-control <?= inv($errors, 'home_kit') ?>" id="home_kit" name="home_kit" maxlength="50" value="<?= e($form['home_kit']) ?>" placeholder="e.g. Red / white">
                        <?= err($errors, 'home_kit') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="away_kit">Away kit colour</label>
                        <input class="form-control <?= inv($errors, 'away_kit') ?>" id="away_kit" name="away_kit" maxlength="50" value="<?= e($form['away_kit']) ?>" placeholder="e.g. Blue">
                        <?= err($errors, 'away_kit') ?>
                    </div>
                </div>
                <?php if ($editable): ?>
                <div class="form-check">
                    <input class="form-check-input <?= inv($errors, 'declaration') ?>" type="checkbox" id="declaration" name="declaration" value="1" <?= !empty($_POST['declaration']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="declaration">I confirm the team details are correct and follow the competition rules.</label>
                    <?= err($errors, 'declaration') ?>
                </div>
                <?php endif; ?>
            </fieldset>
            <?php if ($editable): ?>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-outline-secondary" type="submit" name="action" value="save_draft">Save Draft</button>
                <button class="btn btn-primary" type="submit" name="action" value="save_next">Save and Add Players</button>
            </div>
            <?php elseif ($team): ?>
            <div class="card-footer bg-white text-end">
                <a class="btn btn-primary" href="<?= url('manager/players.php') ?>">View Players</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header">Registration progress</div>
            <ol class="steps card-body mb-0">
                <?php foreach ($steps as $i => $label): $n = $i + 1; ?>
                    <li class="<?= $n < $step ? 'done' : ($n === $step ? 'current' : '') ?>"><span class="dot"><?= $n < $step ? '<i class="bi bi-check"></i>' : $n ?></span><?= e($label) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php if ($selCat): ?>
        <div class="card">
            <div class="card-header">Important dates</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Registration closes</span><strong><?= format_date($selCat['reg_closes']) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Competition starts</span><strong><?= format_date($selCat['start_date']) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Competition ends</span><strong><?= format_date($selCat['end_date']) ?></strong></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
// Only show the categories of the chosen competition.
(function () {
    const comp = document.getElementById('comp'), cat = document.getElementById('category_id');
    const filter = () => {
        let first = null;
        for (const o of cat.options) {
            o.hidden = o.dataset.comp !== comp.value;
            if (!o.hidden && !first) first = o;
        }
        if (cat.selectedOptions[0]?.hidden && first) cat.value = first.value;
    };
    comp.addEventListener('change', filter);
    filter();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
