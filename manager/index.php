<?php
/**
 * Team manager dashboard. Team and player registration (Module 4) will be added here.
 */
require __DIR__ . '/../includes/init.php';
$user = require_login('team_manager');

$s = $pdo->prepare('SELECT club_name FROM users WHERE user_id = ?');
$s->execute([$user['user_id']]);
$club = $s->fetchColumn();
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
        <div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>Team and player registration will be available here soon.</div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
