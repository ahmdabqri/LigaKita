<?php
/**
 * lk_3001 Manage venues and match slots (Module 3.0, sub-processes 3.1 and 3.2). Screen: Figure 4.6.
 * Venues are shared by all competitions. Match slots belong to the competition chosen in the top bar,
 * and the fixture generator (Module 6.0) only places matches into these slots.
 */
require __DIR__ . '/../includes/init.php';
require_login('admin');
handle_context_switch($pdo);
$comp = current_competition($pdo);

/** Minutes after midnight for a HH:MM or HH:MM:SS time. */
function to_minutes(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

/** 8:00 am style time for display. */
function format_time(string $time): string
{
    return date('g:i a', strtotime($time));
}

$editVenue = (int) ($_GET['edit_venue'] ?? $_POST['venue_id'] ?? 0);
$venueForm = ['name' => '', 'town' => ''];
$slotForm = ['slot_venue' => '', 'slot_date' => $comp['start_date'] ?? '', 'first_kickoff' => '08:00', 'length_min' => '90', 'n_slots' => '2'];
$venueErrors = $slotErrors = [];
// Keep the last slot settings so the next venue or match day is quicker to add.
if (isset($_SESSION['slot_form']) && ($_SESSION['slot_form']['competition_id'] ?? 0) == ($comp['competition_id'] ?? -1)) {
    $slotForm = array_merge($slotForm, array_intersect_key($_SESSION['slot_form'], $slotForm));
}

if ($editVenue) {
    $stmt = $pdo->prepare('SELECT name, town FROM venues WHERE venue_id = ?');
    $stmt->execute([$editVenue]);
    if ($row = $stmt->fetch()) {
        $venueForm = ['name' => $row['name'], 'town' => (string) $row['town']];
    } else {
        $editVenue = 0;
    }
}

if (is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // ---- 3.1 Add or edit a venue
    if ($action === 'save_venue') {
        $venueForm = ['name' => input('name'), 'town' => input('town')];
        if ($venueForm['name'] === '' || mb_strlen($venueForm['name']) > 100) {
            $venueErrors['name'] = 'Venue name is required (up to 100 characters).';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM venues WHERE name = ? AND venue_id <> ?');
            $stmt->execute([$venueForm['name'], $editVenue]);
            if ($stmt->fetchColumn() > 0) {
                $venueErrors['name'] = 'A venue with this name already exists.';
            }
        }
        if (mb_strlen($venueForm['town']) > 50) {
            $venueErrors['town'] = 'Up to 50 characters.';
        }
        if (!$venueErrors) {
            $town = $venueForm['town'] === '' ? null : $venueForm['town'];
            if ($editVenue) {
                $pdo->prepare('UPDATE venues SET name = ?, town = ? WHERE venue_id = ?')->execute([$venueForm['name'], $town, $editVenue]);
                flash('success', 'Venue ' . $venueForm['name'] . ' updated.');
            } else {
                $pdo->prepare('INSERT INTO venues (name, town) VALUES (?, ?)')->execute([$venueForm['name'], $town]);
                flash('success', 'Venue ' . $venueForm['name'] . ' added. You can now create its match slots.');
            }
            redirect('admin/venues.php');
        }
    }

    if ($action === 'delete_venue') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM match_slots WHERE venue_id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            flash('danger', 'This venue has match slots. Delete its slots first.');
        } else {
            $pdo->prepare('DELETE FROM venues WHERE venue_id = ?')->execute([$id]);
            flash('success', 'Venue deleted.');
        }
        redirect('admin/venues.php');
    }

    // ---- 3.2 Generate match slots for one venue and match day
    if ($action === 'generate' && $comp) {
        foreach (array_keys($slotForm) as $k) {
            $slotForm[$k] = input($k);
        }
        $venueId = (int) $slotForm['slot_venue'];
        $stmt = $pdo->prepare('SELECT name FROM venues WHERE venue_id = ?');
        $stmt->execute([$venueId]);
        $venueName = $stmt->fetchColumn();
        if (!$venueName) {
            $slotErrors['slot_venue'] = 'Choose a venue.';
        }
        $date = valid_date($slotForm['slot_date']);
        if (!$date) {
            $slotErrors['slot_date'] = 'Enter the match date.';
        } elseif ($date < $comp['start_date'] || $date > $comp['end_date']) {
            $slotErrors['slot_date'] = 'The date must be within the competition (' . format_date($comp['start_date']) . ' to ' . format_date($comp['end_date']) . ').';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slotForm['first_kickoff'])) {
            $slotErrors['first_kickoff'] = 'Choose the first kick-off time.';
        }
        $length = filter_var($slotForm['length_min'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 180]]);
        if ($length === false) {
            $slotErrors['length_min'] = 'Enter a number from 30 to 180.';
        }
        $count = filter_var($slotForm['n_slots'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($count === false) {
            $slotErrors['n_slots'] = 'Enter a number from 1 to 10.';
        }
        if (!$slotErrors && to_minutes($slotForm['first_kickoff']) + $count * $length > 24 * 60) {
            $slotErrors['n_slots'] = 'The last slot would end after midnight. Use fewer slots or an earlier kick-off.';
        }

        if (!$slotErrors) {
            // A new slot is skipped if it overlaps any slot already at this venue on this date
            // (in any competition), so the same pitch is never booked twice.
            $overlap = $pdo->prepare('SELECT COUNT(*) FROM match_slots WHERE venue_id = ? AND slot_date = ?
                                         AND kickoff < ? AND ADDTIME(kickoff, SEC_TO_TIME(length_min * 60)) > ?');
            $insert = $pdo->prepare('INSERT INTO match_slots (competition_id, venue_id, slot_date, kickoff, length_min) VALUES (?, ?, ?, ?, ?)');
            $made = 0;
            $skipped = [];
            $time = to_minutes($slotForm['first_kickoff']);
            $pdo->beginTransaction();
            for ($i = 0; $i < $count; $i++, $time += $length) {
                $start = sprintf('%02d:%02d:00', intdiv($time, 60), $time % 60);
                $end = $time + $length >= 24 * 60 ? '24:00:00' : sprintf('%02d:%02d:00', intdiv($time + $length, 60), ($time + $length) % 60);
                $overlap->execute([$venueId, $date, $end, $start]);
                if ($overlap->fetchColumn() > 0) {
                    $skipped[] = format_time($start);
                    continue;
                }
                $insert->execute([$comp['competition_id'], $venueId, $date, $start, $length]);
                $made++;
            }
            $pdo->commit();
            $_SESSION['slot_form'] = $slotForm + ['competition_id' => $comp['competition_id']];

            $msg = $made . ' match slot' . ($made === 1 ? '' : 's') . ' created at ' . $venueName . ' on ' . format_date($date) . '.';
            if ($skipped) {
                $msg .= ' Skipped ' . implode(', ', $skipped) . ' because the venue is already used at that time.';
            }
            flash($made ? 'success' : 'warning', $msg);
            redirect('admin/venues.php?date=' . $date);
        }
    }

    if ($action === 'delete_slot' && $comp) {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE slot_id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            flash('danger', 'This slot is booked by a match and cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM match_slots WHERE slot_id = ? AND competition_id = ?')->execute([$id, $comp['competition_id']]);
            flash('success', 'Match slot deleted.');
        }
        redirect('admin/venues.php?date=' . urlencode((string) ($_POST['date'] ?? '')));
    }

    if ($action === 'delete_free_day' && $comp) {
        $date = valid_date((string) ($_POST['date'] ?? ''));
        if ($date) {
            $stmt = $pdo->prepare('DELETE FROM match_slots WHERE competition_id = ? AND slot_date = ?
                                     AND slot_id NOT IN (SELECT slot_id FROM matches WHERE slot_id IS NOT NULL)');
            $stmt->execute([$comp['competition_id'], $date]);
            flash('success', $stmt->rowCount() . ' free slot(s) on ' . format_date($date) . ' deleted.');
        }
        redirect('admin/venues.php');
    }
}

// ---- Data for the page
$venues = $pdo->query('SELECT v.*, (SELECT COUNT(*) FROM match_slots s WHERE s.venue_id = v.venue_id) AS n_slot
                         FROM venues v ORDER BY v.name')->fetchAll();

$days = $slots = [];
$summary = ['total' => 0, 'free' => 0, 'days' => 0];
$selDate = null;
if ($comp) {
    $stmt = $pdo->prepare('SELECT s.slot_date, COUNT(*) AS n, SUM(m.match_id IS NULL) AS n_free
                             FROM match_slots s LEFT JOIN matches m ON m.slot_id = s.slot_id
                            WHERE s.competition_id = ? GROUP BY s.slot_date ORDER BY s.slot_date');
    $stmt->execute([$comp['competition_id']]);
    $days = $stmt->fetchAll();
    foreach ($days as $d) {
        $summary['total'] += $d['n'];
        $summary['free'] += $d['n_free'];
    }
    $summary['days'] = count($days);

    $dates = array_column($days, 'slot_date');
    $selDate = in_array($_GET['date'] ?? '', $dates, true) ? $_GET['date'] : ($dates[0] ?? null);
    if ($selDate) {
        $stmt = $pdo->prepare('SELECT s.*, v.name AS venue_name, m.match_id, m.status AS match_status, k.name AS cat_name,
                                      h.team_name AS home, a.team_name AS away
                                 FROM match_slots s
                                 JOIN venues v ON v.venue_id = s.venue_id
                            LEFT JOIN matches m ON m.slot_id = s.slot_id
                            LEFT JOIN categories k ON k.category_id = m.category_id
                            LEFT JOIN teams h ON h.team_id = m.home_team_id
                            LEFT JOIN teams a ON a.team_id = m.away_team_id
                                WHERE s.competition_id = ? AND s.slot_date = ?
                             ORDER BY s.kickoff, v.name');
        $stmt->execute([$comp['competition_id'], $selDate]);
        $slots = $stmt->fetchAll();
    }
}

$pageTitle = 'Venues and Match Slots';
$active = 'Venues & Match Slots';
$crumb = 'Competition Setup / Venues & Match Slots';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Venues and Match Slots</h1>
    <p>Add the pitches you can use, then create the kick-off slots that fixtures are placed into.</p>
</div>
<div class="row g-4">
    <div class="col-xl-5">
        <form method="post" class="card mb-4" novalidate id="venue-form">
            <div class="card-header"><?= $editVenue ? 'Edit Venue' : 'Add Venue' ?></div>
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_venue">
                <input type="hidden" name="venue_id" value="<?= $editVenue ?>">
                <div class="mb-3">
                    <label class="form-label req" for="name">Venue name</label>
                    <input class="form-control <?= inv($venueErrors, 'name') ?>" id="name" name="name" maxlength="100" value="<?= e($venueForm['name']) ?>" placeholder="e.g. Padang Bandar Pontian">
                    <?= err($venueErrors, 'name') ?>
                </div>
                <div>
                    <label class="form-label" for="town">Address / town</label>
                    <input class="form-control <?= inv($venueErrors, 'town') ?>" id="town" name="town" maxlength="50" value="<?= e($venueForm['town']) ?>" placeholder="e.g. Pontian">
                    <?= err($venueErrors, 'town') ?>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <?php if ($editVenue): ?><a class="btn btn-outline-secondary" href="<?= url('admin/venues.php') ?>">Cancel</a><?php endif; ?>
                <button class="btn btn-primary" type="submit"><?= $editVenue ? 'Save Venue' : 'Add Venue' ?></button>
            </div>
        </form>

        <form method="post" class="card" novalidate id="slot-form">
            <div class="card-header">Add Match Slots</div>
            <?php if (!$comp): ?>
                <div class="card-body"><?php need_setup('competition'); ?></div>
            <?php elseif (!$venues): ?>
                <div class="card-body text-muted">Add a venue first, then create its match slots here.</div>
            <?php else: ?>
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate">
                <div class="mb-3">
                    <label class="form-label req" for="slot_venue">Venue</label>
                    <select class="form-select <?= inv($slotErrors, 'slot_venue') ?>" id="slot_venue" name="slot_venue">
                        <option value="">Choose a venue</option>
                        <?php foreach ($venues as $v): ?>
                            <option value="<?= $v['venue_id'] ?>" <?= (string) $v['venue_id'] === $slotForm['slot_venue'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= err($slotErrors, 'slot_venue') ?>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label req" for="slot_date">Match date</label>
                        <input class="form-control <?= inv($slotErrors, 'slot_date') ?>" type="date" id="slot_date" name="slot_date"
                               min="<?= e($comp['start_date']) ?>" max="<?= e($comp['end_date']) ?>" value="<?= e($slotForm['slot_date']) ?>">
                        <?= err($slotErrors, 'slot_date') ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label req" for="first_kickoff">First kick-off</label>
                        <select class="form-select <?= inv($slotErrors, 'first_kickoff') ?>" id="first_kickoff" name="first_kickoff">
                            <?php for ($t = 7 * 60; $t <= 22 * 60; $t += 30): $hm = sprintf('%02d:%02d', intdiv($t, 60), $t % 60); ?>
                                <option value="<?= $hm ?>" <?= $hm === $slotForm['first_kickoff'] ? 'selected' : '' ?>><?= format_time($hm) ?></option>
                            <?php endfor; ?>
                        </select>
                        <?= err($slotErrors, 'first_kickoff') ?>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label req" for="length_min">Slot length (minutes)</label>
                        <input class="form-control <?= inv($slotErrors, 'length_min') ?>" type="number" min="30" max="180" step="5" id="length_min" name="length_min" value="<?= e($slotForm['length_min']) ?>">
                        <?= err($slotErrors, 'length_min') ?: '<div class="form-text">Match time plus break</div>' ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label req" for="n_slots">Number of slots</label>
                        <input class="form-control <?= inv($slotErrors, 'n_slots') ?>" type="number" min="1" max="10" id="n_slots" name="n_slots" value="<?= e($slotForm['n_slots']) ?>">
                        <?= err($slotErrors, 'n_slots') ?>
                    </div>
                </div>
                <div class="slot-preview small text-muted mt-3" id="slot-preview"></div>
            </div>
            <div class="card-footer bg-white">
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-calendar-plus me-1"></i>Generate Slots</button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header">Venues (<?= count($venues) ?>)</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Venue</th><th>Town</th><th>Slots</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$venues): ?><tr><td colspan="4" class="text-center text-muted py-4">No venues yet.</td></tr><?php endif; ?>
                    <?php foreach ($venues as $v): ?>
                        <tr class="<?= $v['venue_id'] == $editVenue ? 'table-active' : '' ?>">
                            <td><strong><?= e($v['name']) ?></strong></td>
                            <td><?= e($v['town'] ?? '–') ?></td>
                            <td><?= $v['n_slot'] ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit_venue=<?= $v['venue_id'] ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this venue?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_venue"><input type="hidden" name="id" value="<?= $v['venue_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" <?= $v['n_slot'] ? 'disabled title="Delete its slots first"' : '' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($comp): ?>
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span class="me-auto">Match Slots</span>
                <?php if ($days): ?>
                    <form method="get" class="d-flex gap-2">
                        <select name="date" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Match day">
                            <?php foreach ($days as $d): ?>
                                <option value="<?= e($d['slot_date']) ?>" <?= $d['slot_date'] === $selDate ? 'selected' : '' ?>>
                                    <?= date('D d/m/Y', strtotime($d['slot_date'])) ?> (<?= $d['n_free'] ?>/<?= $d['n'] ?> free)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>
            <div class="px-3 py-2 border-bottom small text-muted">
                <?= e($comp['name']) ?>: <strong><?= $summary['total'] ?></strong> slots on <strong><?= $summary['days'] ?></strong> match day(s), <strong><?= $summary['free'] ?></strong> still free.
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Kick-off</th><th>Venue</th><th>Status</th><th>Match</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$slots): ?><tr><td colspan="5" class="text-center text-muted py-4">No match slots yet. Use Add Match Slots to create the kick-off times of a match day.</td></tr><?php endif; ?>
                    <?php foreach ($slots as $s): ?>
                        <tr>
                            <td class="text-nowrap"><?= format_time($s['kickoff']) ?> <small class="text-muted">– <?= format_time(date('H:i', strtotime($s['kickoff']) + $s['length_min'] * 60)) ?></small></td>
                            <td><?= e($s['venue_name']) ?></td>
                            <td><?= $s['match_id'] ? '<span class="badge text-bg-dark">Booked</span>' : '<span class="badge text-bg-light border">Free</span>' ?></td>
                            <td><?= $s['match_id'] ? e(($s['home'] ?? 'TBD') . ' v ' . ($s['away'] ?? 'TBD')) . ' <small class="text-muted">' . e($s['cat_name']) . '</small>' : '–' ?></td>
                            <td class="text-end">
                                <?php if (!$s['match_id']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this slot?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_slot"><input type="hidden" name="id" value="<?= $s['slot_id'] ?>"><input type="hidden" name="date" value="<?= e($selDate) ?>">
                                    <button class="btn btn-sm btn-link text-danger p-0" title="Delete slot" aria-label="Delete slot"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($selDate && $slots): ?>
            <div class="card-footer bg-white text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Delete all free slots on this day? Booked slots are kept.')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_free_day"><input type="hidden" name="date" value="<?= e($selDate) ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete free slots on this day</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
// Live preview of the kick-off times that Generate Slots will create.
(function () {
    const f = document.getElementById('slot-form'), out = document.getElementById('slot-preview');
    if (!f || !out) return;
    const show = () => {
        const [h, m] = f.first_kickoff.value.split(':').map(Number);
        const len = parseInt(f.length_min.value, 10), n = parseInt(f.n_slots.value, 10);
        if (!(len >= 30 && len <= 180 && n >= 1 && n <= 10)) { out.textContent = ''; return; }
        const times = [];
        for (let i = 0, t = h * 60 + m; i < n; i++, t += len) {
            if (t + len > 24 * 60) { out.textContent = 'The last slot would end after midnight.'; return; }
            const hh = Math.floor(t / 60), mm = t % 60;
            times.push(((hh + 11) % 12 + 1) + ':' + String(mm).padStart(2, '0') + (hh < 12 ? ' am' : ' pm'));
        }
        out.innerHTML = '<i class="bi bi-clock me-1"></i>Kick-off times: ' + times.join(', ');
    };
    f.addEventListener('input', show);
    show();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
