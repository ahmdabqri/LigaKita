<?php
/**
 * lk_4002 Register player and check eligibility (Module 4.0, sub-process 4.2). Screen: Figure 4.10.
 * Each player is checked when Add Player is pressed: age limit, registration with another team in the
 * same competition, jersey number and squad size. The team is then submitted for approval from here.
 */
require __DIR__ . '/../includes/init.php';
$user = require_login('team_manager');
$team = current_team($pdo, $user);
if (!$team) {
    flash('info', 'Register your team first, then add its players.');
    redirect('manager/team.php');
}
$teamId = (int) $team['team_id'];
$editable = team_editable($team);

$editId = (int) ($_GET['edit'] ?? $_POST['team_player_id'] ?? 0);
$editRow = null;
if ($editId) {
    $s = $pdo->prepare('SELECT tp.*, p.full_name, p.id_number, p.date_of_birth, p.photo_path
                          FROM team_players tp JOIN players p ON p.player_id = tp.player_id
                         WHERE tp.team_player_id = ? AND tp.team_id = ?');
    $s->execute([$editId, $teamId]);
    $editRow = $s->fetch() ?: null;
    $editId = $editRow ? $editId : 0;
}

$form = ['full_name' => '', 'date_of_birth' => '', 'id_number' => '', 'jersey_no' => '', 'position' => ''];
if ($editRow) {
    $form = ['full_name' => $editRow['full_name'], 'date_of_birth' => $editRow['date_of_birth'], 'id_number' => format_id_number($editRow['id_number']),
             'jersey_no' => (string) $editRow['jersey_no'], 'position' => (string) $editRow['position']];
}
$errors = [];
$alert = null;

if (is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if (!$editable) {
        flash('warning', 'The players of this team can no longer be changed.');
        redirect('manager/players.php');
    }

    if ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $s = $pdo->prepare('SELECT tp.player_id, p.full_name, p.photo_path FROM team_players tp JOIN players p ON p.player_id = tp.player_id WHERE tp.team_player_id = ? AND tp.team_id = ?');
        $s->execute([$id, $teamId]);
        if ($row = $s->fetch()) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM team_players WHERE team_player_id = ?')->execute([$id]);
            // Remove the player record too if no other team uses it.
            $s = $pdo->prepare('SELECT COUNT(*) FROM team_players WHERE player_id = ?');
            $s->execute([$row['player_id']]);
            if ($s->fetchColumn() == 0) {
                $pdo->prepare('DELETE FROM players WHERE player_id = ?')->execute([$row['player_id']]);
                delete_upload($row['photo_path']);
            }
            $pdo->commit();
            flash('success', $row['full_name'] . ' removed from the team.');
        }
        redirect('manager/players.php');
    }

    if ($action === 'submit') {
        $checks = registration_checks($pdo, $team);
        if (!checks_pass($checks)) {
            flash('danger', 'The team cannot be submitted yet. Fix the items marked in red first.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE teams SET reg_status = 'pending', submitted_at = NOW(), reject_reason = NULL WHERE team_id = ? AND manager_id = ?")
                ->execute([$teamId, $user['user_id']]);
            $pdo->prepare("UPDATE team_players SET status = 'pending' WHERE team_id = ?")->execute([$teamId]);
            $pdo->commit();
            flash('success', $team['team_name'] . ' has been submitted. The committee will review the registration.');
        }
        redirect('manager/players.php');
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $k) {
            $form[$k] = input($k);
        }
        $jersey = filter_var($form['jersey_no'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($jersey === false) {
            $errors['jersey_no'] = 'Enter a number from 1 to 99.';
        } else {
            $s = $pdo->prepare('SELECT p.full_name FROM team_players tp JOIN players p ON p.player_id = tp.player_id WHERE tp.team_id = ? AND tp.jersey_no = ? AND tp.team_player_id <> ?');
            $s->execute([$teamId, $jersey, $editId]);
            if ($other = $s->fetchColumn()) {
                $errors['jersey_no'] = "Jersey number $jersey is already used by $other.";
            }
        }
        if ($form['position'] !== '' && !isset(POSITIONS[$form['position']])) {
            $errors['position'] = 'Choose a position.';
        }

        if ($editRow) {
            // Only the jersey number, position and photo can be changed after a player is added.
            if (!$errors) {
                [$photo, $photoError] = save_image_upload('photo', 'photos', 'player');
                if ($photoError) {
                    $errors['photo'] = $photoError;
                }
            }
            if (!$errors) {
                $pdo->prepare('UPDATE team_players SET jersey_no = ?, position = ? WHERE team_player_id = ?')
                    ->execute([$jersey, $form['position'] ?: null, $editId]);
                if ($photo) {
                    delete_upload($editRow['photo_path']);
                    $pdo->prepare('UPDATE players SET photo_path = ? WHERE player_id = ?')->execute([$photo, $editRow['player_id']]);
                }
                flash('success', $editRow['full_name'] . ' updated.');
                redirect('manager/players.php');
            }
        } else {
            // 1. Required fields
            if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
                $errors['full_name'] = 'Enter the full name as in the MyKad or birth certificate.';
            }
            $dob = valid_date($form['date_of_birth']);
            $idNo = clean_id_number($form['id_number']);
            if (!$dob) {
                $errors['date_of_birth'] = 'Enter the date of birth.';
            } elseif ($dob < $team['born_on_or_after']) {
                // 2. Age limit of the category
                $errors['date_of_birth'] = 'Must be on or after ' . format_date($team['born_on_or_after']) . '.';
                $alert = 'Player is over the age limit for ' . $team['cat_name'] . '.';
            } elseif ($dob > date('Y-m-d')) {
                $errors['date_of_birth'] = 'The date of birth cannot be in the future.';
            }
            if (!$idNo) {
                $errors['id_number'] = 'Enter the 12-digit MyKad or birth certificate number.';
            } elseif ($dob && !isset($errors['date_of_birth']) && id_birth_date($idNo) !== $dob) {
                $errors['id_number'] = 'The first six digits do not match the date of birth.';
            }

            $existing = null;
            if (!$errors) {
                $idFormatted = format_id_number($idNo);
                $s = $pdo->prepare('SELECT * FROM players WHERE id_number = ?');
                $s->execute([$idFormatted]);
                $existing = $s->fetch() ?: null;
                if ($existing) {
                    // 3. Already registered with a team in this competition?
                    $s = $pdo->prepare('SELECT t.team_id, t.team_name FROM team_players tp JOIN teams t ON t.team_id = tp.team_id
                                          JOIN categories k ON k.category_id = t.category_id
                                         WHERE tp.player_id = ? AND k.competition_id = ?');
                    $s->execute([$existing['player_id'], $team['competition_id']]);
                    if ($reg = $s->fetch()) {
                        $alert = $reg['team_id'] == $teamId ? 'This player is already in your team.' : 'Player is already registered with ' . $reg['team_name'] . '.';
                        $errors['id_number'] = 'A player can only play for one team in a competition.';
                    } elseif ($existing['date_of_birth'] !== $dob) {
                        $errors['id_number'] = 'This number is already recorded with a different date of birth. Contact the committee.';
                    }
                }
            }
            // 5. Squad size
            if (!$errors && $team['n_player'] >= $team['max_squad']) {
                $alert = 'Squad is full. The maximum for ' . $team['cat_name'] . ' is ' . $team['max_squad'] . ' players.';
                $errors['squad'] = true;
            }
            if (!$errors) {
                [$photo, $photoError] = save_image_upload('photo', 'photos', 'player');
                if ($photoError) {
                    $errors['photo'] = $photoError;
                }
            }
            // 6. Save
            if (!$errors) {
                $pdo->beginTransaction();
                if ($existing) {
                    $playerId = (int) $existing['player_id'];
                    if ($photo) {
                        delete_upload($existing['photo_path']);
                        $pdo->prepare('UPDATE players SET photo_path = ? WHERE player_id = ?')->execute([$photo, $playerId]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO players (full_name, id_number, date_of_birth, photo_path) VALUES (?, ?, ?, ?)')
                        ->execute([$form['full_name'], $idFormatted, $dob, $photo]);
                    $playerId = (int) $pdo->lastInsertId();
                }
                $pdo->prepare("INSERT INTO team_players (team_id, player_id, jersey_no, position, status) VALUES (?, ?, ?, ?, 'pending')")
                    ->execute([$teamId, $playerId, $jersey, $form['position'] ?: null]);
                $pdo->commit();
                flash('success', ($existing ? $existing['full_name'] : $form['full_name']) . ' added with jersey number ' . $jersey . '.');
                redirect('manager/players.php');
            }
        }
        $alert ??= 'Please correct the fields marked in red.';
    }
}

$players = team_players($pdo, $teamId);
$checks = registration_checks($pdo, $team);
$canSubmit = $editable && checks_pass($checks);

$pageTitle = 'Register Player';
$active = 'Players';
$crumb = 'Players / Register Player';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Register Player</h1>
    <p><?= e($team['team_name']) ?> · Category <?= e($team['cat_name']) ?> (born on or after <?= format_date($team['born_on_or_after']) ?>) · <?= status_badge($team['reg_status']) ?></p>
</div>
<?php if ($team['reg_status'] === 'rejected'): ?>
    <div class="alert alert-danger"><strong>Returned by the committee.</strong> Reason: <?= e((string) $team['reject_reason']) ?></div>
<?php elseif ($team['reg_status'] === 'pending'): ?>
    <div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>Submitted on <?= format_date($team['submitted_at']) ?>. The players cannot be changed while the committee reviews the team.</div>
<?php elseif ($team['reg_status'] === 'approved'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>This team is approved. Contact the committee to change a player.</div>
<?php elseif (!$editable): ?>
    <div class="alert alert-secondary">Registration for <?= e($team['cat_name']) ?> closed on <?= format_date($team['reg_closes']) ?>.</div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($editable): ?>
    <div class="col-xl-5">
        <form method="post" enctype="multipart/form-data" class="card" novalidate id="player-form">
            <div class="card-header"><?= $editRow ? 'Edit ' . e($editRow['full_name']) : 'Player Details' ?></div>
            <div class="card-body">
                <?php if ($alert): ?><div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-circle me-1"></i><?= e($alert) ?></div><?php endif; ?>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="team_player_id" value="<?= $editId ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= 2 * 1024 * 1024 ?>">
                <div class="mb-3">
                    <label class="form-label req" for="full_name">Full name (as in MyKad / birth certificate)</label>
                    <input class="form-control <?= inv($errors, 'full_name') ?>" id="full_name" name="full_name" maxlength="100" value="<?= e($form['full_name']) ?>" placeholder="Player full name" <?= $editRow ? 'readonly' : '' ?>>
                    <?= err($errors, 'full_name') ?>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label req" for="date_of_birth">Date of birth</label>
                        <input class="form-control <?= inv($errors, 'date_of_birth') ?>" type="date" id="date_of_birth" name="date_of_birth" value="<?= e($form['date_of_birth']) ?>" <?= $editRow ? 'readonly' : '' ?>>
                        <?= err($errors, 'date_of_birth') ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label req" for="id_number">MyKad / Birth cert. no.</label>
                        <input class="form-control <?= inv($errors, 'id_number') ?>" id="id_number" name="id_number" maxlength="14" inputmode="numeric" value="<?= e($form['id_number']) ?>" placeholder="XXXXXX-XX-XXXX" <?= $editRow ? 'readonly' : '' ?>>
                        <?= err($errors, 'id_number') ?>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label req" for="jersey_no">Jersey number</label>
                        <input class="form-control <?= inv($errors, 'jersey_no') ?>" type="number" min="1" max="99" id="jersey_no" name="jersey_no" value="<?= e($form['jersey_no']) ?>">
                        <?= err($errors, 'jersey_no') ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="position">Position</label>
                        <select class="form-select <?= inv($errors, 'position') ?>" id="position" name="position">
                            <option value="">Not set</option>
                            <?php foreach (POSITIONS as $k => $label): ?>
                                <option value="<?= $k ?>" <?= $form['position'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= err($errors, 'position') ?>
                    </div>
                </div>
                <label class="form-label" for="photo">Photo</label>
                <div class="d-flex gap-3 align-items-start">
                    <div class="upload-thumb"><?= !empty($editRow['photo_path']) ? '<img src="' . e(url($editRow['photo_path'])) . '" alt="Photo">' : '<i class="bi bi-person"></i>' ?></div>
                    <div class="flex-grow-1">
                        <input class="form-control <?= inv($errors, 'photo') ?>" type="file" id="photo" name="photo" accept="image/png,image/jpeg">
                        <?= err($errors, 'photo') ?: '<div class="form-text">JPG or PNG, max 2 MB</div>' ?>
                    </div>
                </div>
                <?php if ($editRow): ?><div class="form-text mt-3">To correct the name, date of birth or MyKad number, remove the player and add them again.</div><?php endif; ?>
            </div>
            <div class="card-footer bg-white text-end">
                <a class="btn btn-outline-secondary" href="<?= url('manager/players.php') ?>">Cancel</a>
                <button class="btn btn-primary" type="submit"><?= $editRow ? 'Save Player' : 'Add Player' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="<?= $editable ? 'col-xl-7' : 'col-12' ?>">
        <div class="card mb-4">
            <div class="card-header">Registered Players (<?= count($players) ?> of <?= $team['max_squad'] ?>)</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>No.</th><th>Name</th><th>Date of birth</th><th>Position</th><th>Status</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php if (!$players): ?><tr><td colspan="6" class="text-center text-muted py-4">No players yet. Add at least <?= $team['min_squad'] ?> players.</td></tr><?php endif; ?>
                    <?php foreach ($players as $p): ?>
                        <tr class="<?= $p['team_player_id'] == $editId ? 'table-active' : '' ?>">
                            <td><strong><?= $p['jersey_no'] ?></strong></td>
                            <td class="text-nowrap">
                                <?= $p['photo_path'] ? '<img class="player-thumb me-2" src="' . e(url($p['photo_path'])) . '" alt="">' : '<span class="player-thumb me-2"><i class="bi bi-person"></i></span>' ?><?= e($p['full_name']) ?>
                            </td>
                            <td><?= format_date($p['date_of_birth']) ?></td>
                            <td><?= e(POSITIONS[$p['position']] ?? '–') ?></td>
                            <td><?= status_badge($p['status']) ?></td>
                            <?php if ($editable): ?>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $p['team_player_id'] ?>" title="Edit"><i class="bi bi-pencil"></i><span class="visually-hidden">Edit</span></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove this player from the team?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $p['team_player_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i><span class="visually-hidden">Remove</span></button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($editable): ?>
        <div class="card">
            <div class="card-header">Submit for approval</div>
            <div class="card-body">
                <ul class="check-list list-unstyled mb-3">
                    <?php foreach ($checks as [$ok, $text]): ?>
                        <li><i class="bi <?= $ok ? 'bi-check-lg' : 'bi-exclamation-circle-fill' ?>"></i><?= e($text) ?></li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" class="d-flex flex-wrap align-items-center gap-3" onsubmit="return confirm('Submit the team for approval? You cannot change it while it is reviewed.')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="submit">
                    <button class="btn btn-primary" <?= $canSubmit ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i>Submit for Approval</button>
                    <span class="small text-muted">Registration closes on <?= format_date($team['reg_closes']) ?>.</span>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="small text-muted mt-3">
            <strong>Rules checked when a player is added:</strong>
            <ul class="mb-0">
                <li>Date of birth must meet the category age limit</li>
                <li>A player cannot be registered in two teams in the same competition</li>
                <li>Each jersey number can be used only once in a team</li>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
