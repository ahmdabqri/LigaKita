<?php
/**
 * lk_4004 View squad status (Module 4.0, sub-process 4.4). Screen: Figure 4.21.
 * Shows the team manager which approved players can play in the next match:
 * Eligible, Suspended, or At risk (one more yellow card means a ban).
 */
require __DIR__ . '/../includes/init.php';
$user = require_login('team_manager');
$team = current_team($pdo, $user);
if (!$team) {
    flash('info', 'Register your team first.');
    redirect('manager/team.php');
}
$data = squad_status($pdo, $team);
$players = $data['players'];
$count = array_count_values(array_column($players, 'state')) + ['Eligible' => 0, 'Suspended' => 0, 'At risk' => 0];
$filter = in_array($_GET['status'] ?? '', ['Eligible', 'Suspended', 'At risk'], true) ? $_GET['status'] : '';
$shown = $filter ? array_filter($players, fn ($p) => $p['state'] === $filter) : $players;
$next = $data['next'];

$pageTitle = 'Squad Status';
$active = 'Squad Status';
require __DIR__ . '/../includes/header.php';
$badge = ['Eligible' => 'text-bg-light border', 'Suspended' => 'text-bg-dark', 'At risk' => 'text-bg-warning'];
?>
<div class="page-head">
    <h1>Squad Status</h1>
    <p>
        <?= e($team['team_name']) ?> (<?= e($team['cat_name']) ?>) ·
        <?php if ($next): ?>
            Who can play in the next match: <?= e($next['stage'] === 'group' ? ($next['group_name'] ? 'Group ' . $next['group_name'] : 'Group stage') : STAGES[$next['stage']]) ?>,
            <?= date('D d/m/Y', strtotime($next['slot_date'])) ?> <?= date('g:i a', strtotime($next['kickoff'])) ?> v <?= e($next['opponent'] ?? 'to be decided') ?>, <?= e($next['venue']) ?>
        <?php else: ?>
            No match is scheduled for the team yet.
        <?php endif; ?>
    </p>
</div>
<?php if ($team['reg_status'] !== 'approved'): ?>
    <div class="alert alert-info">The squad status is shown once the committee approves the team. Current status: <?= status_badge($team['reg_status']) ?></div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ([['Eligible to play', $count['Eligible'], 'of ' . count($players) . ' approved players'],
                    ['Suspended', $count['Suspended'], 'Misses the next match'],
                    ['At risk', $count['At risk'], $data['limit'] > 1 ? 'One more yellow card means a ban' : 'Not used when one yellow card means a ban']] as [$label, $value, $note]): ?>
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small fw-semibold"><?= $label ?></div>
                <div class="stat mt-1"><?= $value ?></div>
                <div class="small text-muted"><?= e($note) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span class="me-auto">Players</span>
        <form method="get">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Status">
                <option value="">Status: All</option>
                <?php foreach (['Eligible', 'Suspended', 'At risk'] as $st): ?>
                    <option value="<?= $st ?>" <?= $filter === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>No.</th><th>Player</th><th>Position</th><th>Goals</th><th>Yellow</th><th>Red</th><th>Next match</th><th>Note</th></tr></thead>
            <tbody>
            <?php if (!$shown): ?><tr><td colspan="8" class="text-center text-muted py-4">No players to show.</td></tr><?php endif; ?>
            <?php foreach ($shown as $p): ?>
                <tr class="<?= $p['state'] === 'Suspended' ? 'table-light' : '' ?>">
                    <td><?= $p['jersey_no'] ?></td>
                    <td><?= e($p['full_name']) ?></td>
                    <td><?= e(POSITIONS[$p['position']] ?? '–') ?></td>
                    <td><?= (int) $p['goals'] ?></td>
                    <td><?= (int) $p['yellows'] ?></td>
                    <td><?= (int) $p['reds'] ?></td>
                    <td><span class="badge <?= $badge[$p['state']] ?>"><?= $p['state'] ?></span></td>
                    <td class="small"><?= e($p['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">
        Showing <?= count($shown) ?> of <?= count($players) ?> players. The team has played <?= $data['played'] ?> match(es).
        A ban follows <?= $data['limit'] ?> yellow cards or a red card. The status updates as soon as a match result is saved.
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
