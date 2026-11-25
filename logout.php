<?php
/** lk_1001 Log out: clears the session and returns to the login page. */
require __DIR__ . '/includes/init.php';
check_csrf();
$_SESSION = [];
session_regenerate_id(true); // new session ID, the old session is deleted
flash('success', 'You have logged out.');
redirect('login.php');
