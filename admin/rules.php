<?php
/**
 * lk_2003 Set competition rules (Module 2.0, sub-process 2.3). Screen: Figure 4.5.
 * Rules are stored per category because the tie-break order and qualification rules differ between competitions.
 */
require __DIR__ . '/../includes/init.php';
require_login('admin');
handle_context_switch($pdo);
$comp = current_competition($pdo);
$cat = current_category($pdo);

$defaults = ['pts_win' => 3, 'pts_draw' => 1, 'pts_loss' => 0, 'tiebreak_order' => 'GD,GF,H2H,FP', 'qualify_per_group' => 2, 'best_thirds' => 0,
    'ko_draw_rule' => 'et_pens', 'yellow_limit' => 2, 'red_ban' => 1, 'clear_yellows' => 0, 'max_per_day' => 1];
$form = $defaults;
$errors = [];
$saved = false;

if ($cat) {
    $stmt = $pdo->prepare('SELECT * FROM competition_rules WHERE category_id = ?');
    $stmt->execute([$cat['category_id']]);
    if ($row = $stmt->fetch()) {
        $form = array_merge($form, $row);
        $saved = true;
    }
}

if ($cat && is_post()) {
    check_csrf();
    $int = function (string $k, int $min, int $max) use (&$errors, &$form, $defaults): int {
        $v = filter_var($_POST[$k] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($v === false) {
            $errors[$k] = "Enter a whole number from $min to $max.";
            $form[$k] = $_POST[$k] ?? '';
            return $defaults[$k];
        }
        return $form[$k] = $v;
    };
    $win = $int('pts_win', 0, 9);
    $draw = $int('pts_draw', 0, 9);
    $loss = $int('pts_loss', 0, 9);
    if (empty($errors['pts_win']) && empty($errors['pts_draw']) && $win <= $draw) {
        $errors['pts_win'] = 'Points for a win must be more than for a draw.';
    }
    if (empty($errors['pts_draw']) && empty($errors['pts_loss']) && $draw < $loss) {
        $errors['pts_draw'] = 'Points for a draw cannot be less than for a loss.';
    }

    $order = array_values(array_filter(explode(',', input('tiebreak_order')), fn ($c) => isset(TIEBREAKS[$c])));
    if (!$order || count($order) !== count(array_unique($order))) {
        $errors['tiebreak_order'] = 'Choose at least one tie-break criterion, each only once.';
    }
    $form['tiebreak_order'] = implode(',', $order);

    $int('qualify_per_group', 1, 4);
    $int('best_thirds', 0, 8);
    $form['ko_draw_rule'] = in_array($_POST['ko_draw_rule'] ?? '', ['et_pens', 'pens'], true) ? $_POST['ko_draw_rule'] : 'et_pens';
    $int('yellow_limit', 1, 5);
    $int('red_ban', 1, 5);
    $int('max_per_day', 1, 3);
    $form['clear_yellows'] = isset($_POST['clear_yellows']) ? 1 : 0;

    if (!$errors) {
        $cols = ['pts_win', 'pts_draw', 'pts_loss', 'tiebreak_order', 'qualify_per_group', 'best_thirds', 'ko_draw_rule', 'yellow_limit', 'red_ban', 'clear_yellows', 'max_per_day'];
        $values = array_map(fn ($c) => $form[$c], $cols);
        if ($saved) {
            $set = implode(', ', array_map(fn ($c) => "$c = ?", $cols));
            $pdo->prepare("UPDATE competition_rules SET $set WHERE category_id = ?")->execute([...$values, $cat['category_id']]);
        } else {
            $pdo->prepare('INSERT INTO competition_rules (' . implode(', ', $cols) . ', category_id) VALUES (' . str_repeat('?, ', count($cols)) . '?)')
                ->execute([...$values, $cat['category_id']]);
        }
        // When results exist, the standings are recalculated from these rules (program lk_8001, Module 8).
        flash('success', 'Rules for ' . $cat['name'] . ' saved.');
        redirect('admin/rules.php');
    }
}

$order = $form['tiebreak_order'] !== '' ? explode(',', $form['tiebreak_order']) : [];
$unused = array_diff(array_keys(TIEBREAKS), $order);
$nGroups = 0;
if ($cat) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM `groups` WHERE category_id = ?');
    $s->execute([$cat['category_id']]);
    $nGroups = (int) $s->fetchColumn();
}

$pageTitle = 'Competition Rules';
$active = 'Competition Rules';
$crumb = 'Competition Setup / Competition Rules';
require __DIR__ . '/../includes/header.php';

function num(string $name, string $label, array $form, array $errors, int $min, int $max): void
{ ?>
    <label class="form-label req" for="<?= $name ?>"><?= e($label) ?></label>
    <input class="form-control <?= inv($errors, $name) ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" id="<?= $name ?>" name="<?= $name ?>" value="<?= e((string) $form[$name]) ?>">
    <?= err($errors, $name) ?>
<?php }
?>
<div class="page-head">
    <h1>Competition Rules</h1>
    <p><?= $cat ? 'Rules for ' . e($comp['name']) . ', category ' . e($cat['name']) . '. Standings, qualifiers and suspensions use these.' : 'Rules for each category.' ?></p>
