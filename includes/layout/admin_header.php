<?php
/**
 * Admin console layout (sidebar + top bar).
 * Page variables: $pageTitle, $activeNav, $pageSubtitle (optional), $pageActions (optional HTML)
 */
$user = Auth::user();
$activeNav ??= '';
$pendingEscalations = Auth::can('escalations.manage') ? EscalationService::pendingCount() : 0;

$sidebar = [
    'Overview' => [
        ['dashboard', 'index.php', 'fa-chart-pie', 'Dashboard', 'dashboard.view', 0],
        ['ai_monitor', 'ai_monitor.php', 'fa-microchip', 'AI Monitoring', 'analytics.view', 0],
    ],
    'Support desk' => [
        ['escalations', 'escalations.php', 'fa-hand-holding-heart', 'Escalations', 'escalations.manage', $pendingEscalations],
        ['conversations', 'conversations.php', 'fa-comments', 'Conversations', 'conversations.view', 0],
        ['tickets', 'tickets.php', 'fa-ticket', 'Tickets', 'tickets.view', 0],
        ['customers', 'customers.php', 'fa-address-book', 'Customers', 'customers.view', 0],
    ],
    'Knowledge' => [
        ['faq', 'faqs.php', 'fa-circle-question', 'FAQ', 'faq.manage', 0],
        ['articles', 'articles.php', 'fa-book-open', 'Knowledge Base', 'kb.manage', 0],
        ['categories', 'categories.php', 'fa-layer-group', 'Categories', 'categories.manage', 0],
    ],
    'Administration' => [
        ['users', 'users.php', 'fa-users-gear', 'Users & Staff', 'users.manage', 0],
        ['ai_settings', 'ai_settings.php', 'fa-robot', 'AI Configuration', 'ai.configure', 0],
        ['settings', 'settings.php', 'fa-sliders', 'System Settings', 'settings.manage', 0],
        ['logs', 'logs.php', 'fa-clipboard-list', 'Activity Logs', 'logs.view', 0],
    ],
];
require __DIR__ . '/head.php';
?>
<body class="ns-admin">
<a class="visually-hidden-focusable ns-skip" href="#main">Skip to content</a>
<div class="ns-admin-shell">
    <aside class="ns-sidebar offcanvas-lg offcanvas-start" id="nsSidebar" tabindex="-1" aria-label="Admin navigation">
        <div class="ns-sidebar-head">
            <a class="ns-brand ns-brand-light" href="<?= e(url('admin/index.php')) ?>">
                <?php require __DIR__ . '/logo.php'; ?>
                <span class="ns-brand-text">Nova<span>Serve</span></span>
            </a>
            <span class="ns-console-tag">Console</span>
            <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#nsSidebar" aria-label="Close"></button>
        </div>
        <nav class="ns-sidebar-nav">
            <?php foreach ($sidebar as $group => $items):
                $visible = array_filter($items, static fn ($item) => Auth::can($item[4]));
                if (!$visible) {
                    continue;
                } ?>
                <div class="ns-sidebar-group"><?= e($group) ?></div>
                <?php foreach ($visible as [$key, $href, $icon, $label, $perm, $count]): ?>
                    <a class="ns-sidebar-link <?= $activeNav === $key ? 'active' : '' ?>" href="<?= e(url('admin/' . $href)) ?>" <?= $activeNav === $key ? 'aria-current="page"' : '' ?>>
                        <i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                        <?php if ($count > 0): ?><span class="ns-sidebar-count"><?= (int) $count ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="ns-sidebar-foot">
            <a href="<?= e(url('index.php')) ?>" class="ns-sidebar-link"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>Customer portal</span></a>
        </div>
    </aside>

    <div class="ns-admin-main">
        <header class="ns-admin-topbar">
            <button class="btn ns-icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#nsSidebar" aria-controls="nsSidebar" aria-label="Open navigation">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="ns-admin-title">
                <h1><?= e($pageTitle ?? '') ?></h1>
                <?php if (!empty($pageSubtitle)): ?><p><?= e($pageSubtitle) ?></p><?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?php require __DIR__ . '/notifications_dropdown.php'; ?>
                <div class="dropdown">
                    <button class="btn ns-user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="ns-avatar ns-avatar-sm"><?= e(initials($user['full_name'])) ?></span>
                        <span class="d-none d-md-inline text-start">
                            <span class="d-block fw-semibold lh-1"><?= e($user['full_name']) ?></span>
                            <small class="text-muted"><?= e($user['role_name']) ?></small>
                        </span>
                        <i class="fa-solid fa-chevron-down small text-muted"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end ns-dropdown">
                        <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="fa-solid fa-user me-2"></i>My profile</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('index.php')) ?>"><i class="fa-solid fa-house me-2"></i>Customer portal</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('logout.php')) ?>">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <main id="main" class="ns-admin-content">
            <?php require __DIR__ . '/flash.php'; ?>
            <?php if (!empty($pageActions)): ?>
                <div class="ns-page-actions"><?= $pageActions ?></div>
            <?php endif; ?>
