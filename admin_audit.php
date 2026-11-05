<?php

require_once __DIR__ . '/../app/auth.php';

$user = requireAdmin();

header('Cache-Control: no-store');

$allowedActions = [
    'STAFF_ACCOUNT_CREATED' => 'Staff Account Created',
    'STAFF_ACCOUNT_ACTIVATED' => 'Staff Account Activated',
    'STAFF_ACCOUNT_DEACTIVATED' => 'Staff Account Deactivated',
    'STAFF_PASSWORD_RESET' => 'Staff Password Reset',
    'TICKET_CREATED' => 'Ticket Issued',
    'ISSUANCE_CORRECTED' => 'Issuance Corrected',
    'RETURN_RECORDED' => 'Return Recorded',
    'RETURN_CORRECTED' => 'Return Corrected',
    'RETURN_REVERSED' => 'Return Reversed',
    'AI_SETTINGS_CHANGED' => 'AI Settings Changed'
];

function auditInput(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

$search = auditInput('search');
$action = auditInput('action');
$dateFrom = auditInput('date_from');
$dateTo = auditInput('date_to');

$rawPage = $_GET['page'] ?? '1';

$page = is_scalar($rawPage)
    ? filter_var(
        $rawPage,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

$page = $page === false ? 1 : $page;
$pageSize = 20;
$error = '';

function auditDateValid(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date
    );

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date;
}

require_once __DIR__ . '/../app/audit.php';

$logs = [];
$totalLogs = 0;
$totalPages = 1;

if (mb_strlen($search, 'UTF-8') > 100) {
    $error = 'Search must not exceed 100 characters.';
} elseif (
    $action !== ''
    && !array_key_exists($action, $allowedActions)
) {
    $error = 'Invalid action filter.';
} elseif (
    !auditDateValid($dateFrom)
    || !auditDateValid($dateTo)
) {
    $error = 'Invalid date format.';
} elseif (
    $dateFrom !== ''
    && $dateTo !== ''
    && $dateFrom > $dateTo
) {
    $error = 'Date From cannot be later than Date To.';
} else {
    try {
        $pdo = db();
        $where = [];
        $params = [];

        if ($search !== '') {
            $escaped = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $search
            );

            $pattern = '%' . $escaped . '%';

            $where[] = "(
                actor.username LIKE :actor_username ESCAPE '!'
                OR actor.full_name LIKE :actor_name ESCAPE '!'
                OR t.ticket_number LIKE :ticket_number ESCAPE '!'
                OR JSON_UNQUOTE(
                    JSON_EXTRACT(a.old_values, '$.username')
                ) LIKE :old_username ESCAPE '!'
                OR JSON_UNQUOTE(
                    JSON_EXTRACT(a.new_values, '$.username')
                ) LIKE :new_username ESCAPE '!'
            )";

            $params['actor_username'] = $pattern;
            $params['actor_name'] = $pattern;
            $params['ticket_number'] = $pattern;
            $params['old_username'] = $pattern;
            $params['new_username'] = $pattern;
        }

        if ($action !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = $action;
        }

        if ($dateFrom !== '') {
            $where[] = 'a.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $where[] = 'a.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = $where
            ? ' WHERE ' . implode(' AND ', $where)
            : '';

        $fromSql = "
            FROM audit_logs a
            LEFT JOIN users actor
                ON actor.id = a.user_id
            LEFT JOIN trip_tickets t
                ON t.id = a.trip_ticket_id
            $whereSql
        ";

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) ' . $fromSql
        );

        $countStmt->execute($params);
        $totalLogs = (int) $countStmt->fetchColumn();

        $totalPages = max(
            1,
            (int) ceil($totalLogs / $pageSize)
        );

        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.user_id,
                a.trip_ticket_id,
                a.action,
                a.old_values,
                a.new_values,
                a.created_at,
                actor.username AS actor_username,
                actor.full_name AS actor_name,
                actor.role AS actor_role,
                t.ticket_number
            $fromSql
            ORDER BY a.id DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ':limit',
            $pageSize,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log(
            'TripLedger admin audit error: '
            . $e->getMessage()
        );

        $error = 'Unable to load activity logs.';
        $logs = [];
        $totalLogs = 0;
        $totalPages = 1;
    }
}

