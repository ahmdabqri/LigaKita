<?php
/**
 * Page layout for logged-in users: left menu, top bar and the start of the content area.
 * Set $pageTitle, $active (menu item) and optionally $crumb before including this file.
 */
$user = current_user();
$isAdmin = $user['role'] === 'admin';

// Menu items: [label, link or null if the module is not built yet, icon]
$menu = $isAdmin ? [
    'Main' => [['Dashboard', 'admin/index.php', 'speedometer2']],
    'Competition setup' => [
        ['Competitions', 'admin/competitions.php', 'trophy'],
        ['Categories', 'admin/categories.php', 'diagram-3'],
        ['Competition Rules', 'admin/rules.php', 'list-ol'],
        ['Groups', 'admin/groups.php', 'grid-3x2-gap'],
        ['Venues & Match Slots', 'admin/venues.php', 'geo-alt'],
    ],
    'Teams' => [
        ['Registrations', null, 'clipboard-check'],
        ['Team Manager Accounts', 'admin/accounts.php', 'people'],
    ],
    'Matches' => [
        ['Fixtures & Brackets', null, 'calendar3'],
        ['Match Results', null, 'pencil-square'],
        ['Knockout Progression', null, 'diagram-2'],
        ['Standings', null, 'table'],
    ],
    'Reports' => [['Reports', null, 'file-earmark-bar-graph']],
] : [
    'My team' => [
        ['Dashboard', 'manager/index.php', 'speedometer2'],
        ['Team Registration', null, 'shield'],
        ['Players', null, 'person-badge'],
        ['Squad Status', null, 'person-check'],
        ['Fixtures & Results', null, 'calendar3'],
    ],
    'Competition' => [
        ['Standings', null, 'table'],
        ['Knockout Bracket', null, 'diagram-2'],
    ],
];

if ($isAdmin) {
    $ctxComp = current_competition($pdo);
    $ctxCat = current_category($pdo);
    $allComps = $pdo->query('SELECT competition_id, name FROM competitions ORDER BY start_date DESC, competition_id DESC')->fetchAll();
    $ctxCats = [];
    if ($ctxComp) {
        $s = $pdo->prepare('SELECT category_id, name FROM categories WHERE competition_id = ? ORDER BY name');
        $s->execute([$ctxComp['competition_id']]);
        $ctxCats = $s->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · LigaKita</title>
    <link href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="app">
<div class="d-flex">
    <nav id="sidebar" class="sidebar d-flex flex-column">
        <a class="brand d-flex align-items-center gap-2" href="<?= url(home_for($user)) ?>">
            <span class="brand-logo"><i class="bi bi-trophy-fill"></i></span>
            <span><strong>LigaKita</strong><small><?= $isAdmin ? 'ADMINISTRATOR' : 'TEAM MANAGER' ?></small></span>
        </a>
        <div class="menu flex-grow-1">
            <?php foreach ($menu as $section => $items): ?>
                <div class="menu-section"><?= e($section) ?></div>
                <?php foreach ($items as [$label, $link, $icon]): ?>
                    <?php if ($link === null): ?>
                        <span class="menu-item disabled" title="Coming in a later module"><i class="bi bi-<?= $icon ?>"></i><?= e($label) ?><span class="soon">soon</span></span>
                    <?php else: ?>
                        <a class="menu-item <?= ($active ?? '') === $label ? 'active' : '' ?>" href="<?= url($link) ?>"><i class="bi bi-<?= $icon ?>"></i><?= e($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <a class="menu-item logout" href="<?= url('logout.php?csrf=' . csrf_token()) ?>"><i class="bi bi-box-arrow-left"></i>Log out</a>
    </nav>

    <div class="main flex-grow-1">
        <header class="topbar d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
            <div class="crumb flex-grow-1"><?= e($crumb ?? $pageTitle) ?></div>
            <?php if ($isAdmin && $allComps): ?>
                <form method="get" class="d-none d-md-flex gap-2 ctx">
                    <select name="ctx_comp" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Competition">
                        <?php foreach ($allComps as $c): ?>
                            <option value="<?= $c['competition_id'] ?>" <?= $c['competition_id'] == ($ctxComp['competition_id'] ?? 0) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="get" class="d-none d-md-flex ctx">
                    <select name="ctx_cat" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Category" <?= $ctxCats ? '' : 'disabled' ?>>
                        <?php if (!$ctxCats): ?><option>No category</option><?php endif; ?>
                        <?php foreach ($ctxCats as $c): ?>
                            <option value="<?= $c['category_id'] ?>" <?= $c['category_id'] == ($ctxCat['category_id'] ?? 0) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <div class="dropdown">
                <button class="user d-flex align-items-center gap-2 btn btn-link text-decoration-none text-reset p-0 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span>
                    <span class="d-none d-sm-block lh-sm text-start"><strong><?= e($user['full_name']) ?></strong><br><small class="text-muted"><?= $isAdmin ? 'Administrator' : 'Team Manager' ?></small></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= url('change_password.php') ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= url('logout.php?csrf=' . csrf_token()) ?>"><i class="bi bi-box-arrow-left me-2"></i>Log out</a></li>
                </ul>
            </div>
        </header>
        <main class="content">
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= $f['message'] /* escaped by flash() */ ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
