<?php
/**
 * lk_2004 Create groups and assign teams (Module 2.0, sub-process 2.4). Screen: Figure 4.9.
 * The administrator enters the result of the draw, or lets the system draw the groups at random.
 */
require __DIR__ . '/../includes/init.php';
require_login('admin');
handle_context_switch($pdo);
$comp = current_competition($pdo);
$cat = current_category($pdo);

$teams = [];      // team_id => team_name (approved teams of the category)
$existing = [];   // group letter => [team_id, ...]
$locked = false;  // true when group fixtures already exist
if ($cat) {
    $s = $pdo->prepare("SELECT team_id, team_name FROM teams WHERE category_id = ? AND reg_status = 'approved' ORDER BY team_name");
    $s->execute([$cat['category_id']]);
    $teams = array_column($s->fetchAll(), 'team_name', 'team_id');

    $s = $pdo->prepare('SELECT g.group_name, gt.team_id FROM `groups` g LEFT JOIN group_teams gt ON gt.group_id = g.group_id
                         WHERE g.category_id = ? ORDER BY g.group_name, gt.group_team_id');
    $s->execute([$cat['category_id']]);
    foreach ($s->fetchAll() as $r) {
        $existing[$r['group_name']] ??= [];
        if ($r['team_id']) {
            $existing[$r['group_name']][] = (int) $r['team_id'];
        }
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE category_id = ? AND group_id IS NOT NULL');
    $s->execute([$cat['category_id']]);
    $locked = $s->fetchColumn() > 0;
}

// Layout of the form: number of groups and teams per group
$nTeams = count($teams);
$nGroups = (int) ($_POST['n_groups'] ?? $_GET['n_groups'] ?? (count($existing) ?: min(4, max(1, (int) ceil($nTeams / 4)))));
$perGroup = (int) ($_POST['per_group'] ?? $_GET['per_group'] ?? ($existing ? max(array_map('count', $existing)) : ($nGroups ? (int) ceil($nTeams / $nGroups) : 4)));
$nGroups = max(1, min(8, $nGroups));
$perGroup = max(2, min(8, $perGroup));
$letters = array_slice(range('A', 'H'), 0, $nGroups);

// Current placement shown in the form: saved groups, or what was just submitted / drawn
$placement = [];
foreach ($letters as $L) {
    $placement[$L] = array_pad(array_slice($existing[$L] ?? [], 0, $perGroup), $perGroup, 0);
}
$method = $_POST['method'] ?? 'manual';
$errors = [];

if ($cat && is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($locked) {
        flash('danger', 'Group fixtures have already been generated for ' . $cat['name'] . ', so the groups can no longer be changed.');
        redirect('admin/groups.php');
    }
    if ($action === 'draw') {
        // Random draw: shuffle the approved teams and deal them into the groups in turn.
        $ids = array_keys($teams);
        shuffle($ids);
        foreach ($letters as $L) {
            $placement[$L] = array_fill(0, $perGroup, 0);
        }
        foreach ($ids as $i => $tid) {
            $g = $letters[$i % $nGroups];
            $slot = intdiv($i, $nGroups);
            if ($slot < $perGroup) {
                $placement[$g][$slot] = $tid;
            }
        }
        $method = 'random';
        if ($nTeams > $nGroups * $perGroup) {
            $errors[] = 'There are more teams (' . $nTeams . ') than places (' . ($nGroups * $perGroup) . '). Add groups or teams per group.';
        } else {
            flash('info', 'Random draw done. Check the groups, then press Save Groups to keep them.');
        }
    } elseif ($action === 'save' || $action === 'resize') {
        $sent = $_POST['slot'] ?? [];
        foreach ($letters as $L) {
            for ($i = 0; $i < $perGroup; $i++) {
                $tid = (int) ($sent[$L][$i] ?? 0);
                $placement[$L][$i] = isset($teams[$tid]) ? $tid : 0;
            }
        }
        if ($action === 'save') {
            $placed = array_filter(array_merge(...array_values($placement)));
            $counts = array_count_values($placed);
            $dups = array_keys(array_filter($counts, fn ($n) => $n > 1));
            $missing = array_diff(array_keys($teams), $placed);
            foreach ($dups as $tid) {
                $errors[] = e($teams[$tid]) . ' is placed in more than one place.';
            }
            if ($missing) {
                $errors[] = 'Not placed yet: ' . implode(', ', array_map(fn ($t) => e($teams[$t]), $missing)) . '.';
            }
            foreach ($letters as $L) {
                if (count(array_filter($placement[$L])) < 2) {
                    $errors[] = "Group $L needs at least 2 teams.";
                }
            }
            if (!$errors) {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM `groups` WHERE category_id = ?')->execute([$cat['category_id']]); // placements are removed with them
                $insG = $pdo->prepare('INSERT INTO `groups` (category_id, group_name) VALUES (?, ?)');
                $insT = $pdo->prepare('INSERT INTO group_teams (group_id, team_id) VALUES (?, ?)');
                foreach ($letters as $L) {
                    $insG->execute([$cat['category_id'], $L]);
                    $gid = (int) $pdo->lastInsertId();
                    foreach (array_filter($placement[$L]) as $tid) {
                        $insT->execute([$gid, $tid]);
                    }
                }
                $pdo->commit();
                flash('success', $nGroups . ' groups saved for ' . $cat['name'] . '.');
                redirect('admin/groups.php');
            }
        }
    }
}

$pageTitle = 'Groups';
$active = 'Groups';
$crumb = 'Competition Setup / Groups';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Groups</h1>
    <p>Create the groups and place the approved teams into them, from the draw or at random.</p>
</div>
<?php if (!$comp): need_setup('competition'); elseif (!$cat): need_setup('category'); else: ?>
<?php if ($comp['format'] === 'knockout'): ?>
    <div class="alert alert-info">This competition uses the Knockout format, so it has no group stage. Groups are used only by the League and Group stage + Knockout formats.</div>
<?php endif; ?>
<?php if ($locked): ?>
    <div class="alert alert-warning">Group fixtures have already been generated for <?= e($cat['name']) ?>. The groups are shown below but can no longer be changed.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><strong>The groups were not saved.</strong><ul class="mb-0"><?php foreach ($errors as $m): ?><li><?= $m ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$teams): ?>
    <div class="alert alert-info">There are no approved teams in <?= e($cat['name']) ?> yet. Teams appear here after their registration is approved (Module 4).</div>
<?php else: ?>
<form method="post" id="groups-form">
    <?= csrf_field() ?>
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-4">
            <div>
                <label class="form-label req" for="n_groups">Number of groups</label>
                <select class="form-select" id="n_groups" name="n_groups" onchange="resize()">
                    <?php for ($i = 1; $i <= 8; $i++): ?><option <?= $i === $nGroups ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
                </select>
            </div>
            <div>
                <label class="form-label req" for="per_group">Teams per group</label>
                <select class="form-select" id="per_group" name="per_group" onchange="resize()">
                    <?php for ($i = 2; $i <= 8; $i++): ?><option <?= $i === $perGroup ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
                </select>
            </div>
            <div>
                <label class="form-label req d-block">Assignment method</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="method" id="m1" value="manual" <?= $method === 'manual' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="m1">Enter draw result manually</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="method" id="m2" value="random" <?= $method === 'random' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="m2">Random draw by system</label>
                </div>
            </div>
            <div class="ms-auto">
                <button class="btn btn-outline-secondary" name="action" value="draw" <?= $locked ? 'disabled' : '' ?> onclick="return confirmDraw()"><i class="bi bi-shuffle"></i> Run Random Draw</button>
            </div>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-3">
            <div class="card">
                <div class="card-header">Approved teams (<?= $nTeams ?>)</div>
                <ul class="list-group list-group-flush" id="team-list">
                    <?php foreach ($teams as $tid => $name): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center" data-team="<?= $tid ?>">
                            <?= e($name) ?><span class="badge rounded-pill text-bg-light border">–</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="card-footer bg-white small text-muted" id="unplaced-count"></div>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="row g-3">
                <?php foreach ($letters as $L): ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card group-card h-100">
                            <div class="card-header">Group <?= $L ?></div>
                            <div class="card-body d-grid gap-2">
                                <?php foreach ($placement[$L] as $i => $tid): ?>
                                    <select class="form-select team-slot" name="slot[<?= $L ?>][<?= $i ?>]" data-group="<?= $L ?>" <?= $locked ? 'disabled' : '' ?>>
                                        <option value="0">– Choose team –</option>
                                        <?php foreach ($teams as $id => $name): ?>
                                            <option value="<?= $id ?>" <?= $id === $tid ? 'selected' : '' ?>><?= e($name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-4">
        <span class="text-muted small">Each team can be placed in one group only. Places: <?= $nGroups * $perGroup ?> · Approved teams: <?= $nTeams ?></span>
        <span>
            <a class="btn btn-outline-secondary" href="<?= url('admin/groups.php') ?>">Cancel</a>
            <button class="btn btn-primary" name="action" value="save" <?= $locked ? 'disabled' : '' ?>>Save Groups</button>
        </span>
    </div>
    <input type="hidden" name="action" value="" id="resize-action" disabled>
</form>
<script>
    // Show which group each team is in, and mark a team chosen twice in red.
    function refresh() {
        const where = {}, count = {};
        document.querySelectorAll('.team-slot').forEach(s => {
            s.classList.remove('dup');
            if (s.value !== '0') { count[s.value] = (count[s.value] || 0) + 1; where[s.value] = (where[s.value] || []).concat(s.dataset.group); }
        });
        document.querySelectorAll('.team-slot').forEach(s => { if (count[s.value] > 1) s.classList.add('dup'); });
        let unplaced = 0;
        document.querySelectorAll('#team-list li').forEach(li => {
            const g = where[li.dataset.team], badge = li.querySelector('.badge');
            badge.textContent = g ? g.join(', ') : '–';
            badge.className = 'badge rounded-pill ' + (!g ? 'text-bg-light border' : g.length > 1 ? 'text-bg-danger' : 'text-bg-success');
            if (!g) unplaced++;
        });
        document.getElementById('unplaced-count').textContent = unplaced ? unplaced + ' team(s) not placed yet' : 'All teams are placed';
    }
    document.querySelectorAll('.team-slot').forEach(s => s.addEventListener('change', refresh));
    refresh();

    // Changing the number of groups or teams per group redraws the form, keeping the current choices.
    function resize() {
        const a = document.getElementById('resize-action');
        a.disabled = false; a.value = 'resize';
        document.getElementById('groups-form').submit();
    }
    function confirmDraw() {
        const any = [...document.querySelectorAll('.team-slot')].some(s => s.value !== '0');
        return !any || confirm('Replace the current placement with a random draw?');
    }
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