function auditPageUrl(int $pageNumber): string
{
    global $search, $action, $dateFrom, $dateTo;

    return 'admin_audit.php?' . http_build_query([
        'search' => $search,
        'action' => $action,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $pageNumber
    ]);
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

    <title>System Activity Logs | TripLedger</title>

    <link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="assets/admin-audit.css">

    <style>
        .audit-values {
            min-width: 190px;
            max-width: 340px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .audit-table td {
            vertical-align: top;
        }
    </style>
</head>
<body>

<div class="admin-layout">
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
                href="admin_dashboard.php"
                class="admin-nav-link"
            >
                Dashboard
            </a>

            <a
                href="admin_staff.php"
                class="admin-nav-link"
            >
                Staff Account Management
            </a>

            <a
                href="admin_audit.php"
                class="admin-nav-link active"
                aria-current="page"
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

            <form method="POST" action="logout.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <button
                    type="submit"
                    class="admin-logout"
                >
                    Logout
                </button>
            </form>
        </div>
    </aside>

    <main class="admin-content">
        <header class="admin-topbar">
            <div>
                <h1>System Activity Logs</h1>
                <p>
                    General Services and Procurement Office
                </p>
            </div>

            <div class="admin-topbar-user">
                <?= e($user['full_name']) ?>
            </div>
        </header>

        <div class="admin-body">
            <section class="admin-welcome">
                <h2>System-wide Activity Monitoring</h2>
                <p>
                    Review account management, ticket issuance,
                    corrections, recorded returns, and reversals.
                </p>
            </section>

            <?php if ($error !== ''): ?>
                <div
                    class="admin-message admin-message-error"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section class="admin-stats">
                <article class="admin-stat-card">
                    <p>Matching Activities</p>
                    <strong>
                        <?= number_format($totalLogs) ?>
                    </strong>
                </article>
            </section>

            <section class="admin-card">
                <h2>Search Activity Logs</h2>
                <p>
                    Search by actor name, username, affected
                    staff username, or ticket number.
                    Date filters apply to the activity date.
                </p>

                <form
                    method="GET"
                    action="admin_audit.php"
                    class="audit-filter-form"
                >
                    <div class="audit-filters">
                        <div>
                            <label for="search">
                                Name, Username, or Ticket
                            </label>
                            <input
                                type="text"
                                id="search"
                                name="search"
                                maxlength="100"
                                placeholder="Search activities"
                                value="<?= e($search) ?>"
                            >
                        </div>

                        <div>
                            <label for="action">Action</label>
                            <select id="action" name="action">
                                <option value="">All Actions</option>

                                <?php foreach (
                                    $allowedActions as $value => $label
                                ): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= $action === $value
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="date_from">
                                Date From
                            </label>
                            <input
                                type="date"
                                id="date_from"
                                name="date_from"
                                value="<?= e($dateFrom) ?>"
                            >
                        </div>

                        <div>
                            <label for="date_to">
                                Date To
                            </label>
                            <input
                                type="date"
                                id="date_to"
                                name="date_to"
                                value="<?= e($dateTo) ?>"
                            >
                        </div>
                    </div>

                    <div class="audit-filter-actions">
                        <button
                            type="submit"
                            class="admin-save-btn"
                        >
                            Apply Filters
                        </button>

                        <a
                            href="admin_audit.php"
                            class="audit-clear-btn"
                        >
                            Clear Filters
                        </a>
                    </div>
                </form>
            </section>

            <section class="admin-card admin-list-section">
                <h2>System Activity History</h2>

                <p>
                    <?= number_format($totalLogs) ?>
                    matching log entries
                </p>

                <?php if ($error !== ''): ?>
                    <div class="admin-empty-state">
                        Activity logs could not be loaded.
                    </div>

                <?php elseif (!$logs): ?>
                    <div class="admin-empty-state">
                        No activities match the selected filters.
                    </div>

                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table audit-table">
                            <thead>
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Performed By</th>
                                    <th>Action</th>
                                    <th>Ticket</th>
                                    <th>Before</th>
                                    <th>After</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>
                                            <?= e($log['created_at']) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= e(
                                                    $log['actor_name']
                                                    ?? 'Unavailable user'
                                                ) ?>
                                            </strong>

                                            <div class="audit-secondary">
                                                <?= e(
                                                    $log['actor_username']
                                                    ?? (
                                                        'User ID: '
                                                        . $log['user_id']
                                                    )
                                                ) ?>
                                            </div>

                                            <div class="audit-secondary">
                                                <?= e(
                                                    $log['actor_role']
                                                    ?? ''
                                                ) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="audit-action">
                                                <?= e(
                                                    $allowedActions[
                                                        $log['action']
                                                    ] ?? $log['action']
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e(
                                                $log['ticket_number']
                                                ?? (
                                                    $log['trip_ticket_id']
                                                        !== null
                                                    ? 'Ticket ID: '
                                                        . $log['trip_ticket_id']
                                                    : '—'
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <div class="audit-values"><?= e(
                                                auditSummary(
                                                    $log['old_values']
                                                )
                                            ) ?></div>
                                        </td>

                                        <td>
                                            <div class="audit-values"><?= e(
                                                auditSummary(
                                                    $log['new_values']
                                                )
                                            ) ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav
                            class="audit-pagination"
                            aria-label="Audit log pages"
                        >
                            <?php if ($page > 1): ?>
                                <a href="<?= e(
                                    auditPageUrl($page - 1)
                                ) ?>">
                                    Previous
                                </a>
                            <?php endif; ?>

                            <span>
                                Page <?= $page ?>
                                of <?= $totalPages ?>
                            </span>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= e(
                                    auditPageUrl($page + 1)
                                ) ?>">
                                    Next
                                </a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

</body>
</html>