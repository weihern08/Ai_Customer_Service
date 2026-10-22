<?php
require __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    redirect('index.php');
}
if (!Settings::bool('allow_registration')) {
    flash('info', __('auth.registration_closed'));
    redirect('login.php');
}

$errors = [];
if (is_post()) {
    require_csrf();
    $name = (string) input('full_name');
    $email = mb_strtolower((string) input('email'));
    $phone = (string) input('phone');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    remember_input(compact('name', 'email', 'phone') + ['full_name' => $name]);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $errors['full_name'] = __('validation.name');
    }
    if (!valid_email($email)) {
        $errors['email'] = __('validation.email');
    } elseif (DB::value('SELECT id FROM users WHERE email = ?', [$email])) {
        $errors['email'] = __('validation.email_taken');
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $phone)) {
        $errors['phone'] = __('validation.phone');
    }
    if ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    } elseif (!hash_equals($password, $confirm)) {
        $errors['password_confirm'] = __('validation.password_match');
    }

    if (!$errors) {
        $roleId = (int) DB::value("SELECT id FROM roles WHERE slug = 'customer'");
        $userId = DB::insert('users', [
            'role_id'            => $roleId,
            'full_name'          => $name,
            'email'              => $email,
            'phone'              => $phone ?: null,
            'password_hash'      => password_hash($password, PASSWORD_DEFAULT),
            'preferred_language' => current_lang(),
        ]);
        AuditLog::record('customer_registered', "New customer account: $email", $userId);
        Auth::login($userId);
        clear_old();
        flash('success', __('auth.registered', ['name' => $name]));
        redirect('chat.php');
    }
}

$pageTitle = __('auth.register_title');
require __DIR__ . '/includes/layout/portal_header.php';

$field = static function (string $name, string $label, string $type = 'text', string $auto = '') use ($errors): string {
    $value = in_array($type, ['password'], true) ? '' : old($name);
    $invalid = isset($errors[$name]) ? ' is-invalid' : '';
    $html = '<div class="mb-3"><label class="form-label" for="' . e($name) . '">' . e($label) . '</label>'
        . '<input class="form-control' . $invalid . '" type="' . e($type) . '" id="' . e($name) . '" name="' . e($name) . '" value="' . e($value) . '"'
        . ($auto ? ' autocomplete="' . e($auto) . '"' : '') . ($name !== 'phone' ? ' required' : '') . '>';
    if (isset($errors[$name])) {
        $html .= '<div class="invalid-feedback">' . e($errors[$name]) . '</div>';
    }
    return $html . '</div>';
};
?>
<section class="ns-auth">
    <div class="ns-auth-card">
        <div class="ns-auth-side">
            <span class="ns-eyebrow"><i class="fa-solid fa-user-plus"></i> <?= e(__('auth.free')) ?></span>
            <h2><?= e(__('auth.register_side_title')) ?></h2>
            <ul class="ns-check-list">
                <li><?= e(__('auth.benefit_history')) ?></li>
                <li><?= e(__('auth.benefit_tickets')) ?></li>
                <li><?= e(__(Lang::isMultilingual() ? 'auth.benefit_lang' : 'auth.benefit_voice')) ?></li>
            </ul>
        </div>
        <div class="ns-auth-form">
            <h1><?= e(__('auth.register_title')) ?></h1>
            <p class="text-muted"><?= e(__('auth.register_lead')) ?></p>
            <?php if ($errors): ?>
                <div class="alert alert-danger" role="alert"><i class="fa-solid fa-circle-exclamation me-2"></i><?= e(__('validation.fix_errors')) ?></div>
            <?php endif; ?>
            <form method="post" novalidate data-ns-submit-once>
                <?= csrf_field() ?>
                <?= $field('full_name', __('field.full_name'), 'text', 'name') ?>
                <?= $field('email', __('field.email'), 'email', 'email') ?>
                <?= $field('phone', __('field.phone_optional'), 'tel', 'tel') ?>
                <div class="row g-2">
                    <div class="col-sm-6"><?= $field('password', __('field.password'), 'password', 'new-password') ?></div>
                    <div class="col-sm-6"><?= $field('password_confirm', __('field.password_confirm'), 'password', 'new-password') ?></div>
                </div>
                <p class="form-text mt-n2 mb-3"><?= e(__('auth.password_rule')) ?></p>
                <button class="btn ns-btn-primary w-100 btn-lg" type="submit"><?= e(__('auth.create_account')) ?></button>
            </form>
            <p class="mt-3 mb-0"><?= e(__('auth.have_account')) ?> <a href="<?= e(url('login.php')) ?>"><?= e(__('nav.login')) ?></a></p>
        </div>
    </div>
</section>
<?php clear_old(); require __DIR__ . '/includes/layout/portal_footer.php'; ?>
