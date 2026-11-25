<?php
/**
 * LigaKita configuration.
 * The defaults suit XAMPP and Laragon (MySQL user "root" with no password).
 * Change them here if your MySQL server uses a different user or password.
 */
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'ligakita',
    'db_user' => 'root',
    'db_pass' => '',
    // Leave empty to detect the URL of the project folder automatically
    // (for example /ligakita when the project is in htdocs/ligakita).
    'base_url' => '',
];
