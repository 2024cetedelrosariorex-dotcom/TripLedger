<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/ai_settings.php';
$user = requireAdmin();
$error = '';
$success = $_SESSION['ai_success'] ?? '';
unset($_SESSION['ai_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        foreach (['enabled', 'explanations_enabled', 'analysis_enabled'] as $key) {
            if (isset($_POST[$key]) && $_POST[$key] !== '1') throw new InvalidArgumentException('Invalid setting.');
        }
        saveAiSettings((int) $user['id'], $_POST);
        $_SESSION['ai_success'] = 'AI settings saved. Changes apply to subsequent requests.';
        header('Location: admin_ai.php', true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Unable to save AI settings. Ask the server operator to check migrations and configuration.';
    }
}
$settings = aiSettings();
$models = aiAllowedModels();
$keyPath = tripledgerConfig()['gemini']['key_file'] ?? '';
$keyReady = $keyPath !== '' && is_readable($keyPath) && filesize($keyPath) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Settings | TripLedger</title><link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="admin-ai-page"><div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="admin-brand"><img src="assets/dar-logo.png.webp" alt="DAR Logo"><strong>TripLedger</strong></div>
        <nav aria-label="Administrator navigation">
            <a class="admin-nav-link" href="admin_dashboard.php">Dashboard</a>
            <a class="admin-nav-link" href="admin_staff.php">Staff Account Management</a>
            <a class="admin-nav-link" href="admin_audit.php">Activity Logs</a>
            <a class="admin-nav-link active" aria-current="page" href="admin_ai.php">AI Settings</a>
        </nav>
        <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button class="admin-logout" type="submit">Logout</button></form>
    </aside>
    <main class="admin-content"><div class="admin-body">
        <h1>AI Settings</h1>
        <p>Trippy's local record lookup always remains available. Gemini provides optional explanations and aggregate reconciliation analysis.</p>
        <?php if ($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
        <?php if ($success): ?><p role="status"><?= e($success) ?></p><?php endif; ?>
        <section class="admin-card">
            <p>Server key file: <?= $keyReady ? 'Configured (connection not tested)' : 'Missing or unreadable' ?>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <?php foreach (['enabled' => 'Enable Gemini features', 'explanations_enabled' => 'Allow fixed-prompt explanations', 'analysis_enabled' => 'Allow reconciliation analysis using aggregate counts'] as $key => $label): ?>
                    <p><label><input type="checkbox" name="<?= e($key) ?>" value="1" <?= (int) $settings[$key] ? 'checked' : '' ?>> <?= e($label) ?></label></p>
                <?php endforeach; ?>
                <p><label for="model">Permitted Gemini model</label>
                <select id="model" name="model"><option value="">Not configured</option>
                    <?php foreach ($models as $model): ?><option value="<?= e($model) ?>" <?= $settings['model'] === $model ? 'selected' : '' ?>><?= e($model) ?></option><?php endforeach; ?>
                </select></p>
                <p>Enabling analysis permits sending counts and age groups only. No ticket details, staff messages, plates, or driver names are sent. Changes are audited.</p>
                <button class="admin-save-btn" type="submit">Save Settings</button>
            </form>
        </section>
        <p>Gemini needs outbound internet from the server. TripLedger stays on the office LAN. Credentials and the permitted model list are managed in private server configuration.</p>
    </div></main>
</div></body></html>
