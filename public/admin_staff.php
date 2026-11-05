
<?php
require_once __DIR__ . '/../app/auth.php';

$user = requireAdmin();
$error = '';
$success = '';

if (isset($_SESSION['staff_action_error'])) {
    $error = (string) $_SESSION['staff_action_error'];
}
if (isset($_SESSION['staff_success'])) {
    $success = (string) $_SESSION['staff_success'];
}
unset($_SESSION['staff_action_error'], $_SESSION['staff_success']);

$username = '';
$fullName = '';

// CREATE STAFF ACCOUNT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (($_POST['form_action'] ?? '') !== 'create_staff') {
        $error = 'Invalid account creation request.';
    } else {
        $rawUsername = $_POST['username'] ?? null;
        $rawName = $_POST['full_name'] ?? null;
        $password = $_POST['password'] ?? null;
        $confirm = $_POST['confirm_password'] ?? null;

        if (
            !is_string($rawUsername) ||
            !is_string($rawName) ||
            !is_string($password) ||
            !is_string($confirm)
        ) {
            $error = 'Invalid form submission.';
        } else {
            $username = trim($rawUsername);
            $fullName = trim($rawName);

            if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
                $error = 'Username must contain 3–50 letters, numbers, or underscores.';
            } elseif ($fullName === '' || strlen($fullName) > 150) {
                $error = 'Full name is required and must not exceed 150 bytes.';
            } elseif (strlen($password) < 12 || strlen($password) > 72) {
                $error = 'Password must contain 12–72 bytes.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $error = 'Password must contain an uppercase letter.';
            } elseif (!preg_match('/[a-z]/', $password)) {
                $error = 'Password must contain a lowercase letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $error = 'Password must contain a number.';
            } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $error = 'Password must contain a special character.';
            } elseif (!hash_equals($password, $confirm)) {
                $error = 'Passwords do not match.';
            } else {
                $pdo = db();

                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    unset($password, $confirm);

                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        "INSERT INTO users
                         (username, full_name, password_hash, role, is_active)
                         VALUES (?, ?, ?, ?, 1)"
                    );
                    $stmt->execute([
                        $username, $fullName, $hash, ROLE_STAFF
                    ]);

                    $staffId = (int) $pdo->lastInsertId();

                    $audit = $pdo->prepare(
                        "INSERT INTO audit_logs
                         (user_id, trip_ticket_id, action,
                          old_values, new_values)
                         VALUES (?, NULL, ?, NULL, ?)"
                    );
                    $audit->execute([
                        (int) $user['id'],
                        'STAFF_ACCOUNT_CREATED',
                        json_encode([
                            'target_user_id' => $staffId,
                            'username' => $username,
                            'full_name' => $fullName,
                            'role' => ROLE_STAFF,
                            'is_active' => 1
                        ], JSON_THROW_ON_ERROR)
                    ]);

                    $pdo->commit();

                    $_SESSION['staff_success'] =
                        'Authorized Staff account created successfully.';

                    header('Location: admin_staff.php', true, 303);
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if (
                        $e->getCode() === '23000' &&
                        (int) ($e->errorInfo[1] ?? 0) === 1062
                    ) {
                        $error = 'Username already exists.';
                    } else {
                        error_log('Staff creation error: ' . $e->getMessage());
                        $error = 'Unable to create staff account.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Staff creation error: ' . $e->getMessage());
                    $error = 'Unable to create staff account.';
                }
            }
        }
    }
}

// EXISTING NON-ARCHIVED STAFF
$stmt = db()->prepare(
    "SELECT id, username, full_name, role, is_active
     FROM users
     WHERE role = ?
       AND archived_at IS NULL
     ORDER BY id ASC"
);
$stmt->execute([ROLE_STAFF]);
$staffAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStaff = count($staffAccounts);
$activeStaff = count(array_filter(
    $staffAccounts,
    static fn($staff) => (int) $staff['is_active'] === 1
));
$inactiveStaff = $totalStaff - $activeStaff;

// ARCHIVED STAFF
$archivedStmt = db()->prepare(
    "SELECT id, username, full_name, archived_at
     FROM users
     WHERE role = ?
       AND archived_at IS NOT NULL
     ORDER BY archived_at DESC, id DESC"
);
$archivedStmt->execute([ROLE_STAFF]);
$archivedAccounts = $archivedStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Account Management | TripLedger</title>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="assets/components.css">
    <script src="assets/app.js" defer></script>
    <script src="assets/admin-staff.js" defer></script>
</head>
<body>

