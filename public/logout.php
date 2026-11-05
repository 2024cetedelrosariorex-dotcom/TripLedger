<?php

require_once __DIR__ . '/../app/auth.php';

// ==========================================
// TRIPLEDGER SECURE LOGOUT
// ==========================================

// Logout must use POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// Verify the logout security token.
verifyCsrf();

// Clear all session variables.
$_SESSION = [];

// Delete the PHP session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}

// Destroy server-side session data.
session_destroy();

// Prevent the logout response from being cached.
header('Cache-Control: no-store, no-cache, must-revalidate');

// Return to login page.
header('Location: index.php');
exit;
