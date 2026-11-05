<?php
// Shared sidebar for authenticated TripLedger pages.
// Parent page must call requireLogin() first.

$activePage = basename($_SERVER['SCRIPT_NAME'] ?? '');

$navigation = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => '▦'],
    ['file' => 'records.php', 'label' => 'Trip Ticket Records', 'icon' => '▤'],
    ['file' => 'issuance.php', 'label' => 'New Issuance', 'icon' => '+'],
    ['file' => 'ai_assistant.php', 'label' => 'Trippy Assistant', 'icon' => '?'],
    ['file' => 'outstanding.php', 'label' => 'Outstanding Report', 'icon' => '▥'],
    ['file' => 'my_profile.php', 'label' => 'My Profile', 'icon' => '●']
];

$recordPages = [
    'records.php',
    'ticket_details.php',
    'correct_ticket.php'
];
?>

<aside class="tl-sidebar" data-sidebar>
    <div class="tl-sidebar-brand">
        
<img
    src="assets/dar-logo.png.webp"
    alt="DAR Logo"
    class="tl-sidebar-logo"
>


        <div>
            <strong>TripLedger</strong>
            <small>DAR–Sarangani</small>
        </div>
    </div>

    <div class="tl-sidebar-caption">MAIN MENU</div>

    <nav class="tl-sidebar-nav" aria-label="Main navigation">
        <?php foreach ($navigation as $item): ?>
            <?php
                $isActive = $activePage === $item['file'];

                if ($item['file'] === 'records.php') {
                    $isActive = in_array(
                        $activePage,
                        $recordPages,
                        true
                    );
                }
            ?>

            <a
                href="<?= e($item['file']) ?>"
                class="tl-nav-link <?= $isActive ? 'active' : '' ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            >
                <span class="tl-nav-icon" aria-hidden="true">
                    <?= e($item['icon']) ?>
                </span>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="tl-sidebar-bottom">
        <div class="tl-sidebar-profile">
            <span class="tl-avatar" aria-hidden="true">
                <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
            </span>
            <div>
                <strong><?= e($user['full_name']) ?></strong>
                <small>Authorized Staff</small>
            </div>
        </div>

        <form method="POST" action="logout.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrfToken()) ?>"
            >
            <button type="submit" class="tl-logout-btn">
                Logout
            </button>
        </form>
    </div>
</aside>
