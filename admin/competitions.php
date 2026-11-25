<?php
/**
 * Competitions list (Module 2.0). Lists all competitions with links to create, edit or delete one.
 */
require __DIR__ . '/../includes/init.php';
require_login('admin');
handle_context_switch($pdo);

if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE competition_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'This competition has categories. Delete its categories first.');
    } else {
        $pdo->prepare('DELETE FROM match_slots WHERE competition_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM competitions WHERE competition_id = ?')->execute([$id]);
        flash('success', 'Competition deleted.');
    }
    redirect('admin/competitions.php');
}

$rows = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM categories k WHERE k.competition_id = c.competition_id) AS n_cat,
                            (SELECT COUNT(*) FROM teams t JOIN categories k ON k.category_id = t.category_id
                              WHERE k.competition_id = c.competition_id AND t.reg_status = \'approved\') AS n_team
                       FROM competitions c ORDER BY c.start_date DESC, c.competition_id DESC')->fetchAll();

$pageTitle = 'Competitions';
$active = 'Competitions';
$crumb = 'Competition Setup / Competitions';
require __DIR__ . '/../includes/header.php';
$badge = ['upcoming' => 'text-bg-info', 'ongoing' => 'text-bg-success', 'completed' => 'text-bg-secondary'];
?>
<div class="page-head d-flex justify-content-between align-items-start">
    <div>
        <h1>Competitions</h1>
        <p>All competitions in the system. Choose one in the top bar to set up its categories, rules and groups.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('admin/competition_form.php') ?>"><i class="bi bi-plus-lg"></i> Create Competition</a>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Competition</th><th>Season</th><th>Format</th><th>Dates</th><th>Categories</th><th>Teams</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No competitions yet. Press Create Competition to add the first one.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong><?php if ($r['organiser']): ?><br><small class="text-muted"><?= e($r['organiser']) ?></small><?php endif; ?></td>
                    <td><?= e((string) $r['season']) ?></td>
                    <td><?= e(FORMATS[$r['format']]) ?></td>
                    <td class="text-nowrap"><?= format_date($r['start_date']) ?> – <?= format_date($r['end_date']) ?></td>
                    <td><?= $r['n_cat'] ?></td>
                    <td><?= $r['n_team'] ?></td>
                    <td><span class="badge rounded-pill <?= $badge[$r['status']] ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="?ctx_comp=<?= $r['competition_id'] ?>" title="Make this the selected competition">Select</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/competition_form.php?id=' . $r['competition_id']) ?>">Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this competition?')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['competition_id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" <?= $r['n_cat'] ? 'disabled title="Delete its categories first"' : '' ?>>Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
