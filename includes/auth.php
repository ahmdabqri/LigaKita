<?php
/** Login state and role checks (Module 1.0 User Management). */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Only let logged-in users with the given role open the page.
 * Users who must change their temporary password are sent to the Change Password page first.
 */
function require_login(?string $role = null): array
{
    $user = current_user();
    if (!$user) {
        flash('warning', 'Please log in to continue.');
        redirect('login.php');
    }
    if (!empty($user['must_change_pw']) && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
        redirect('change_password.php');
    }
    if ($role !== null && $user['role'] !== $role) {
        http_response_code(403);
        exit('You do not have access to this page.');
    }
    return $user;
}

function home_for(array $user): string
{
    return $user['role'] === 'admin' ? 'admin/index.php' : 'manager/index.php';
}
