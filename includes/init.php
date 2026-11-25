<?php
/**
 * Loaded at the top of every page: starts the session, connects to the
 * database and loads the shared helper functions.
 */
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

// URL path of the project folder, e.g. "/ligakita" in htdocs/ligakita, "" at the web root.
if ($config['base_url'] !== '') {
    define('BASE_URL', rtrim($config['base_url'], '/'));
} else {
    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $base = ($docRoot !== '' && stripos($root, $docRoot) === 0) ? substr($root, strlen($docRoot)) : '';
    define('BASE_URL', rtrim($base, '/'));
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => BASE_URL . '/']);
session_name('ligakita');
session_start();

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $ex) {
    http_response_code(500);
    exit('<h3>Cannot connect to the database.</h3><p>Check that MySQL is running, that database/ligakita.sql has been imported, '
        . 'and that the settings in includes/config.php are correct.</p>');
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
