<?php
$pageTitle = $pageTitle ?? 'Dashboard';
?>

<header class="tl-topbar">
    <div class="tl-topbar-left">
        <button
            type="button"
            class="tl-menu-toggle"
            data-sidebar-toggle
            aria-label="Toggle navigation"
            aria-expanded="false"
        >
            ☰
        </button>

        <div>
            <h1><?= e($pageTitle) ?></h1>
            <p>General Services and Procurement Office</p>
        </div>
    </div>

    <div class="tl-topbar-user">
        <span class="tl-online-dot"></span>
        <?= e($user['full_name']) ?>
    </div>
</header>
