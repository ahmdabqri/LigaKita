<?php
/**
 * Public home page. The full public portal (fixtures, standings, bracket, top scorers) is program lk_8002 (Module 8);
 * this version lists the tournaments in the system.
 */
require __DIR__ . '/includes/init.php';
$comps = $pdo->query("SELECT c.*, GROUP_CONCAT(k.name ORDER BY k.name SEPARATOR ', ') AS cats FROM competitions c
                      LEFT JOIN categories k ON k.competition_id = c.competition_id
                      GROUP BY c.competition_id ORDER BY FIELD(c.status, 'ongoing', 'upcoming', 'completed'), c.start_date DESC")->fetchAll();
$badge = ['upcoming' => 'text-bg-info', 'ongoing' => 'text-bg-success', 'completed' => 'text-bg-secondary'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LigaKita</title>
    <link href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="app">
<nav class="navbar bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= url('index.php') ?>"><span class="brand-logo"><i class="bi bi-trophy-fill"></i></span>LigaKita</a>
        <a class="btn btn-outline-secondary" href="<?= url('login.php') ?>">Committee Login</a>
    </div>
</nav>
<div class="bg-light border-bottom py-4 mb-4">
    <div class="container">
        <h1 class="h3 fw-bold mb-1">Welcome to LigaKita</h1>
        <p class="text-muted mb-0">Matches and results from every tournament in the system.</p>
    </div>
</div>
<div class="container pb-5">
    <h2 class="h5 fw-bold mb-3">Tournaments</h2>
    <div class="row g-3">
        <?php if (!$comps): ?><div class="col-12 text-muted">No tournaments yet.</div><?php endif; ?>
        <?php foreach ($comps as $c): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <span class="badge rounded-pill <?= $badge[$c['status']] ?> mb-2"><?= ucfirst($c['status']) ?></span>
                    <h3 class="h6 fw-bold mb-1"><?= e($c['name']) ?></h3>
                    <div class="small text-muted">Categories: <?= e($c['cats'] ?: '–') ?></div>
                    <div class="small text-muted"><?= format_date($c['start_date']) ?> to <?= format_date($c['end_date']) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-4">Fixtures, results, standings and the knockout bracket will appear here once the matches module is completed.</p>
</div>
</body>
</html>