</div>
<?php if (!$comp): need_setup('competition'); elseif (!$cat): need_setup('category'); else: ?>
<?php if (!$saved): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>No rules saved for <?= e($cat['name']) ?> yet. The default values are shown. Press Save Rules to keep them.</div><?php endif; ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Points</div>
                <div class="card-body row g-3">
                    <div class="col-4"><?php num('pts_win', 'Points for a win', $form, $errors, 0, 9); ?></div>
                    <div class="col-4"><?php num('pts_draw', 'Points for a draw', $form, $errors, 0, 9); ?></div>
                    <div class="col-4"><?php num('pts_loss', 'Points for a loss', $form, $errors, 0, 9); ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">Tie-break order (teams level on points)</div>
                <div class="card-body">
                    <input type="hidden" name="tiebreak_order" id="tiebreak_order" value="<?= e($form['tiebreak_order']) ?>">
                    <div id="tiebreak-list" class="d-grid gap-2 mb-3"></div>
                    <div class="input-group" id="tiebreak-add">
                        <select class="form-select" id="tiebreak-new"></select>
                        <button class="btn btn-outline-secondary" type="button" onclick="tbAdd()">+ Add criterion</button>
                    </div>
                    <?= err($errors, 'tiebreak_order') ?>
                    <div class="form-text">Use the arrows to change the order. The first criterion is used first.</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">Qualification</div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-6"><?php num('qualify_per_group', 'Teams qualifying per group', $form, $errors, 1, 4); ?></div>
                        <div class="col-6"><?php num('best_thirds', 'Best third-placed teams', $form, $errors, 0, 8); ?></div>
                    </div>
                    <label class="form-label req">Drawn knockout match decided by</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="ko_draw_rule" id="ko1" value="et_pens" <?= $form['ko_draw_rule'] === 'et_pens' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ko1">Extra time, then penalties</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="ko_draw_rule" id="ko2" value="pens" <?= $form['ko_draw_rule'] === 'pens' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ko2">Penalties directly</label>
                        </div>
                    </div>
                    <?php if ($nGroups): ?>
                        <div class="form-text mt-2" id="qual-summary"></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header">Discipline and scheduling</div>
                <div class="card-body row g-3">
                    <div class="col-6"><?php num('yellow_limit', 'Yellow cards before a ban', $form, $errors, 1, 5); ?></div>
                    <div class="col-6"><?php num('red_ban', 'Matches banned for a red card', $form, $errors, 1, 5); ?></div>
                    <div class="col-6"><?php num('max_per_day', 'Max matches per team per day', $form, $errors, 1, 3); ?></div>
                    <div class="col-6 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="clear_yellows" name="clear_yellows" value="1" <?= $form['clear_yellows'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="clear_yellows">Clear yellow cards after the group stage</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-4">
        <span class="text-muted small">Changing the rules after matches are played recalculates the standings.</span>
        <span>
            <a class="btn btn-outline-secondary" href="<?= url('admin/rules.php') ?>">Cancel</a>
            <button class="btn btn-primary" type="submit">Save Rules</button>
        </span>
    </div>
</form>
<script>
    const TB_LABELS = <?= json_encode(TIEBREAKS) ?>;
    const N_GROUPS = <?= $nGroups ?>;
    let tbOrder = document.getElementById('tiebreak_order').value.split(',').filter(c => TB_LABELS[c]);

    function tbRender() {
        const list = document.getElementById('tiebreak-list');
        list.innerHTML = '';
        tbOrder.forEach((code, i) => {
            const row = document.createElement('div');
            row.className = 'tiebreak-item d-flex align-items-center gap-2';
            row.innerHTML = `<i class="bi bi-grip-vertical text-muted"></i><span class="flex-grow-1">${i + 1}. ${TB_LABELS[code]}</span>
                <button type="button" class="btn btn-sm btn-light" ${i === 0 ? 'disabled' : ''} onclick="tbMove(${i}, -1)" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>
                <button type="button" class="btn btn-sm btn-light" ${i === tbOrder.length - 1 ? 'disabled' : ''} onclick="tbMove(${i}, 1)" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>
                <button type="button" class="btn btn-sm btn-light text-danger" onclick="tbRemove(${i})" aria-label="Remove"><i class="bi bi-x-lg"></i></button>`;
            list.appendChild(row);
        });
        const unused = Object.keys(TB_LABELS).filter(c => !tbOrder.includes(c));
        document.getElementById('tiebreak-new').innerHTML = unused.map(c => `<option value="${c}">${TB_LABELS[c]}</option>`).join('');
        document.getElementById('tiebreak-add').style.display = unused.length ? '' : 'none';
        document.getElementById('tiebreak_order').value = tbOrder.join(',');
    }
    function tbMove(i, d) { [tbOrder[i], tbOrder[i + d]] = [tbOrder[i + d], tbOrder[i]]; tbRender(); }
    function tbRemove(i) { tbOrder.splice(i, 1); tbRender(); }
    function tbAdd() { const v = document.getElementById('tiebreak-new').value; if (v) { tbOrder.push(v); tbRender(); } }
    tbRender();

    function qualSummary() {
        const el = document.getElementById('qual-summary');
        if (!el) return;
        const q = +document.getElementById('qualify_per_group').value || 0;
        const b = +document.getElementById('best_thirds').value || 0;
        el.textContent = `Knockout stage: ${N_GROUPS} groups × ${q} + ${b} best thirds = ${N_GROUPS * q + b} teams.`;
    }
    ['qualify_per_group', 'best_thirds'].forEach(id => document.getElementById(id).addEventListener('input', qualSummary));
    qualSummary();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
