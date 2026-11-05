
<?php
require_once __DIR__ . '/../app/auth.php';

$user = requireStaff();
$userId = (int) $user['id'];

$pageTitle = 'Change Password';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $current = $_POST['current_password'] ?? null;
    $new = $_POST['new_password'] ?? null;
    $confirm = $_POST['confirm_password'] ?? null;

    if (
        !is_string($current) ||
        !is_string($new) ||
        !is_string($confirm)
    ) {
        $error = 'Invalid password form submission.';

    } elseif (
        $current === '' ||
        $new === '' ||
        $confirm === ''
    ) {
        $error = 'All password fields are required.';

 } elseif (strlen($new) < 12 || strlen($new) > 72) {
    $error = 'Password must contain 12–72 bytes.';

} elseif (!preg_match('/[A-Z]/', $new)) {
    $error = 'Password must contain at least one uppercase letter.';

} elseif (!preg_match('/[a-z]/', $new)) {
    $error = 'Password must contain at least one lowercase letter.';

} elseif (!preg_match('/[0-9]/', $new)) {
    $error = 'Password must contain at least one number.';

} elseif (!preg_match('/[^A-Za-z0-9]/', $new)) {
    $error = 'Password must contain at least one special character.';
    } elseif (!hash_equals($new, $confirm)) {
        $error = 'New password and confirmation do not match.';

    } else {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT password_hash, session_version
                 FROM users
                 WHERE id = ?
                   AND role = ?
                   AND is_active = 1
                 FOR UPDATE"
            );

            $stmt->execute([$userId, ROLE_STAFF]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$account ||
                (int) $account['session_version']
                    !== (int) $_SESSION['session_version']
            ) {
                throw new RuntimeException(
                    'Your session is no longer valid.'
                );
            }

            if (!password_verify(
                $current,
                $account['password_hash']
            )) {
                $error = 'Current password is incorrect.';

            } elseif (password_verify(
                $new,
                $account['password_hash']
            )) {
                $error =
                    'New password must be different ' .
                    'from your current password.';

            } else {
                $hash = password_hash(
                    $new,
                    PASSWORD_DEFAULT
                );

                $update = $pdo->prepare(
                    "UPDATE users
                     SET password_hash = ?,
                         session_version = session_version + 1
                     WHERE id = ?
                       AND role = ?
                       AND is_active = 1"
                );

                $update->execute([
                    $hash,
                    $userId,
                    ROLE_STAFF
                ]);

                $audit = $pdo->prepare(
                    "INSERT INTO audit_logs
                     (user_id, trip_ticket_id, action,
                      old_values, new_values)
                     VALUES (?, NULL, ?, NULL, ?)"
                );

                $audit->execute([
                    $userId,
                    'STAFF_PASSWORD_CHANGED',
                    json_encode([
                        'event' => 'Self-service password change'
                    ], JSON_THROW_ON_ERROR)
                ]);

                $pdo->commit();

                // Remove authenticated session values.
                $_SESSION = [];
                session_regenerate_id(true);

                // Redirect with non-sensitive success notice.
                header(
                    'Location: index.php?password_changed=1',
                    true,
                    303
                );
                exit;
            }

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'TripLedger password change failed: ' .
                $e->getMessage()
            );

            $error = 'Unable to change password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Change Password | TripLedger</title>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
    <script src="assets/app.js" defer></script>

    <style>
        .password-card {
            max-width: 640px;
        }
        .password-field {
            margin-top: 20px;
        }
        .password-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #334155;
        }
        .password-field input {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
        }
        .password-field input:focus {
            outline: 2px solid #b6dfc7;
            border-color: #155e45;
        }
        .password-hint {
            margin-top: 7px;
            font-size: 13px;
            color: #64748b;
        }
        .password-error {
            margin-top: 7px;
            color: #b91c1c;
            font-size: 13px;
        }
        .password-match {
            margin-top: 7px;
            color: #166534;
            font-size: 13px;
        }
        .password-alert {
            padding: 14px;
            border-radius: 8px;
            background: #fef2f2;
            color: #991b1b;
            margin-top: 18px;
        }
        .password-actions {
            margin-top: 26px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .password-save {
            background: #155e45;
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 13px 20px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .password-save:disabled {
            opacity: .55;
            cursor: not-allowed;
        }
        .password-cancel {
            color: #155e45;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="app-layout">

    <?php require __DIR__ . '/../app/partials/sidebar.php'; ?>

    <div class="tl-content">

        <?php require __DIR__ . '/../app/partials/header.php'; ?>

        <main class="tl-dashboard-body">

            <section class="tl-welcome">
                <h2>Change Password</h2>
                <p>
                    Update your account password securely.
                    You will need to sign in again afterward.
                </p>
            </section>

            <section class="app-card password-card">
                <h2>Account Security</h2>

                <?php if ($error !== ''): ?>
                    <div class="password-alert" role="alert">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST"
                      id="changePasswordForm"
                      action="change_password.php">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= e(csrfToken()) ?>">

                    <div class="password-field">
                        <label for="current_password">
                            Current Password *
                        </label>
                        <input type="password"
                               id="current_password"
                               name="current_password"
                               autocomplete="current-password"
                               required>
                    </div>

                    <div class="password-field">
                        <label for="new_password">
                            New Password *
                        </label>
                        <input type="password"
                               id="new_password"
                               name="new_password"
                               autocomplete="new-password"
                                minlength="12"
           maxlength="72"
                               required
                               aria-describedby="passwordHint">
                        <p class="password-hint"
                           id="passwordHint">
                           Password must contain at least 12 characters,
    including uppercase and lowercase letters,
    a number, and a special character.
                        </p>
                    </div>

                    <div class="password-field">
                        <label for="confirm_password">
                            Confirm New Password *
                        </label>
                        <input type="password"
                               id="confirm_password"
                               name="confirm_password"
                               autocomplete="new-password"
                               aria-describedby="matchMessage"
                               required>
                        <p id="matchMessage"
                           aria-live="polite"></p>
                    </div>

                    <div class="password-field">
                        <label>
                            <input type="checkbox"
                                   id="showPasswords"
                                   style="width:auto">
                            Show passwords
                        </label>
                    </div>

                    <div class="password-actions">
                        <button type="submit"
                                class="password-save"
                                id="savePassword">
                            Change Password
                        </button>

                        <a href="my_profile.php"
                           class="password-cancel">
                            Cancel
                        </a>
                    </div>

                </form>
            </section>

        </main>
    </div>
</div>

<script>
const passwordForm =
    document.getElementById('changePasswordForm');

const newPassword =
    document.getElementById('new_password');

const confirmPassword =
    document.getElementById('confirm_password');

const matchMessage =
    document.getElementById('matchMessage');

const saveButton =
    document.getElementById('savePassword');

function validateMatch() {
    if (confirmPassword.value === '') {
        matchMessage.textContent = '';
        matchMessage.className = '';
        confirmPassword.setCustomValidity('');
        return;
    }

    if (newPassword.value !== confirmPassword.value) {
        matchMessage.textContent =
            'New password and confirmation do not match.';
        matchMessage.className = 'password-error';
        confirmPassword.setCustomValidity(
            'Passwords do not match.'
        );
    } else {
        matchMessage.textContent = 'Passwords match.';
        matchMessage.className = 'password-match';
        confirmPassword.setCustomValidity('');
    }
}

newPassword.addEventListener('input', validateMatch);
confirmPassword.addEventListener('input', validateMatch);

document.getElementById('showPasswords')
    .addEventListener('change', function () {
        const type = this.checked ? 'text' : 'password';

        document.querySelectorAll(
            '#changePasswordForm input[type="password"], ' +
            '#changePasswordForm input[type="text"]'
        ).forEach(input => {
            input.type = type;
        });
    });

passwordForm.addEventListener('submit', function (event) {
    validateMatch();

    if (!passwordForm.checkValidity()) {
        event.preventDefault();
        passwordForm.reportValidity();
        return;
    }

    if (!window.confirm(
        'Change your password? You will be signed out.'
    )) {
        event.preventDefault();
        return;
    }

    saveButton.disabled = true;
});
</script>
</body>
</html>
