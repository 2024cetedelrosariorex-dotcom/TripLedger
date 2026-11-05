
<?php
require_once __DIR__ . '/../app/auth.php';

if (isLoggedIn()) {
    $loggedInUser = currentUser();

    if ($loggedInUser) {
        if ($loggedInUser['role'] === ROLE_ADMIN) {
            header('Location: admin_dashboard.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
    }
}

$error = '';
$username = '';
$passwordChanged =
    ($_GET['password_changed'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $rawUsername = $_POST['username'] ?? '';
    $rawPassword = $_POST['password'] ?? '';

    if (!is_string($rawUsername) || !is_string($rawPassword)) {
        $error = 'Invalid login request.';
    } else {
        $username = trim($rawUsername);
        $password = $rawPassword;

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            $stmt = db()->prepare(
                "SELECT
                    id,
                    username,
                    full_name,
                    role,
                    password_hash,
                    is_active,
                     archived_at,
                    session_version
                 FROM users
                 WHERE username = ?
                 LIMIT 1"
            );

            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check password before revealing account status.
            if (
                $user &&
                password_verify($password, $user['password_hash'])
            ) {
              
if (
    (int) $user['is_active'] !== 1 ||
    $user['archived_at'] !== null
) {
    $error =
        'Your account has been deactivated or archived. ' .
        'Please contact your administrator.';

                } elseif (!in_array(
                    $user['role'],
                    [ROLE_ADMIN, ROLE_STAFF],
                    true
                )) {
                    $error = 'Invalid username or password.';

                } else {
                    // Successful login.
                    session_regenerate_id(true);

                    $_SESSION = [];

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['session_version'] =
                        (int) $user['session_version'];
                    $_SESSION['last_activity'] = time();

                    if ($user['role'] === ROLE_ADMIN) {
                        header('Location: admin_dashboard.php');
                        exit;
                    }

                    if ($user['role'] === ROLE_STAFF) {
                        // Welcome popup after successful staff login.
                        $_SESSION['trippy_auto_open'] = true;

                        header('Location: dashboard.php');
                        exit;
                    }
                }
            } else {
                // Do not reveal whether an account exists.
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Staff Login | TripLedger</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/login.css">

    <script src="assets/login.js" defer></script>
</head>

<body class="login-page">

<main class="tl-login-shell">

    <div class="tl-login-layout">

        <!-- LEFT: SYSTEM BRANDING -->
        <section
            class="tl-login-brand"
            aria-labelledby="system-title"
        >

            <div class="tl-login-agency">
                <img
                    src="assets/dar-logo.png.webp"
                    alt="Department of Agrarian Reform logo"
                    class="tl-login-logo"
                >

                <div class="tl-agency-text">
                    <strong>
                        Department of Agrarian Reform
                    </strong>

                    <span>
                        Sarangani
                    </span>

                    <small>
                        General Services and Procurement Office
                    </small>
                </div>
            </div>

            <div class="tl-brand-content">

                <div class="tl-brand-line"></div>

                <h1 id="system-title">
                    Trip<span>Ledger</span>
                </h1>

                <p class="tl-brand-subtitle">
                    Trip Ticket Records Reconciliation
                    and Retrieval System with
                    Automated Annual Numbering
                </p>

                <p class="tl-brand-description">
                    A centralized system for recording,
                    retrieving, and reconciling trip
                    ticket transactions.
                </p>

                <div class="tl-access-note">
                    <span
                        class="tl-access-icon"
                        aria-hidden="true"
                    >&#10003;</span>

                    <span>
                        Authorized Staff Access Only
                    </span>
                </div>

            </div>

            <p class="tl-brand-footnote">
                Department of Agrarian Reform –
                Sarangani
            </p>

        </section>

        <!-- RIGHT: LOGIN FORM -->
        <section
            class="tl-login-panel"
            aria-labelledby="login-title"
        >

            <div class="tl-login-card">

                <div class="tl-login-card-heading">

                    <div
                        class="tl-card-icon"
                        aria-hidden="true"
                    ></div>

                    <h2 id="login-title">
                        Welcome Back
                    </h2>

                    <p>
                        Sign in to your authorized
                        TripLedger staff account.
                    </p>
                </div>

                <?php if ($error !== ''): ?>
                    <div
                        class="tl-login-error"
                        role="alert"
                    >
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($passwordChanged): ?>
    <div
        role="status"
        style="padding:14px; margin-bottom:16px;
               color:#166534; background:#dcfce7;
               border-radius:8px;">
        Password changed successfully.
        Please log in again.
    </div>
<?php endif; ?>

                <form
                    method="POST"
                    action="index.php"
                    id="tripLedgerLoginForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <div class="tl-login-field">

                        <label for="username">
                            Username
                        </label>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            maxlength="50"
                            autocomplete="username"
                            placeholder="Enter your username"
                            value="<?= e($username) ?>"
                            required
                            autofocus
                        >
                    </div>

                    <div class="tl-login-field">

                        <label for="password">
                            Password
                        </label>

                        <div class="tl-password-wrap">

                            <input
                                type="password"
                                id="password"
                                name="password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                required
                            >

                            <button
                                type="button"
                                id="togglePassword"
                                class="tl-password-toggle"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                Show
                            </button>
                        </div>
                    </div>

                    <button
                        type="submit"
                        id="loginSubmit"
                        class="tl-login-submit"
                    >
                        <span id="loginButtonText">
                            Sign In
                        </span>

                        <span aria-hidden="true">
                            &rarr;
                        </span>
                    </button>

                </form>

                <div class="tl-login-card-footer">
                    Restricted to authorized DAR personnel
                </div>

            </div>

        </section>

    </div>

    <footer class="tl-login-footer">
        <span>
            TripLedger Local Prototype
        </span>

        <span>
            &copy; <?= date('Y') ?>
            Department of Agrarian Reform – Sarangani
        </span>
    </footer>

</main>

</body>
</html>
