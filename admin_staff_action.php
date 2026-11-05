<?php


require_once __DIR__ . '/../app/auth.php';

// ==========================================
// ADMINISTRATOR ACCESS
// ==========================================

$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

verifyCsrf();

// ==========================================
// REDIRECT WITH MESSAGE
// ==========================================

function staffActionRedirect(
    string $message,
    bool $success = false
): void {
    $key = $success
        ? 'staff_success'
        : 'staff_action_error';

    $_SESSION[$key] = $message;

    header('Location: admin_staff.php', true, 303);
    exit;
}

// ==========================================
// VALIDATE ACTION AND STAFF ID
// ==========================================

$action = $_POST['action'] ?? null;
$rawStaffId = $_POST['staff_id'] ?? null;

if (
    !is_string($action) ||
    !is_string($rawStaffId) ||
    !ctype_digit($rawStaffId) ||
    (int) $rawStaffId < 1
) {
    staffActionRedirect('Invalid staff account request.');
}

$staffId = (int) $rawStaffId;

$allowedActions = [
    'activate',
    'deactivate',
    'reset_password',
    'archive'
];
if (!in_array($action, $allowedActions, true)) {
    staffActionRedirect('Invalid account action.');
}

// Validate password input before starting transaction.
$newPasswordHash = null;

if ($action === 'reset_password') {
    $password = $_POST['new_password'] ?? null;
    $confirm = $_POST['confirm_password'] ?? null;

    if (
        !is_string($password) ||
        !is_string($confirm)
    ) {
        staffActionRedirect('Invalid password reset request.');
    }

    if (
        strlen($password) < 12 ||
        strlen($password) > 72
    ) {
        staffActionRedirect(
            'Password must contain 12–72 bytes.'
        );
    }

    if (!hash_equals($password, $confirm)) {
        staffActionRedirect(
            'New passwords do not match.'
        );
    }

    try {
        $newPasswordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );
    } catch (Throwable $e) {
        error_log(
            'TripLedger password hashing error: ' .
            $e->getMessage()
        );

        staffActionRedirect(
            'Unable to prepare password reset.'
        );
    }

    unset($password, $confirm);
}

// ==========================================
// TRANSACTION AND AUDIT LOGGING
// ==========================================

$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock the staff account during the update.
    $stmt = $pdo->prepare(
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
           AND role = ?
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([
        $staffId,
        ROLE_STAFF
    ]);

    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$staff) {
        $pdo->rollBack();

        staffActionRedirect(
            'Authorized Staff account not found.'
        );
    }

    if ($staff['archived_at'] !== null) {
    $pdo->rollBack();

    staffActionRedirect(
        'This staff account has already been archived.'
    );
}

    $oldStatus = (int) $staff['is_active'];
    $oldVersion = (int) $staff['session_version'];

    // Metadata only; never include passwords or hashes.
    $oldValues = [
        'target_user_id' => $staffId,
        'username' => $staff['username']
    ];

    $newValues = [
        'target_user_id' => $staffId,
        'username' => $staff['username']
    ];

    $auditAction = '';
    $successMessage = '';

    // ======================================
    // ACTIVATE STAFF
    // ======================================

    if ($action === 'activate') {
        if ($oldStatus === 1) {
            $pdo->rollBack();

            staffActionRedirect(
                'Staff account is already active.'
            );
        }

        $update = $pdo->prepare(
            "UPDATE users
             SET is_active = 1,
                 session_version = session_version + 1
             WHERE id = ?
               AND role = ?"
        );

        $update->execute([
            $staffId,
            ROLE_STAFF
        ]);

        $oldValues['is_active'] = 0;
        $newValues['is_active'] = 1;

        $auditAction = 'STAFF_ACCOUNT_ACTIVATED';
        $successMessage = 'Staff account activated successfully.';
    }

    // ======================================
    // DEACTIVATE STAFF
    // ======================================

    elseif ($action === 'deactivate') {
        if ($oldStatus === 0) {
            $pdo->rollBack();

            staffActionRedirect(
                'Staff account is already inactive.'
            );
        }

        $update = $pdo->prepare(
            "UPDATE users
             SET is_active = 0,
                 session_version = session_version + 1
             WHERE id = ?
               AND role = ?"
        );

        $update->execute([
            $staffId,
            ROLE_STAFF
        ]);

        $oldValues['is_active'] = 1;
        $newValues['is_active'] = 0;

        $auditAction = 'STAFF_ACCOUNT_DEACTIVATED';
        $successMessage = 'Staff account deactivated successfully.';
    }

    // ======================================
    // RESET STAFF PASSWORD
    // ======================================

    elseif ($action === 'reset_password') {
        $update = $pdo->prepare(
            "UPDATE users
             SET password_hash = ?,
                 session_version = session_version + 1
             WHERE id = ?
               AND role = ?"
        );

        $update->execute([
            $newPasswordHash,
            $staffId,
            ROLE_STAFF
        ]);

        // No password contents or hashes in the audit.
        $oldValues['password'] = 'Existing password';
        $newValues['password'] = 'Password reset by Administrator';

        $auditAction = 'STAFF_PASSWORD_RESET';
        $successMessage = 'Staff password reset successfully.';
    }


    
    // ======================================
    // ARCHIVE STAFF ACCOUNT (SOFT DELETE)
    // ======================================

    elseif ($action === 'archive') {

        $update = $pdo->prepare(
            "UPDATE users
             SET archived_at = NOW(),
                 is_active = 0,
                 session_version = session_version + 1
             WHERE id = ?
               AND role = ?
               AND archived_at IS NULL"
        );

        $update->execute([
            $staffId,
            ROLE_STAFF
        ]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to archive staff account.'
            );
        }

        $oldValues['is_active'] = $oldStatus;
        $oldValues['archived_at'] = null;

        $newValues['is_active'] = 0;
        $newValues['archived_at'] = 'Archived';

        $auditAction = 'STAFF_ACCOUNT_ARCHIVED';

        $successMessage =
            'Staff account archived successfully.';
    }


    // ======================================
    // INSERT AUDIT LOG
    // ======================================

    $audit = $pdo->prepare(
        "INSERT INTO audit_logs
            (
                user_id,
                trip_ticket_id,
                action,
                old_values,
                new_values
            )
         VALUES (?, NULL, ?, ?, ?)"
    );

    $oldJson = json_encode(
        $oldValues,
        JSON_THROW_ON_ERROR
    );

    $newJson = json_encode(
        $newValues,
        JSON_THROW_ON_ERROR
    );

    $audit->execute([
        (int) $admin['id'],
        $auditAction,
        $oldJson,
        $newJson
    ]);

    // Keep the user update and audit record atomic.
    $pdo->commit();

    staffActionRedirect(
        $successMessage,
        true
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'TripLedger administrative action failed: ' .
        $e->getMessage()
    );

    staffActionRedirect(
        'Unable to complete the administrative action.'
    );
}
