<?php
/**
 * Customer portal header.
 * Page variables: $pageTitle, $activeNav, $bodyClass (optional), $extraHead (optional)
 */
$user = Auth::user();
$activeNav ??= '';
$navItems = [
    'home'      => ['index.php', 'fa-house', __('nav.home')],
    'chat'      => ['chat.php', 'fa-wand-magic-sparkles', __('nav.chat')],
    'knowledge' => ['knowledge.php', 'fa-book-open', __('nav.knowledge')],
    'faq'       => ['faq.php', 'fa-circle-question', __('nav.faq')],
];
if ($user) {
    $navItems['tickets'] = ['ticket.php', 'fa-ticket', __('nav.tickets')];
}
require __DIR__ . '/head.php';
?>
<body class="ns-portal <?= e($bodyClass ?? '') ?>">
<a class="visually-hidden-focusable ns-skip" href="#main"><?= e(__('common.skip')) ?></a>
<header class="ns-topbar">
    <nav class="navbar navbar-expand-lg container-xl" aria-label="<?= e(__('nav.main')) ?>">
        <a class="navbar-brand ns-brand" href="<?= e(url('index.php')) ?>">
            <?php require __DIR__ . '/logo.php'; ?>
            <span class="ns-brand-text">Nova<span>Serve</span></span>
        </a>
        <div class="d-flex align-items-center gap-2 order-lg-3">
            <?php if (Lang::isMultilingual()): ?>
            <div class="dropdown">
                <button class="btn ns-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e(__('nav.language')) ?>">
                    <i class="fa-solid fa-globe"></i><span class="ns-lang-short"><?= e(Lang::AVAILABLE[current_lang()]['short']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end ns-dropdown">
                    <?php foreach (Lang::AVAILABLE as $code => $meta): ?>
                        <li><a class="dropdown-item <?= $code === current_lang() ? 'active' : '' ?>" href="<?= e(query_with(['lang' => $code])) ?>" lang="<?= e($code) ?>"><?= e($meta['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if ($user): ?>
                <?php require __DIR__ . '/notifications_dropdown.php'; ?>
                <div class="dropdown">
                    <button class="btn ns-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e(__('nav.account')) ?>">
                        <span class="ns-avatar"><?= e(initials($user['full_name'])) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end ns-dropdown">
                        <li class="px-3 py-2 small">
                            <div class="fw-bold"><?= e($user['full_name']) ?></div>
                            <div class="text-muted text-truncate" style="max-width:220px"><?= e($user['email']) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if (Auth::can('admin.access')): ?>
                            <li><a class="dropdown-item" href="<?= e(url('admin/index.php')) ?>"><i class="fa-solid fa-gauge-high me-2"></i><?= e(__('nav.admin')) ?></a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="fa-solid fa-user me-2"></i><?= e(__('nav.profile')) ?></a></li>
                        <li><a class="dropdown-item" href="<?= e(url('ticket.php')) ?>"><i class="fa-solid fa-ticket me-2"></i><?= e(__('nav.tickets')) ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('logout.php')) ?>">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit"><i class="fa-solid fa-right-from-bracket me-2"></i><?= e(__('nav.logout')) ?></button>
                            </form>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <a class="btn ns-btn-ghost d-none d-sm-inline-flex" href="<?= e(url('login.php')) ?>"><?= e(__('nav.login')) ?></a>
                <a class="btn ns-btn-primary btn-sm" href="<?= e(url('register.php')) ?>"><?= e(__('nav.register')) ?></a>
            <?php endif; ?>
            <button class="navbar-toggler ns-icon-btn border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nsNav" aria-controls="nsNav" aria-expanded="false" aria-label="<?= e(__('nav.menu')) ?>">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
        <div class="collapse navbar-collapse order-lg-2" id="nsNav">
            <ul class="navbar-nav mx-lg-auto ns-nav">
                <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeNav === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>" <?= $activeNav === $key ? 'aria-current="page"' : '' ?>>
                            <i class="fa-solid <?= e($icon) ?>"></i><?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if (!$user): ?>
                    <li class="nav-item d-sm-none"><a class="nav-link" href="<?= e(url('login.php')) ?>"><i class="fa-solid fa-right-to-bracket"></i><?= e(__('nav.login')) ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>
</header>
<main id="main" class="ns-main">
<?php require __DIR__ . '/flash.php'; ?>
