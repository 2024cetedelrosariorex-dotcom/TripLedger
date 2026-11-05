<?php
require_once __DIR__ . '/../app/auth.php';

// ==========================================
// ADMINISTRATOR-ONLY ACCESS
// ==========================================

$user = requireAdmin();
$pdo = db();

// ==========================================
// STAFF ACCOUNT STATISTICS
// ==========================================

$stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_staff,
        COALESCE(
            SUM(CASE WHEN is_active = 1
                THEN 1 ELSE 0 END), 0
        ) AS active_staff,
        COALESCE(
            SUM(CASE WHEN is_active = 0
                THEN 1 ELSE 0 END), 0
        ) AS inactive_staff
     FROM users
     WHERE role = ?"
);

$stmt->execute([ROLE_STAFF]);

$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalStaff = (int) ($stats['total_staff'] ?? 0);
$activeStaff = (int) ($stats['active_staff'] ?? 0);
$inactiveStaff = (int) ($stats['inactive_staff'] ?? 0);

// ==========================================
// SYSTEM-WIDE AUDIT COUNT
// ==========================================

$totalAuditLogs = (int) $pdo
    ->query("SELECT COUNT(*) FROM audit_logs")
    ->fetchColumn();

// ==========================================
// ADMINISTRATIVE ACTIVITY SUMMARY
// ==========================================

$adminActions = [
    'STAFF_ACCOUNT_CREATED' => 'Staff Account Created',
    'STAFF_ACCOUNT_ACTIVATED' => 'Staff Account Activated',
    'STAFF_ACCOUNT_DEACTIVATED' => 'Staff Account Deactivated',
    'STAFF_PASSWORD_RESET' => 'Staff Password Reset'
];

$recentActivities = [];
$totalAdminActivities = 0;
$activityError = '';

try {
    $totalAdminActivities = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM audit_logs
             WHERE trip_ticket_id IS NULL
               AND action IN (
                   'STAFF_ACCOUNT_CREATED',
                   'STAFF_ACCOUNT_ACTIVATED',
                   'STAFF_ACCOUNT_DEACTIVATED',
                   'STAFF_PASSWORD_RESET'
               )"
        )
        ->fetchColumn();

    $activityStmt = $pdo->prepare(
        "SELECT
            a.id,
            a.action,
            a.created_at,
            a.old_values,
            a.new_values,
            u.username AS admin_username,
            u.full_name AS admin_name
         FROM audit_logs a
         INNER JOIN users u
             ON u.id = a.user_id
         WHERE a.trip_ticket_id IS NULL
           AND a.action IN (
               'STAFF_ACCOUNT_CREATED',
               'STAFF_ACCOUNT_ACTIVATED',
               'STAFF_ACCOUNT_DEACTIVATED',
               'STAFF_PASSWORD_RESET'
           )
         ORDER BY a.id DESC
         LIMIT 5"
    );

    $activityStmt->execute();

    $recentActivities = $activityStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

} catch (Throwable $exception) {
    error_log(
        'Admin dashboard activity error: ' .
        $exception->getMessage()
    );

    $activityError =
        'Recent administrative activities could not be loaded.';
}

// ==========================================
// SAFE STAFF LABEL
// ==========================================

