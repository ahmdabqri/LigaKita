<?php
/**
 * lk_4003 Approve registration (Module 4.0, sub-process 4.3). Screen: Figure 4.8.
 * Lists the submitted teams of the category chosen in the top bar. Review runs the automatic checks
 * again; the administrator approves the team or returns it to the manager with a reason.
 */
require __DIR__ . '/../includes/init.php';
require_login('admin');
handle_context_switch($pdo);
$comp = current_competition($pdo);
$cat = current_category($pdo);

$tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'pending';
$reviewId = (int) ($_GET['review'] ?? $_POST['team_id'] ?? 0);

/** A team of the selected category with the fields that the checks need. */
function load_team(PDO $pdo, int $id, int $catId): ?array
{
    $s = $pdo->prepare('SELECT t.*, k.name AS cat_name, k.min_squad, k.max_squad, k.born_on_or_after, k.competition_id, k.reg_closes,
                               u.full_name AS manager_name, u.phone AS manager_phone,
                               (SELECT COUNT(*) FROM team_players tp WHERE tp.team_id = t.team_id) AS n_player,
                               (SELECT COUNT(*) FROM group_teams gt WHERE gt.team_id = t.team_id) AS in_group
                          FROM teams t JOIN categories k ON k.category_id = t.category_id JOIN users u ON u.user_id = t.manager_id
                         WHERE t.team_id = ? AND t.category_id = ?');
    $s->execute([$id, $catId]);
    return $s->fetch() ?: null;
}

$errors = [];
$reason = '';
if ($cat && is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $team = load_team($pdo, $reviewId, (int) $cat['category_id']);
    if (!$team) {
        redirect('admin/registrations.php');
    }

    if ($action === 'approve') {
        if ($team['reg_status'] !== 'pending') {
            flash('warning', 'Only a pending registration can be approved.');
        } elseif (!checks_pass(registration_checks($pdo, $team))) {
            flash('danger', $team['team_name'] . ' cannot be approved because some checks failed. Return it to the manager with a reason.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE teams SET reg_status = 'approved', reject_reason = NULL WHERE team_id = ?")->execute([$team['team_id']]);
            $pdo->prepare("UPDATE team_players SET status = 'approved' WHERE team_id = ?")->execute([$team['team_id']]);
            $pdo->commit();
            flash('success', $team['team_name'] . ' approved with ' . $team['n_player'] . ' players.');
        }
        redirect('admin/registrations.php?tab=approved&review=' . $team['team_id']);
    }

    if ($action === 'reject') {
        $reason = input('reason');
        if ($team['in_group']) {
            flash('danger', $team['team_name'] . ' is already placed in a group. Remove it from the group first.');
            redirect('admin/registrations.php?tab=' . $team['reg_status'] . '&review=' . $team['team_id']);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            $errors['reason'] = 'Enter the reason (up to 255 characters) so the manager knows what to fix.';
            $tab = $team['reg_status'];
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE teams SET reg_status = 'rejected', reject_reason = ? WHERE team_id = ?")->execute([$reason, $team['team_id']]);
            $pdo->prepare("UPDATE team_players SET status = 'pending' WHERE team_id = ?")->execute([$team['team_id']]);
            $pdo->commit();
            flash('success', $team['team_name'] . ' returned to the team manager.');
            redirect('admin/registrations.php?tab=rejected&review=' . $team['team_id']);
        }
    }
}

$counts = array_fill_keys(array_keys($tabs), 0) + ['draft' => 0];
$list = [];
$review = null;
if ($cat) {
    $s = $pdo->prepare('SELECT reg_status, COUNT(*) FROM teams WHERE category_id = ? GROUP BY reg_status');
    $s->execute([$cat['category_id']]);
    $counts = array_merge($counts, $s->fetchAll(PDO::FETCH_KEY_PAIR));

    $s = $pdo->prepare('SELECT t.team_id, t.team_name, t.submitted_at, t.reg_status, u.full_name AS manager_name,
                               (SELECT COUNT(*) FROM team_players tp WHERE tp.team_id = t.team_id) AS n_player
                          FROM teams t JOIN users u ON u.user_id = t.manager_id
                         WHERE t.category_id = ? AND t.reg_status = ? ORDER BY t.submitted_at, t.team_name');
    $s->execute([$cat['category_id'], $tab]);
    $list = $s->fetchAll();

    if ($reviewId) {
        $review = load_team($pdo, $reviewId, (int) $cat['category_id']);
        if ($review && $review['reg_status'] === 'draft') {
            $review = null;
        }
    }
    if ($review) {
        $checks = registration_checks($pdo, $review);
        $players = team_players($pdo, (int) $review['team_id']);
    }
}

$pageTitle = 'Team Registrations';
$active = 'Registrations';
$crumb = 'Teams / Registrations';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Team Registrations</h1>
    <p>Check each team and its players, then approve or reject the registration.</p>
</div>
<?php if (!$comp): need_setup('competition'); elseif (!$cat): need_setup('category'); else: ?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><?= $label ?> (<?= $counts[$k] ?>)</a></li>
    <?php endforeach; ?>
</ul>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header"><?= ['pending' => 'Waiting for approval', 'approved' => 'Approved teams', 'rejected' => 'Returned to the manager'][$tab] ?> · <?= e($cat['name']) ?></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Team</th><th>Players</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$list): ?><tr><td colspan="5" class="text-center text-muted py-4">No <?= strtolower($tabs[$tab]) ?> teams in <?= e($cat['name']) ?>.</td></tr><?php endif; ?>
                    <?php foreach ($list as $t): $short = $t['n_player'] < $cat['min_squad']; ?>
                        <tr class="<?= $review && $review['team_id'] == $t['team_id'] ? 'table-active' : '' ?>">
                            <td><strong><?= e($t['team_name']) ?></strong><br><small class="text-muted"><?= e($t['manager_name']) ?></small></td>
                            <td><?= $t['n_player'] ?></td>
                            <td><?= format_date($t['submitted_at']) ?></td>
                            <td><?= $tab === 'pending' && $short ? '<span class="badge text-bg-secondary">Incomplete</span>' : status_badge($t['reg_status']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?tab=<?= $tab ?>&review=<?= $t['team_id'] ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                Incomplete: squad is below the minimum of <?= $cat['min_squad'] ?> players.
                <?php if ($counts['draft']): ?><br><?= $counts['draft'] ?> team(s) are still drafts and have not been submitted.<?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <?php if (!$review): ?>
            <div class="card"><div class="card-body text-muted text-center py-5">Choose Review to check a team.</div></div>
        <?php else: ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">Review: <?= e($review['team_name']) ?> <?= status_badge($review['reg_status']) ?></div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-4 fw-normal text-muted">Manager</dt><dd class="col-8"><strong><?= e($review['manager_name']) ?></strong> · <?= e($review['manager_phone']) ?></dd>
                    <dt class="col-4 fw-normal text-muted">Organisation</dt><dd class="col-8"><?= e($review['organisation'] ?: '–') ?></dd>
                    <dt class="col-4 fw-normal text-muted">Head coach</dt><dd class="col-8"><?= e($review['coach_name'] ?: '–') ?><?= $review['coach_phone'] ? ' · ' . e($review['coach_phone']) : '' ?></dd>
                    <dt class="col-4 fw-normal text-muted">Category</dt><dd class="col-8"><?= e($review['cat_name']) ?></dd>
                    <dt class="col-4 fw-normal text-muted">Players</dt><dd class="col-8"><strong><?= $review['n_player'] ?></strong> (min <?= $review['min_squad'] ?>, max <?= $review['max_squad'] ?>)</dd>
                    <?php if ($review['reject_reason']): ?><dt class="col-4 fw-normal text-muted">Reason returned</dt><dd class="col-8 text-danger"><?= e($review['reject_reason']) ?></dd><?php endif; ?>
                </dl>
                <h6 class="fw-semibold">Automatic checks</h6>
                <ul class="check-list list-unstyled mb-3">
                    <?php foreach ($checks as [$ok, $text]): ?>
                        <li><i class="bi <?= $ok ? 'bi-check-lg' : 'bi-exclamation-circle-fill' ?>"></i><?= e($text) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="small" data-bs-toggle="collapse" href="#player-list" role="button">View player list (<?= count($players) ?>) <i class="bi bi-chevron-down"></i></a>
                <div class="collapse mt-2" id="player-list">
                    <div class="table-responsive">
                        <table class="table table-sm small mb-0">
                            <thead><tr><th>No.</th><th>Name</th><th>MyKad / birth cert.</th><th>Date of birth</th><th>Position</th></tr></thead>
                            <tbody>
                            <?php foreach ($players as $p): $old = $p['date_of_birth'] < $review['born_on_or_after']; ?>
                                <tr class="<?= $old ? 'table-danger' : '' ?>"><td><?= $p['jersey_no'] ?></td><td><?= e($p['full_name']) ?></td><td><?= e($p['id_number']) ?></td><td><?= format_date($p['date_of_birth']) ?></td><td><?= e(POSITIONS[$p['position']] ?? '–') ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php if ($review['reg_status'] !== 'rejected' && !$review['in_group']): ?>
            <form method="post" class="card-footer bg-white" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="team_id" value="<?= $review['team_id'] ?>">
                <label class="form-label" for="reason">Reason <?= $review['reg_status'] === 'pending' ? '(required if rejected)' : '(required to return the team)' ?></label>
                <textarea class="form-control mb-3 <?= inv($errors, 'reason') ?>" id="reason" name="reason" rows="3" maxlength="255" placeholder="Explain what the team must fix"><?= e($reason) ?></textarea>
                <?= err($errors, 'reason') ?>
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-danger" name="action" value="reject"><?= $review['reg_status'] === 'pending' ? 'Reject' : 'Return to Manager' ?></button>
                    <?php if ($review['reg_status'] === 'pending'): ?>
                        <button class="btn btn-primary" name="action" value="approve" <?= checks_pass($checks) ? '' : 'disabled title="Some checks failed"' ?>>Approve</button>
                    <?php endif; ?>
                </div>
            </form>
            <?php elseif ($review['in_group']): ?>
                <div class="card-footer bg-white small text-muted">This team is placed in a group, so its registration can no longer be changed here.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
