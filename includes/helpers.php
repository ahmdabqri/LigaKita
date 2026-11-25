<?php
/** Small helper functions shared by every page. */

/** Escape text for safe output in HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Build a URL inside the project, e.g. url('admin/accounts.php'). */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Store a one-time message shown on the next page. Types: success, danger, warning, info.
 * The message is escaped when shown, unless $html is true (only for messages whose values are already escaped).
 */
function flash(string $type, string $message, bool $html = false): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $html ? $message : e($message)];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* ---------- CSRF protection for every form that changes data ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Stop the request if the form did not come from this site. */
function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Invalid or expired form. Please go back, reload the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed text from the submitted form. */
function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Check a dd/mm/yyyy or yyyy-mm-dd date and return it as yyyy-mm-dd, or null if invalid. */
function valid_date(string $value): ?string
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function format_date(?string $ymd): string
{
    return $ymd ? date('d/m/Y', strtotime($ymd)) : '–';
}

/** Random temporary password without look-alike characters (0/O, 1/l). */
function temp_password(int $length = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

const FORMATS = [
    'league' => 'League',
    'knockout' => 'Knockout',
    'group_knockout' => 'Group stage + Knockout',
];

const TIEBREAKS = [
    'GD' => 'Goal difference',
    'GF' => 'Goals scored',
    'H2H' => 'Head-to-head result',
    'FP' => 'Fewest cards (fair play)',
];

/* ---------- Selected competition and category (top bar of the admin pages) ---------- */

/** Change the selected competition/category when the top bar lists are used. */
function handle_context_switch(PDO $pdo): void
{
    if (isset($_GET['ctx_comp'])) {
        $_SESSION['ctx_comp'] = (int) $_GET['ctx_comp'];
        unset($_SESSION['ctx_cat']);
    }
    if (isset($_GET['ctx_cat'])) {
        $_SESSION['ctx_cat'] = (int) $_GET['ctx_cat'];
    }
}

/** The competition chosen in the top bar (or the latest one), or null if none exist. */
function current_competition(PDO $pdo): ?array
{
    $id = $_SESSION['ctx_comp'] ?? 0;
    $stmt = $pdo->prepare('SELECT * FROM competitions WHERE competition_id = ?');
    $stmt->execute([$id]);
    $comp = $stmt->fetch();
    if (!$comp) {
        $comp = $pdo->query('SELECT * FROM competitions ORDER BY start_date DESC, competition_id DESC LIMIT 1')->fetch() ?: null;
        $_SESSION['ctx_comp'] = $comp['competition_id'] ?? 0;
    }
    return $comp;
}

/** The category chosen in the top bar (or the first of the competition), or null. */
function current_category(PDO $pdo): ?array
{
    $comp = current_competition($pdo);
    if (!$comp) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE category_id = ? AND competition_id = ?');
    $stmt->execute([$_SESSION['ctx_cat'] ?? 0, $comp['competition_id']]);
    $cat = $stmt->fetch();
    if (!$cat) {
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE competition_id = ? ORDER BY name LIMIT 1');
        $stmt->execute([$comp['competition_id']]);
        $cat = $stmt->fetch() ?: null;
        $_SESSION['ctx_cat'] = $cat['category_id'] ?? 0;
    }
    return $cat;
}

/* ---------- Form validation display ---------- */

/** "is-invalid" class when the field has an error. */
function inv(array $errors, string $key): string
{
    return isset($errors[$key]) ? 'is-invalid' : '';
}

/** Error message under a field. */
function err(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>' : '';
}

/** Message shown on setup pages when no competition or category exists yet. */
function need_setup(string $what): void
{
    $link = $what === 'competition' ? url('admin/competition_form.php') : url('admin/categories.php');
    $text = $what === 'competition' ? 'Create a competition first.' : 'Add a category to this competition first.';
    echo '<div class="alert alert-info">' . e($text) . ' <a href="' . $link . '">Go there now</a>.</div>';
}
