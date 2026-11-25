<?php
/**
 * Administrator dashboard. The full dashboard with graphs is program lk_9001 (Module 9);
 * this version shows the setup progress of the selected competition.
 */
require __DIR__ . '/../includes/init.php';
$admin = require_login('admin');
handle_context_switch($pdo);
$comp = current_competition($pdo);

$count = fn (string $sql, array $p = []) => (function () use ($pdo, $sql, $p) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return (int) $s->fetchColumn();
})();
$stats = [
    ['Competitions', $count('SELECT COUNT(*) FROM competitions'), 'In the system', 'trophy'],
    ['Categories', $comp ? $count('SELECT COUNT(*) FROM categories WHERE competition_id = ?', [$comp['competition_id']]) : 0, 'In the selected competition', 'diagram-3'],
    ['Approved teams', $comp ? $count("SELECT COUNT(*) FROM teams t JOIN categories k ON k.category_id = t.category_id WHERE k.competition_id = ? AND t.reg_status = 'approved'", [$comp['competition_id']]) : 0, 'In the selected competition', 'shield-check'],
    ['Team managers', $count("SELECT COUNT(*) FROM users WHERE role = 'team_manager' AND status = 'active'"), 'Active accounts', 'people'],
];

$pending = $comp ? $count("SELECT COUNT(*) FROM teams t JOIN categories k ON k.category_id = t.category_id WHERE k.competition_id = ? AND t.reg_status = 'pending'", [$comp['competition_id']]) : 0;

$cats = [];
if ($comp) {
    $s = $pdo->prepare("SELECT k.category_id, k.name,
            (SELECT COUNT(*) FROM competition_rules r WHERE r.category_id = k.category_id) AS has_rules,
            (SELECT COUNT(*) FROM teams t WHERE t.category_id = k.category_id AND t.reg_status = 'approved') AS n_team,
            (SELECT COUNT(*) FROM `groups` g WHERE g.category_id = k.category_id) AS n_group
        FROM categories k WHERE k.competition_id = ? ORDER BY k.name");
    $s->execute([$comp['competition_id']]);
    $cats = $s->fetchAll();
}

$pageTitle = 'Dashboard';
$active = 'Dashboard';
require __DIR__ . '/../includes/header.php';
$tick = fn (bool $ok) => $ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>';
?>
<div class="page-head">
    <h1>Dashboard</h1>
    <p><?= $comp ? e($comp['name']) . ' · ' . e(FORMATS[$comp['format']]) . ' · ' . ucfirst($comp['status']) : 'Welcome to LigaKita. Start by creating a competition.' ?></p>
</div>
<?php if ($pending): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center"><span><i class="bi bi-clipboard-check me-1"></i><?= $pending ?> team registration(s) are waiting for approval.</span><a class="btn btn-sm btn-warning" href="<?= url('admin/registrations.php') ?>">Review now</a></div>
<?php endif; ?>
<div class="row g-3 mb-4">
    <?php foreach ($stats as [$label, $value, $note, $icon]): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between text-muted small fw-semibold"><?= e($label) ?><i class="bi bi-<?= $icon ?>"></i></div>
                <div class="stat mt-1"><?= $value ?></div>
                <div class="small text-muted"><?= e($note) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="card">
    <div class="card-header">Setup progress of each category</div>
    <?php if (!$comp): ?>
        <div class="card-body"><?php need_setup('competition'); ?></div>
    <?php elseif (!$cats): ?>
        <div class="card-body"><?php need_setup('category'); ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Category</th><th>Rules set</th><th>Approved teams</th><th>Groups created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($cats as $c): ?>
                    <tr>
                        <td><strong><?= e($c['name']) ?></strong></td>
                        <td><?= $tick((bool) $c['has_rules']) ?></td>
                        <td><?= $tick($c['n_team'] > 0) ?> <?= $c['n_team'] ?></td>
                        <td><?= $tick($c['n_group'] > 0) ?> <?= $c['n_group'] ?: '' ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/rules.php?ctx_cat=' . $c['category_id']) ?>">Rules</a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/groups.php?ctx_cat=' . $c['category_id']) ?>">Groups</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
