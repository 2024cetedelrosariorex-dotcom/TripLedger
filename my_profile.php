
<?php
require_once __DIR__ . '/../app/auth.php';

$user = requireStaff();
$userId = (int) $user['id'];
$pageTitle = 'My Profile';

$error = '';
$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);

$stmt = db()->prepare(
    "SELECT id, username, full_name, role,
            is_active, created_at
     FROM users
     WHERE id = ? AND role = ?
     LIMIT 1"
);
$stmt->execute([$userId, ROLE_STAFF]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile || (int) $profile['is_active'] !== 1) {
    http_response_code(403);
    exit('Account unavailable.');
}

$values = [
    'full_name' => $profile['full_name'],
    'username' => $profile['username']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $nameInput = $_POST['full_name'] ?? null;
    $usernameInput = $_POST['username'] ?? null;

    if (!is_string($nameInput) || !is_string($usernameInput)) {
        $error = 'Invalid profile information.';
    } else {
        $values['full_name'] = trim(
            preg_replace('/\s+/u', ' ', $nameInput) ?? ''
        );
        $values['username'] = trim($usernameInput);

        $fullName = $values['full_name'];
        $username = $values['username'];

        if (
            $fullName === '' ||
            strlen($fullName) > 150 ||
            !preg_match(
                "/^(?=.*\p{L})[\p{L}\p{M} .'-]+$/u",
                $fullName
            )
        ) {
            $error = 'Enter a valid full name (maximum 150 bytes).';
        } elseif (!preg_match(
            '/^[A-Za-z0-9_]{3,50}$/',
            $username
        )) {
            $error = 'Username must contain 3–50 letters, numbers, or underscores.';
        } else {
            $pdo = db();

            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare(
                    "SELECT id, username, full_name
                     FROM users
                     WHERE id = ?
                       AND role = ?
                       AND is_active = 1
                     FOR UPDATE"
                );
                $lock->execute([$userId, ROLE_STAFF]);
                $current = $lock->fetch(PDO::FETCH_ASSOC);

                if (!$current) {
                    throw new RuntimeException(
                        'Account is no longer available.'
                    );
                }

                if (
                    $current['full_name'] === $fullName &&
                    $current['username'] === $username
                ) {
                    $pdo->rollBack();
                    $error = 'No changes detected.';
                } else {
                    $check = $pdo->prepare(
                        "SELECT id
                         FROM users
                         WHERE username = ?
                           AND id <> ?
                         LIMIT 1"
                    );
                    $check->execute([$username, $userId]);

                    if ($check->fetchColumn() !== false) {
                        $pdo->rollBack();
                        $error = 'Username is already taken.';
                    } else {
                        $update = $pdo->prepare(
                            "UPDATE users
                             SET full_name = ?, username = ?
                             WHERE id = ?
                               AND role = ?
                               AND is_active = 1"
                        );
                        $update->execute([
                            $fullName,
                            $username,
                            $userId,
                            ROLE_STAFF
                        ]);

                        $oldValues = [
                            'full_name' => $current['full_name'],
                            'username' => $current['username']
                        ];

                        $newValues = [
                            'full_name' => $fullName,
                            'username' => $username
                        ];

                        $audit = $pdo->prepare(
                            "INSERT INTO audit_logs
                             (user_id, trip_ticket_id, action,
                              old_values, new_values)
                             VALUES (?, NULL, ?, ?, ?)"
                        );
                        $audit->execute([
                            $userId,
                            'STAFF_PROFILE_UPDATED',
                            json_encode(
                                $oldValues,
                                JSON_THROW_ON_ERROR
                            ),
                            json_encode(
                                $newValues,
                                JSON_THROW_ON_ERROR
                            )
                        ]);

                        $pdo->commit();

                        $_SESSION['username'] = $username;
                        $_SESSION['profile_success'] =
                            'Profile updated successfully.';

                        header('Location: my_profile.php', true, 303);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if (
                    $e->getCode() === '23000' &&
                    (int) ($e->errorInfo[1] ?? 0) === 1062
                ) {
                    $error = 'Username is already taken.';
                } else {
                    error_log(
                        'Profile update error: ' . $e->getMessage()
                    );
                    $error = 'Unable to update your profile.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Profile update error: ' . $e->getMessage()
                );
                $error = 'Unable to update your profile.';
            }
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

    <title>My Profile | TripLedger</title>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
    <script src="assets/app.js" defer></script>

    <style>
        .profile-form {
            max-width: 850px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 22px;
        }

        .profile-field {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .profile-field label {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
        }

        .profile-field input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: white;
            color: #1e293b;
            font: inherit;
        }

        .profile-field input:focus {
            outline: 2px solid #b6dfc7;
            border-color: #155e45;
        }

        .profile-readonly {
            padding: 13px 14px;
            background: #f8faf9;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            color: #155e45;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .profile-actions {
            margin-top: 28px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .profile-save {
            padding: 13px 22px;
            background: #155e45;
            border: 0;
            color: white;
            border-radius: 9px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .profile-save:hover {
            background: #104d39;
        }

        .profile-notice {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 9px;
            line-height: 1.5;
        }

        .profile-success {
            background: #dcfce7;
            color: #166534;
        }

        .profile-error {
            background: #fef2f2;
            color: #991b1b;
        }

        .profile-hint {
            margin-top: 8px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 650px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
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
                <h2>My Profile</h2>
                <p>
                    View and update your personal
                    account information.
                </p>
            </section>

            <section class="app-card profile-form">

                <h2>Personal Information</h2>

                <p class="profile-hint">
                    Only your full name and username
                    can be edited here.
                </p>

                <?php if ($success !== ''): ?>
                    <div class="profile-notice profile-success"
                         role="status">
                        <?= e($success) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="profile-notice profile-error"
                         role="alert">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST"
                      action="my_profile.php">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= e(csrfToken()) ?>">

                    <div class="profile-grid">

                        <div class="profile-field">
                            <label for="full_name">
                                Full Name *
                            </label>

                            <input type="text"
                                   id="full_name"
                                   name="full_name"
                                   maxlength="150"
                                   value="<?= e($values['full_name']) ?>"
                                   autocomplete="name"
                                   required>
                        </div>

                        <div class="profile-field">
                            <label for="username">
                                Username *
                            </label>

                            <input type="text"
                                   id="username"
                                   name="username"
                                   minlength="3"
                                   maxlength="50"
                                   pattern="[A-Za-z0-9_]{3,50}"
                                   title="Use letters, numbers, or underscores."
                                   value="<?= e($values['username']) ?>"
                                   autocomplete="username"
                                   required>
                        </div>

                        <div class="profile-field">
                            <label>Account Role</label>
                            <div class="profile-readonly">
                                <?= e($profile['role']) ?>
                            </div>
                        </div>

                        <div class="profile-field">
                            <label>Account Status</label>
                            <div class="profile-readonly">
                                <?= (int) $profile['is_active'] === 1
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </div>
                        </div>

                        <div class="profile-field">
                            <label>Account Created</label>
                            <div class="profile-readonly">
                                <?= e($profile['created_at']) ?>
                            </div>
                        </div>

                    </div>

                    <div class="profile-actions">
                        <button type="submit"
                                class="profile-save">
                            Save Changes
                        </button>

                        <a href="change_password.php"
   class="profile-save"
   style="text-decoration:none; display:inline-block;">
    Change Password
</a>
                    </div>

                </form>
            </section>

        </main>
    </div>
</div>
</body>
</html>
