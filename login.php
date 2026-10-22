<?php
require __DIR__ . '/includes/bootstrap.php';

$next = safe_next(input('next'), '');
if (Auth::check()) {
    redirect($next ?: (Auth::can('admin.access') ? 'admin/index.php' : 'index.php'));
}

$error = null;
if (is_post()) {
    require_csrf();
    $email = mb_strtolower((string) input('email'));
    $password = (string) ($_POST['password'] ?? '');
    remember_input(['email' => $email]);

    if (!valid_email($email) || $password === '') {
        $error = __('auth.invalid');
    } else {
        $error = Auth::attempt($email, $password);
    }
    if ($error === null) {
        clear_old();
        flash('success', __('auth.welcome_back', ['name' => Auth::user()['full_name']]));
        redirect($next ?: (Auth::can('admin.access') ? 'admin/index.php' : 'index.php'));
    }
}

// Demo accounts from the sample data (only listed while they still exist and are active)
$demoAccounts = [
    'admin@novaserve.example'          => ['role.super_admin', 'Admin@12345'],
    'daniel.wong@novaserve.example'    => ['role.support_staff', 'Demo@12345'],
    'farah.iskandar@novaserve.example' => ['role.content_manager', 'Demo@12345'],
    'meiling.tan@mail.example'         => ['role.customer', 'Demo@12345'],
    'hafiz.kamarul@mail.example'       => ['role.customer', 'Demo@12345'],
];
$existingDemo = array_column(DB::all(
    "SELECT email FROM users WHERE account_status = 'active' AND email IN (" . DB::placeholders(array_keys($demoAccounts)) . ')',
    array_keys($demoAccounts)
), 'email');
$demoAccounts = array_intersect_key($demoAccounts, array_flip($existingDemo));

$pageTitle = __('auth.login_title');
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="ns-auth">
    <div class="ns-auth-card">
        <div class="ns-auth-side">
            <span class="ns-eyebrow"><i class="fa-solid fa-lock"></i> <?= e(__('auth.secure')) ?></span>
            <h2><?= e(__('auth.login_side_title')) ?></h2>
            <ul class="ns-check-list">
                <li><?= e(__('auth.benefit_history')) ?></li>
                <li><?= e(__('auth.benefit_tickets')) ?></li>
                <li><?= e(__('auth.benefit_human')) ?></li>
            </ul>
        </div>
        <div class="ns-auth-form">
            <h1><?= e(__('auth.login_title')) ?></h1>
            <p class="text-muted"><?= e(__('auth.login_lead')) ?></p>
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert"><i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" novalidate data-ns-submit-once>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <div class="mb-3">
                    <label class="form-label" for="email"><?= e(__('field.email')) ?></label>
                    <input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password"><?= e(__('field.password')) ?></label>
                    <div class="input-group">
                        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" data-ns-toggle-password="password" aria-label="<?= e(__('auth.show_password')) ?>"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </div>
                <button class="btn ns-btn-primary w-100 btn-lg" type="submit"><?= e(__('nav.login')) ?></button>
            </form>
            <p class="small text-muted mt-3 mb-1"><?= e(__('auth.forgot_hint')) ?></p>
            <?php if (Settings::bool('allow_registration')): ?>
                <p class="mt-3 mb-0"><?= e(__('auth.no_account')) ?> <a href="<?= e(url('register.php')) ?>"><?= e(__('nav.register')) ?></a></p>
            <?php endif; ?>
            <?php if ($demoAccounts): ?>
                <div class="ns-demo-accounts">
                    <div class="ns-demo-title"><i class="fa-solid fa-key me-2"></i><?= e(__('auth.demo_title')) ?></div>
                    <p class="small text-muted mb-2"><?= e(__('auth.demo_hint')) ?></p>
                    <?php foreach ($demoAccounts as $demoEmail => [$roleKey, $demoPassword]): ?>
                        <button type="button" class="ns-demo-row" data-ns-demo-email="<?= e($demoEmail) ?>" data-ns-demo-password="<?= e($demoPassword) ?>">
                            <span class="ns-demo-role"><?= e(__($roleKey)) ?></span>
                            <span class="ns-demo-cred"><span><?= e($demoEmail) ?></span><code><?= e($demoPassword) ?></code></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php clear_old(); require __DIR__ . '/includes/layout/portal_footer.php'; ?>