<div class="admin-layout">

    <!-- ADMIN SIDEBAR -->
    <aside class="admin-sidebar">

        <div class="admin-brand">
            <img src="assets/dar-logo.png.webp" alt="DAR Logo">
            <div>
                <strong>TripLedger</strong>
                <small>Administration</small>
            </div>
        </div>

        <div class="admin-menu-label">ADMINISTRATION</div>

        <nav aria-label="Administrator navigation">
            <a class="admin-nav-link"
               href="admin_dashboard.php">
                Dashboard
            </a>

            <a class="admin-nav-link active"
               href="admin_staff.php"
               aria-current="page">
                Staff Account Management
            </a>

            <a class="admin-nav-link" href="admin_audit.php">
                Activity Logs
            </a>

            <a class="admin-nav-link" href="admin_ai.php">
                AI Settings
            </a>
        </nav>

        <div class="admin-sidebar-bottom">
            <div class="admin-profile">
                <div class="admin-avatar">
                    <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
                </div>
                <div>
                    <strong><?= e($user['full_name']) ?></strong>
                    <small>Administrator</small>
                </div>
            </div>

            <form method="POST" action="logout.php">
                <input type="hidden" name="csrf_token"
                       value="<?= e(csrfToken()) ?>">
                <button type="submit" class="admin-logout">
                    Logout
                </button>
            </form>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="admin-content">

        <header class="admin-topbar">
            <div>
                <h1>Staff Account Management</h1>
                <p>General Services and Procurement Office</p>
            </div>
            <div class="admin-topbar-user">
                <?= e($user['full_name']) ?>
            </div>
        </header>

        <div class="admin-body">

            <section class="admin-welcome">
                <h2>Manage Authorized Staff</h2>
                <p>
                    Create, activate, deactivate, reset passwords,
                    and archive staff accounts.
                </p>
            </section>

            <?php if ($success !== ''): ?>
                <div class="admin-message admin-message-success"
                     role="status"
                     data-toast-message="<?= e($success) ?>"
                     data-toast-type="success">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="admin-message admin-message-error"
                     role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <!-- STAFF STATISTICS -->
            <section class="admin-stats"
                     aria-label="Staff account statistics">

                <article class="admin-stat-card">
                    <p>Total Staff Accounts</p>
                    <strong><?= number_format($totalStaff) ?></strong>
                </article>

                <article class="admin-stat-card">
                    <p>Active Staff</p>
                    <strong><?= number_format($activeStaff) ?></strong>
                </article>

                <article class="admin-stat-card">
                    <p>Inactive Staff</p>
                    <strong><?= number_format($inactiveStaff) ?></strong>
                </article>

            </section>

            <!-- CREATE STAFF -->
            <section class="admin-card">
                <h2>Create Authorized Staff Account</h2>
                <p>
                    Create an individual account for a staff
                    member. Fields marked * are required.
                </p>

                <form method="POST"
                      action="admin_staff.php"
                      class="admin-staff-form"
                      id="createStaffForm">

                    <input type="hidden" name="csrf_token"
                           value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="form_action"
                           value="create_staff">

                    <div class="admin-form-grid">

                        <div class="admin-form-field">
                            <label for="username">Username *</label>
                            <input type="text"
                                   id="username"
                                   name="username"
                                   maxlength="50"
                                   minlength="3"
                                   pattern="[A-Za-z0-9_]{3,50}"
                                   autocomplete="off"
                                   value="<?= e($username) ?>"
                                   required>
                        </div>

                        <div class="admin-form-field">
                            <label for="full_name">Full Name *</label>
                            <input type="text"
                                   id="full_name"
                                   name="full_name"
                                   maxlength="150"
                                   autocomplete="off"
                                   value="<?= e($fullName) ?>"
                                   required>
                        </div>

                        <div class="admin-form-field">
                            <label for="password">Password *</label>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   minlength="12"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>
                        </div>

                        <div class="admin-form-field">
                            <label for="confirm_password">
                                Confirm Password *
                            </label>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   minlength="12"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>
                        </div>
                    </div>

                    <p style="margin-top:12px;color:#64748b;">
                        Password must be at least 12 characters
                        and include uppercase, lowercase, number,
                        and special character.
                    </p>

                    <div class="admin-form-actions">
                        <button type="submit" class="admin-save-btn">
                            Create Staff Account
                        </button>
                    </div>
                </form>
            </section>

            <!-- EXISTING STAFF ACCOUNTS -->
            <section class="admin-card admin-list-section">
                <h2>Existing Staff Accounts</h2>
                <p>
                    Manage active and inactive staff accounts.
                    Deleted accounts are moved to the archive.
                </p>

                <?php if (!$staffAccounts): ?>
                    <div class="admin-empty-state">
                        No Authorized Staff accounts found.
                    </div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffAccounts as $staff): ?>
                                    <?php
                                        $isActive =
                                            (int) $staff['is_active'] === 1;

                                        $accountAction = $isActive
                                            ? 'deactivate'
                                            : 'activate';

                                        $actionLabel = $isActive
                                            ? 'Deactivate'
                                            : 'Activate';
                                    ?>

                                    <tr>
                                        <td><?= (int) $staff['id'] ?></td>
                                        <td><?= e($staff['username']) ?></td>
                                        <td><?= e($staff['full_name']) ?></td>
                                        <td><?= e($staff['role']) ?></td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="admin-status active">
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="admin-status inactive">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="admin-account-actions">

                                                <!-- ACTIVATE / DEACTIVATE -->
                                                <form method="POST"
                                                      action="admin_staff_action.php"
                                                      data-confirm-title="<?= e($actionLabel) ?> Staff Account"
                                                      data-confirm="Are you sure you want to <?= e($accountAction) ?> the account of <?= e($staff['username']) ?>?"
                                                      data-confirm-button="<?= e($actionLabel) ?> Account"
                                                      data-confirm-danger="<?= $isActive ? 'true' : 'false' ?>">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= e(csrfToken()) ?>">
                                                    <input type="hidden"
                                                           name="staff_id"
                                                           value="<?= (int) $staff['id'] ?>">
                                                    <input type="hidden"
                                                           name="action"
                                                           value="<?= e($accountAction) ?>">

                                                    <button type="submit"
                                                            class="admin-action-btn <?= $isActive
                                                                ? 'admin-deactivate'
                                                                : 'admin-activate' ?>">
                                                        <?= e($actionLabel) ?>
                                                    </button>
                                                </form>

                                                <!-- RESET PASSWORD -->
                                                <button type="button"
                                                        class="admin-action-btn admin-reset"
                                                        data-reset-staff-id="<?= (int) $staff['id'] ?>"
                                                        data-reset-staff-name="<?= e($staff['username']) ?>">
                                                    Reset Password
                                                </button>

                                                <!-- DELETE / ARCHIVE ACCOUNT -->
                                                <form method="POST"
                                                      action="admin_staff_action.php"
                                                      data-confirm-title="Delete Staff Account"
                                                      data-confirm="Are you sure you want to delete the staff account <?= e($staff['username']) ?>? This will archive the account, revoke login access, and preserve historical trip tickets and audit logs."
                                                      data-confirm-button="Yes, Delete Account"
                                                      data-confirm-danger="true">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= e(csrfToken()) ?>">

                                                    <input type="hidden"
                                                           name="staff_id"
                                                           value="<?= (int) $staff['id'] ?>">

                                                    <input type="hidden"
                                                           name="action"
                                                           value="archive">

                                                    <button type="submit"
                                                            class="admin-action-btn admin-deactivate"
                                                            style="background:#b91c1c;color:#fff;">
                                                        Delete Account
                                                    </button>
                                                </form>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ARCHIVED STAFF ACCOUNTS -->
            <section class="admin-card admin-list-section">

                <h2>Archived Staff Accounts</h2>

                <p>
                    Archived accounts cannot log in. Historical
                    trip ticket records and audit logs are preserved.
                </p>

                <?php if (!$archivedAccounts): ?>
                    <div class="admin-empty-state">
                        No archived staff accounts.
                    </div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Status</th>
                                    <th>Archived Date</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($archivedAccounts as $archived): ?>
                                    <tr>
                                        <td><?= (int) $archived['id'] ?></td>
                                        <td><?= e($archived['username']) ?></td>
                                        <td><?= e($archived['full_name']) ?></td>
                                        <td>
                                            <span class="admin-status inactive">
                                                Archived
                                            </span>
                                        </td>
                                        <td>
                                            <?= e($archived['archived_at']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </section>

            <!-- PASSWORD RESET SECTION -->
            <section class="admin-card admin-list-section"
                     id="passwordResetSection"
                     hidden>

                <h2>Reset Staff Password</h2>

                <p>
                    Set a new password for:
                    <strong id="resetStaffName"></strong>
                </p>

                <form method="POST"
                      action="admin_staff_action.php"
                      class="admin-staff-form"
                      id="adminResetForm"
                      data-confirm="Are you sure you want to reset this staff member's password?"
                      data-confirm-title="Reset Staff Password"
                      data-confirm-button="Reset Password">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= e(csrfToken()) ?>">

                    <input type="hidden"
                           name="action"
                           value="reset_password">

                    <input type="hidden"
                           name="staff_id"
                           id="resetStaffId"
                           value="">

                    <div class="admin-form-grid">

                        <div class="admin-form-field">
                            <label for="new_password">
                                New Password *
                            </label>

                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   minlength="12"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>
                        </div>

                        <div class="admin-form-field">
                            <label for="confirm_password_reset">
                                Confirm New Password *
                            </label>

                            <input type="password"
                                   id="confirm_password_reset"
                                   name="confirm_password"
                                   minlength="12"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>
                        </div>

                    </div>

                    <div class="admin-form-actions">

                        <button type="submit" class="admin-save-btn">
                            Reset Password
                        </button>

                        <button type="button"
                                class="admin-action-btn"
                                id="cancelPasswordReset">
                            Cancel
                        </button>

                    </div>
                </form>
            </section>

        </div>
    </main>
</div>

</body>
</html>
