<?php

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
}

// ==========================================
// SESSION CONFIGURATION
// ==========================================

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// ==========================================
// USER ROLES
// ==========================================

const ROLE_ADMIN = 'Administrator';
const ROLE_STAFF = 'Authorized Staff';

// 30 minutes of inactivity.
const SESSION_IDLE_TIMEOUT = 1800;

// ==========================================
// HTML ESCAPING
// ==========================================

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// ==========================================
// SESSION HELPERS
// ==========================================

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'])
        && filter_var(
            $_SESSION['user_id'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) !== false;
}

/**
 * Clear login session when the account is invalid,
 * inactive, expired, or its session is revoked.
 */
function clearLoginSession(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// ==========================================
// AUTHENTICATED USER VALIDATION
// ==========================================

function currentUser(): ?array
{
    // No authenticated session.
    if (!isLoggedIn()) {
        return null;
    }

    // ======================================
    // CHECK SESSION INACTIVITY
    // ======================================

    $now = time();

    $lastActivity = $_SESSION['last_activity'] ?? null;

    if (
        !is_int($lastActivity) ||
        $lastActivity > $now ||
        ($now - $lastActivity) >= SESSION_IDLE_TIMEOUT
    ) {
        clearLoginSession();
        return null;
    }

    // ======================================
    // CHECK SESSION VERSION
    // ======================================

    // Older sessions without a session version
    // must sign in again.

    if (
        !isset($_SESSION['session_version']) ||
        !is_int($_SESSION['session_version'])
    ) {
        clearLoginSession();
        return null;
    }

    // ======================================
    // LOAD CURRENT USER FROM DATABASE
    // ======================================

    $stmt = db()->prepare(
        "SELECT
            id,
            username,
            full_name,
            role,
            is_active,
             archived_at,
            session_version
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->execute([
        (int) $_SESSION['user_id']
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        clearLoginSession();
        return null;
    }

    // ======================================
    // VERIFY ACCOUNT STATUS AND ROLE
    // ======================================

   if (
    (int) $user['is_active'] !== 1 ||
    $user['archived_at'] !== null ||
        !in_array(
            $user['role'],
            [ROLE_ADMIN, ROLE_STAFF],
            true
        )
    ) {
        clearLoginSession();
        return null;
    }

    // ======================================
    // VERIFY SESSION VERSION
    // ======================================

    // Reject sessions that were revoked after
    // password reset, account deactivation,
    // or another security-related action.

    if (
        (int) $user['session_version'] !==
        $_SESSION['session_version']
    ) {
        clearLoginSession();
        return null;
    }

    // ======================================
    // UPDATE LAST ACTIVITY
    // ======================================

    // Refresh activity only after successful
    // authentication validation.

    $_SESSION['last_activity'] = $now;

    return $user;
}

// ==========================================
// AUTHORIZATION
// ==========================================

/**
 * Preserve compatibility with existing
 * staff-only pages.
 */
function requireLogin(): array
{
    return requireStaff();
}

/**
 * Only Authorized Staff may access
 * the operational TripLedger modules.
 */
function requireStaff(): array
{
    $user = currentUser();

    if (!$user) {
        if (str_starts_with(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'api_')) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']));
        }
        header('Location: index.php');
        exit;
    }

    if ($user['role'] !== ROLE_STAFF) {
        http_response_code(403);
        if (str_starts_with(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'api_')) {
            header('Content-Type: application/json');
            exit(json_encode(['success' => false, 'message' => 'Authorized Staff only.']));
        }
        exit('Access denied: Authorized Staff only.');
    }

    return $user;
}

/**
 * Only Administrator may access
 * administrative modules.
 */
function requireAdmin(): array
{
    $user = currentUser();

    if (!$user) {
        if (str_starts_with(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'api_')) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']));
        }
        header('Location: index.php');
        exit;
    }

    if ($user['role'] !== ROLE_ADMIN) {
        http_response_code(403);
        exit('Access denied: Administrator only.');
    }

    return $user;
}

/**
 * Allow either valid role.
 */
function requireAnyRole(): array
{
    $user = currentUser();

    if (!$user) {
        if (str_starts_with(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'api_')) {
            http_response_code(401);
            header('Content-Type: application/json');
            exit(json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']));
        }
        header('Location: index.php');
        exit;
    }

    return $user;
}

// ==========================================
// CSRF PROTECTION
// ==========================================

/**
 * Generate a cryptographically secure CSRF token
 * and reuse it for the current session.
 */
function csrfToken(): string
{
    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify the token included in POST forms.
 *
 * Reject missing, invalid, or expired tokens.
 */
function verifyCsrf(): void
{
    $stored = $_SESSION['csrf_token'] ?? null;
    $submitted = $_POST['csrf_token'] ?? null;

    if (
        !is_string($stored) ||
        !is_string($submitted) ||
        !hash_equals($stored, $submitted)
    ) {
        http_response_code(403);

        exit(
            'Invalid security token. Please refresh the page.'
        );
    }
}
