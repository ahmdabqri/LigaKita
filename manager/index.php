<?php
/**
 * Team manager dashboard: the manager's teams with their registration status and next step,
 * and the competitions that are open for registration.
 */
require __DIR__ . '/../includes/init.php';
$user = require_login('team_manager');

$s = $pdo->prepare('SELECT club_name FROM users WHERE user_id = ?');
$s->execute([$user['user_id']]);
$club = $s->fetchColumn();
$teams = my_teams($pdo, (int) $user['user_id']);
$open = $pdo->query("SELECT c.name AS comp, k.name AS cat, k.reg_closes FROM categories k JOIN competitions c ON c.competition_id = k.competition_id
                     WHERE k.reg_closes >= CURDATE() ORDER BY k.reg_closes, c.name, k.name")->fetchAll();

$pageTitle = 'Dashboard';
$active = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Welcome, <?= e($user['full_name']) ?></h1>
    <p><?= $club ? e($club) . ' · ' : '' ?>Team manager</p>
</div>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Competitions open for registration</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Competition</th><th>Category</th><th>Registration closes</th></tr></thead>
                    <tbody>
                    <?php if (!$open): ?><tr><td colspan="3" class="text-center text-muted py-4">No competition is open for registration now.</td></tr><?php endif; ?>
                    <?php foreach ($open as $o): ?>
                        <tr><td><?= e($o['comp']) ?></td><td><?= e($o['cat']) ?></td><td><?= format_date($o['reg_closes']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">My teams
                <?php if ($open): ?><a class="btn btn-sm btn-primary" href="<?= url('manager/team.php?new=1') ?>"><i class="bi bi-plus-lg"></i> Register a team</a><?php endif; ?>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (!$teams): ?><li class="list-group-item text-muted py-4 text-center">You have not registered a team yet.</li><?php endif; ?>
                <?php foreach ($teams as $t):
                    [$next, $link] = match ($t['reg_status']) {
                        'draft' => $t['n_player'] < $t['min_squad'] ? ['Add players (' . $t['n_player'] . ' of ' . $t['min_squad'] . ')', 'manager/players.php'] : ['Submit for approval', 'manager/players.php'],
                        'rejected' => ['Fix and submit again', 'manager/players.php'],
                        'pending' => ['Waiting for the committee', 'manager/players.php'],
                        default => ['View squad status', 'manager/squad.php'],
                    }; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between"><strong><?= e($t['team_name']) ?></strong><?= status_badge($t['reg_status']) ?></div>
                        <div class="small text-muted"><?= e($t['comp_name']) ?> · <?= e($t['cat_name']) ?> · <?= $t['n_player'] ?> players</div>
                        <a class="small" href="<?= url($link . '?ctx_team=' . $t['team_id']) ?>"><?= e($next) ?> <i class="bi bi-arrow-right"></i></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
