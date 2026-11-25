<?php
/**
 * Shared functions for Module 4.0 Team and Player Registration:
 * the team chosen by a team manager, uploads, eligibility checks and squad status.
 */

const POSITIONS = ['GK' => 'Goalkeeper', 'DF' => 'Defender', 'MF' => 'Midfielder', 'FW' => 'Forward'];
const REG_STATUS = [
    'draft' => ['Draft', 'text-bg-light border'],
    'pending' => ['Pending', 'text-bg-warning'],
    'approved' => ['Approved', 'text-bg-success'],
    'rejected' => ['Rejected', 'text-bg-danger'],
];

function status_badge(string $status): string
{
    [$label, $class] = REG_STATUS[$status] ?? [ucfirst($status), 'text-bg-secondary'];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/** All teams of a team manager, newest competition first. */
function my_teams(PDO $pdo, int $userId): array
{
    $s = $pdo->prepare('SELECT t.*, k.name AS cat_name, k.reg_closes, k.min_squad, k.max_squad, k.born_on_or_after, k.competition_id,
                               c.name AS comp_name, c.start_date, c.end_date,
                               (SELECT COUNT(*) FROM team_players tp WHERE tp.team_id = t.team_id) AS n_player
                          FROM teams t
                          JOIN categories k ON k.category_id = t.category_id
                          JOIN competitions c ON c.competition_id = k.competition_id
                         WHERE t.manager_id = ?
                      ORDER BY c.start_date DESC, k.name, t.team_name');
    $s->execute([$userId]);
    return $s->fetchAll();
}

/** The team chosen in the team manager's top bar (?ctx_team=), or their first team, or null. */
function current_team(PDO $pdo, array $user): ?array
{
    if (isset($_GET['ctx_team'])) {
        $_SESSION['ctx_team'] = (int) $_GET['ctx_team'];
    }
    $teams = my_teams($pdo, (int) $user['user_id']);
    foreach ($teams as $t) {
        if ($t['team_id'] == ($_SESSION['ctx_team'] ?? 0)) {
            return $t;
        }
    }
    $_SESSION['ctx_team'] = $teams[0]['team_id'] ?? 0;
    return $teams[0] ?? null;
}

/** A team can be changed by its manager while it is a draft or after it was rejected, and before registration closes. */
function team_editable(array $team): bool
{
    return in_array($team['reg_status'], ['draft', 'rejected'], true) && $team['reg_closes'] >= date('Y-m-d');
}

/**
 * Save an uploaded JPG or PNG (max 2 MB) into uploads/<folder>/.
 * Returns [path relative to the project folder or null if no file was chosen, error message or null].
 */
function save_image_upload(string $field, string $folder, string $prefix): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || ($f['error'] === UPLOAD_ERR_OK && $f['size'] > 2 * 1024 * 1024)) {
        return [null, 'The file is larger than 2 MB.'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, 'The file could not be uploaded. Please try again.'];
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][$info[2] ?? 0] ?? null;
    if (!$ext) {
        return [null, 'Only JPG or PNG images can be uploaded.'];
    }
    $dir = dirname(__DIR__) . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return [null, 'The uploads folder cannot be created.'];
    }
    $name = $prefix . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return [null, 'The file could not be saved.'];
    }
    return ['uploads/' . $folder . '/' . $name, null];
}

/** Delete an earlier upload when it is replaced. Only files inside uploads/ are touched. */
function delete_upload(?string $path): void
{
    if ($path && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
        @unlink(dirname(__DIR__) . '/' . $path);
    }
}

/** MyKad / birth certificate number as 12 digits, or null if it is not 12 digits. */
function clean_id_number(string $value): ?string
{
    $digits = preg_replace('/[\s-]/', '', $value);
    return preg_match('/^\d{12}$/', $digits) ? $digits : null;
}

/** 12 digits shown as YYMMDD-PB-####. */
function format_id_number(string $digits): string
{
    return substr($digits, 0, 6) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8);
}