function dashboardStaffLabel(array $log): string
{
    foreach (['new_values', 'old_values'] as $field) {
        $raw = $log[$field] ?? null;

        if (!is_string($raw) || $raw === '') {
            continue;
        }

        $values = json_decode($raw, true);

        if (
            is_array($values) &&
            isset($values['username']) &&
            is_string($values['username'])
        ) {
            return $values['username'];
        }
    }

    return 'Staff account';
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

    <title>Administrator Dashboard | TripLedger</title>

    <link rel="stylesheet" href="assets/admin.css">

    <style>
        /* Dashboard-specific additions */

        .admin-feature-link {
            color: inherit;
            text-decoration: none;
            display: block;
            height: 100%;
        }

        .admin-feature-link:hover {
            text-decoration: none;
        }

        .admin-feature-clickable {
            transition:
                transform 0.18s ease,
                box-shadow 0.18s ease,
                border-color 0.18s ease;
        }

        .admin-feature-clickable:hover {
            transform: translateY(-2px);
            border-color: #9acbb6;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.06);
        }

        .admin-feature-link:focus-visible {
            outline: 3px solid #16a34a;
            outline-offset: 4px;
            border-radius: 8px;
        }

        .dashboard-status {
            display: inline-block;
            width: fit-content;
            margin-top: 12px;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.4;
        }

        .dashboard-status.implemented {
            background: #dcfce7;
            color: #166534;
        }

        .dashboard-status.planned {
            background: #fef3c7;
            color: #92400e;
        }

        .dashboard-section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .dashboard-section-heading h2 {
            margin: 0;
        }

        .dashboard-section-heading p {
            margin: 6px 0 0;
        }

        .dashboard-text-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #166534;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .dashboard-text-link:hover {
            text-decoration: underline;
        }

        .dashboard-activity-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .dashboard-activity-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 620px;
        }

        .dashboard-activity-table th,
        .dashboard-activity-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            font-size: 13px;
        }

        .dashboard-activity-table th {
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            background: #f8fafc;
        }

        .dashboard-activity-table td {
            color: #334155;
        }

        .dashboard-activity-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .dashboard-activity-table td strong {
            display: block;
            color: #0f172a;
        }

        .dashboard-activity-table td small {
            display: block;
            margin-top: 3px;
            color: #64748b;
        }

        .dashboard-action-label {
            display: inline-block;
            padding: 6px 10px;
            background: #e7f5ed;
            color: #166534;
            border-radius: 18px;
            font-size: 12px;
            font-weight: 600;
        }

        .dashboard-empty {
            padding: 24px 12px;
            color: #64748b;
            font-size: 13px;
            text-align: center;
        }

        .dashboard-subsection {
            margin-top: 24px;
        }

        .dashboard-note {
            margin-top: 16px;
            color: #64748b;
            font-size: 12px;
        }

        @media (max-width: 640px) {
            .dashboard-section-heading {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <!-- ======================================
         ADMINISTRATOR SIDEBAR
         ====================================== -->

    <aside class="admin-sidebar">

        <div class="admin-brand">

            <img
                src="assets/dar-logo.png.webp"
                alt="DAR Logo"
            >

            <div>
                <strong>TripLedger</strong>
                <small>Administration</small>
            </div>

        </div>

        <div class="admin-menu-label">
            ADMINISTRATION
        </div>

        <nav aria-label="Administrator navigation">

            <a
                class="admin-nav-link active"
                href="admin_dashboard.php"
                aria-current="page"
            >
                Dashboard
            </a>

            <a
                class="admin-nav-link"
                href="admin_staff.php"
            >
                Staff Account Management
            </a>

            <a
                class="admin-nav-link"
                href="admin_audit.php"
            >
                Activity Logs
            </a>

            <a class="admin-nav-link" href="admin_ai.php">AI Settings</a>

        </nav>

        <div class="admin-sidebar-bottom">

            <div class="admin-profile">

                <div class="admin-avatar">
                    <?= e(strtoupper(
                        substr($user['full_name'], 0, 1)
                    )) ?>
                </div>

                <div>
                    <strong>
                        <?= e($user['full_name']) ?>
                    </strong>

                    <small>Administrator</small>
                </div>

            </div>

            <form
                method="POST"
                action="logout.php"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <button
                    class="admin-logout"
                    type="submit"
                >
                    Logout
                </button>

            </form>

        </div>

    </aside>

    <!-- ======================================
         MAIN CONTENT
         ====================================== -->

    <main class="admin-content">

        <header class="admin-topbar">

            <div>
                <h1>Administrator Dashboard</h1>

                <p>
                    General Services and Procurement Office
                </p>
            </div>

            <div class="admin-topbar-user">
                <?= e($user['full_name']) ?>
            </div>

        </header>

        <div class="admin-body">

            <!-- WELCOME -->

            <section class="admin-welcome">

                <h2>
                    Welcome,
                    <?= e($user['full_name']) ?>!
                </h2>

                <p>
                    Manage TripLedger user access,
                    review administrative activities,
                    and oversee system configuration.
                </p>

            </section>

            <!-- ==================================
                 ACCOUNT STATISTICS
                 ================================== -->

            <section
                class="admin-stats"
                aria-label="System statistics"
            >

                <article class="admin-stat-card">

                    <p>Total Staff Accounts</p>

                    <strong>
                        <?= number_format($totalStaff) ?>
                    </strong>

                </article>

                <article class="admin-stat-card">

                    <p>Active Staff</p>

                    <strong>
                        <?= number_format($activeStaff) ?>
                    </strong>

                </article>

                <article class="admin-stat-card">

                    <p>Inactive Staff</p>

                    <strong>
                        <?= number_format($inactiveStaff) ?>
                    </strong>

                </article>

                <article class="admin-stat-card">

                    <p>Total Audit Entries</p>

                    <strong>
                        <?= number_format($totalAuditLogs) ?>
                    </strong>

                </article>

            </section>

            <!-- ==================================
                 SYSTEM ADMINISTRATION
                 ================================== -->

            <section class="admin-card">

                <h2>System Administration</h2>

                <p>
                    TripLedger is designed for one
                    Administrator and three intended
                    Authorized Staff users.
                </p>

                <div class="admin-feature-grid">

                    <!-- STAFF ACCOUNT MANAGEMENT -->

                    <div
                        class="admin-feature admin-feature-clickable"
                    >

                        <a
                            class="admin-feature-link"
                            href="admin_staff.php"
                            aria-label="Open Staff Account Management"
                        >

                            <h3>Staff Account Management</h3>

                            <p>
                                Create, activate, deactivate,
                                and manage Authorized Staff
                                accounts and passwords.
                            </p>

                            <span
                                class="dashboard-status implemented"
                            >
                                Manage User Accounts
                            </span>

                        </a>

                    </div>

                    <!-- ACTIVITY LOG MONITORING -->

                    <div
                        class="admin-feature admin-feature-clickable"
                    >

                        <a
                            class="admin-feature-link"
                            href="admin_audit.php"
                            aria-label="Open Administrative Activity Logs"
                        >

                            <h3>Administrative Activity Logs</h3>

                            <p>
                                Review staff account creation,
                                activation, deactivation, and
                                password reset activities.
                            </p>

                            <span
                                class="dashboard-status implemented"
                            >
                                Activity Logs
                            </span>

                        </a>

                    </div>

                    <!-- AI FEATURE MANAGEMENT -->

                    <div class="admin-feature">

                        <h3>AI Feature Management</h3>

                        <p>
                            Enable Gemini explanations and aggregate
                            reconciliation analysis. Local Trippy lookup
                            remains available independently.
                        </p>

                        <a class="dashboard-status implemented" href="admin_ai.php">Manage AI Settings</a>

                    </div>

                </div>

            </section>

            <!-- ==================================
                 RECENT ADMINISTRATIVE ACTIVITY
                 ================================== -->

            <section class="admin-card dashboard-subsection">

                <div class="dashboard-section-heading">

                    <div>
                        <h2>Recent Administrative Activities</h2>

                        <p>
                            Latest staff account changes
                            recorded in TripLedger.
                        </p>
                    </div>

                    <a
                        class="dashboard-text-link"
                        href="admin_audit.php"
                    >
                        View All Activities &rarr;
                    </a>

                </div>

                <?php if ($activityError !== ''): ?>

                    <div class="dashboard-empty" role="alert">
                        <?= e($activityError) ?>
                    </div>

                <?php elseif (!$recentActivities): ?>

                    <div class="dashboard-empty">
                        No administrative activities
                        have been recorded yet.
                    </div>

                <?php else: ?>

                    <div class="dashboard-activity-wrap">

                        <table class="dashboard-activity-table">

                            <thead>

                                <tr>
                                    <th>Date / Time</th>
                                    <th>Administrator</th>
                                    <th>Activity</th>
                                    <th>Affected Staff</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $recentActivities as $activity
                                ): ?>

                                    <tr>

                                        <td>
                                            <?= e(
                                                $activity['created_at']
                                            ) ?>
                                        </td>

                                        <td>

                                            <strong>
                                                <?= e(
                                                    $activity['admin_name']
                                                ) ?>
                                            </strong>

                                            <small>
                                                <?= e(
                                                    $activity['admin_username']
                                                ) ?>
                                            </small>

                                        </td>

                                        <td>

                                            <span
                                                class="dashboard-action-label"
                                            >
                                                <?= e(
                                                    $adminActions[
                                                        $activity['action']
                                                    ] ?? $activity['action']
                                                ) ?>
                                            </span>

                                        </td>

                                        <td>
                                            <?= e(
                                                dashboardStaffLabel(
                                                    $activity
                                                )
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

                <p class="dashboard-note">
                    Administrative activities:
                    <?= number_format($totalAdminActivities) ?>.
                    Total Audit Entries above also includes
                    trip ticket operational audit records.
                </p>

            </section>

        </div>

    </main>

</div>

</body>
</html>