/** Date of birth in the first six digits of a MyKad / MyKid number (yyyy-mm-dd), or null. */
function id_birth_date(string $digits): ?string
{
    $yy = (int) substr($digits, 0, 2);
    $year = $yy <= (int) date('y') ? 2000 + $yy : 1900 + $yy;
    return valid_date(sprintf('%04d-%s-%s', $year, substr($digits, 2, 2), substr($digits, 4, 2)));
}

/** Players of a team with their details, by jersey number. */
function team_players(PDO $pdo, int $teamId): array
{
    $s = $pdo->prepare('SELECT tp.*, p.full_name, p.id_number, p.date_of_birth, p.photo_path
                          FROM team_players tp JOIN players p ON p.player_id = tp.player_id
                         WHERE tp.team_id = ? ORDER BY tp.jersey_no');
    $s->execute([$teamId]);
    return $s->fetchAll();
}

/**
 * The automatic checks of lk_4003 (also shown to the manager before submitting).
 * Returns a list of [passed, message].
 */
function registration_checks(PDO $pdo, array $team): array
{
    $players = team_players($pdo, (int) $team['team_id']);
    $n = count($players);
    $checks = [];

    $checks[] = [$n >= $team['min_squad'] && $n <= $team['max_squad'],
        $n < $team['min_squad'] ? "Squad has $n players, below the minimum of {$team['min_squad']}"
            : ($n > $team['max_squad'] ? "Squad has $n players, above the maximum of {$team['max_squad']}" : 'Squad size within the limit')];

    $old = array_filter($players, fn ($p) => $p['date_of_birth'] < $team['born_on_or_after']);
    $checks[] = [!$old, $old ? implode(', ', array_column($old, 'full_name')) . ' ' . (count($old) > 1 ? 'are' : 'is') . ' over the age limit'
        : 'All players born on or after ' . format_date($team['born_on_or_after'])];

    $jerseys = array_count_values(array_column($players, 'jersey_no'));
    $checks[] = [max($jerseys ?: [0]) <= 1, max($jerseys ?: [0]) <= 1 ? 'No duplicate jersey numbers' : 'Some jersey numbers are used twice'];

    // Players who are also registered with another team in the same competition.
    // A team that was returned to its manager is left out: that team has to fix it before it is submitted again.
    $s = $pdo->prepare("SELECT p.full_name, t2.team_name
                          FROM team_players tp
                          JOIN players p ON p.player_id = tp.player_id
                          JOIN team_players tp2 ON tp2.player_id = tp.player_id AND tp2.team_id <> tp.team_id
                          JOIN teams t2 ON t2.team_id = tp2.team_id
                          JOIN categories k2 ON k2.category_id = t2.category_id
                         WHERE tp.team_id = ? AND k2.competition_id = ? AND t2.reg_status <> 'rejected'
                      ORDER BY p.full_name");
    $s->execute([$team['team_id'], $team['competition_id']]);
    $dups = $s->fetchAll();
    if (!$dups) {
        $checks[] = [true, 'No player is registered with another team in this competition'];
    }
    foreach ($dups as $d) {
        $checks[] = [false, $d['full_name'] . ' is also registered with ' . $d['team_name']];
    }

    $missing = array_filter(['club / organisation' => $team['organisation'], 'head coach name' => $team['coach_name']], fn ($v) => trim((string) $v) === '');
    if ($missing) {
        $checks[] = [false, 'Team details missing: ' . implode(', ', array_keys($missing))];
    }
    return $checks;
}

function checks_pass(array $checks): bool
{
    return !in_array(false, array_column($checks, 0), true);
}

/**
 * Squad status of lk_4004: goals and cards of each approved player and whether they can play next.
 * Returns ['players' => [...], 'next' => next match or null, 'limit' => yellow limit, 'played' => team matches completed].
 */
function squad_status(PDO $pdo, array $team): array
{
    $s = $pdo->prepare('SELECT * FROM competition_rules WHERE category_id = ?');
    $s->execute([$team['category_id']]);
    $rules = $s->fetch() ?: ['yellow_limit' => 2, 'clear_yellows' => 0];
    $limit = (int) $rules['yellow_limit'];

    // Yellow cards are cleared after the group stage when the rules say so and every group match is completed.
    $knockoutOnly = false;
    if ($rules['clear_yellows']) {
        $s = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status <> 'completed') AS open FROM matches WHERE category_id = ? AND stage = 'group'");
        $s->execute([$team['category_id']]);
        $g = $s->fetch();
        $knockoutOnly = $g['n'] > 0 && (int) $g['open'] === 0;
    }

    $s = $pdo->prepare("SELECT tp.team_player_id, tp.jersey_no, tp.position, p.full_name,
            SUM(e.event_type = 'goal') AS goals,
            SUM(e.event_type = 'yellow') AS yellows,
            SUM(e.event_type = 'yellow' AND m.stage <> 'group') AS ko_yellows,
            SUM(e.event_type = 'red') AS reds,
            (SELECT COUNT(*) FROM suspensions s WHERE s.team_player_id = tp.team_player_id AND s.reason = 'yellow_limit') AS yellow_bans,
            (SELECT COUNT(*) FROM suspensions s WHERE s.team_player_id = tp.team_player_id AND s.status = 'active') AS active_ban
          FROM team_players tp
          JOIN players p ON p.player_id = tp.player_id
     LEFT JOIN match_events e ON e.team_player_id = tp.team_player_id
     LEFT JOIN matches m ON m.match_id = e.match_id
         WHERE tp.team_id = ? AND tp.status = 'approved'
      GROUP BY tp.team_player_id ORDER BY tp.jersey_no");
    $s->execute([$team['team_id']]);
    $players = [];
    $maxGoals = 0;
    foreach ($s->fetchAll() as $p) {
        $p = array_map(fn ($v) => $v ?? 0, $p);
        // Yellow cards that have not yet led to a ban
        $current = $knockoutOnly ? (int) $p['ko_yellows'] : (int) $p['yellows'] - $limit * (int) $p['yellow_bans'];
        if ($p['active_ban']) {
            $p['state'] = 'Suspended';
        } elseif ($limit > 1 && $current === $limit - 1) {
            $p['state'] = 'At risk';
        } else {
            $p['state'] = 'Eligible';
        }
        $maxGoals = max($maxGoals, (int) $p['goals']);
        $players[] = $p;
    }
    foreach ($players as &$p) {
        $p['note'] = $p['state'] === 'Suspended' ? ($p['reds'] ? 'Red card' : 'Reached ' . $limit . ' yellow cards') : '';
        if ($maxGoals > 0 && (int) $p['goals'] === $maxGoals) {
            $p['note'] = trim($p['note'] . ' Team top scorer');
        }
    }
    unset($p);

    $s = $pdo->prepare("SELECT m.*, s.slot_date, s.kickoff, v.name AS venue, g.group_name,
                               IF(m.home_team_id = ?, a.team_name, h.team_name) AS opponent
                          FROM matches m
                          JOIN match_slots s ON s.slot_id = m.slot_id
                          JOIN venues v ON v.venue_id = s.venue_id
                     LEFT JOIN `groups` g ON g.group_id = m.group_id
                     LEFT JOIN teams h ON h.team_id = m.home_team_id
                     LEFT JOIN teams a ON a.team_id = m.away_team_id
                         WHERE (m.home_team_id = ? OR m.away_team_id = ?) AND m.status = 'scheduled'
                      ORDER BY s.slot_date, s.kickoff LIMIT 1");
    $s->execute([$team['team_id'], $team['team_id'], $team['team_id']]);
    $next = $s->fetch() ?: null;

    $s = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE (home_team_id = ? OR away_team_id = ?) AND status = 'completed'");
    $s->execute([$team['team_id'], $team['team_id']]);

    return ['players' => $players, 'next' => $next, 'limit' => $limit, 'played' => (int) $s->fetchColumn()];
}

const STAGES = ['group' => 'Group', 'r16' => 'Round of 16', 'qf' => 'Quarter-final', 'sf' => 'Semi-final', 'final' => 'Final'];
