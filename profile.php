<?php
require __DIR__ . '/includes/bootstrap.php';

$user = Auth::requireLogin();
$userId = (int) $user['id'];
$errors = [];
$passwordErrors = [];

if (is_post()) {
    require_csrf();
    $form = (string) input('form');

    if ($form === 'profile') {
        // Only these fields can be changed here. Role, status and permissions are never read from the request.
        $name = trim((string) input('full_name'));
        $phone = trim((string) input('phone'));
        $language = (string) input('preferred_language', (string) $user['preferred_language']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['full_name'] = __('validation.name');
        }
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $phone)) {
            $errors['phone'] = __('validation.phone');
        }
        if (!isset(Lang::AVAILABLE[$language])) {
            $language = 'en';
        }
        if (!$errors) {
            DB::update('users', ['full_name' => $name, 'phone' => $phone ?: null, 'preferred_language' => $language], 'id = ?', [$userId]);
            Lang::setCurrent($language);
            AuditLog::record('profile_updated', 'Updated profile details', $userId);
            flash('success', Lang::get('profile.saved', [], $language));
            redirect('profile.php');
        }
    } elseif ($form === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!password_verify($current, $hash)) {
            $passwordErrors['current_password'] = __('profile.wrong_password');
        }
        if ($problem = password_problem($new)) {
            $passwordErrors['password'] = $problem;
        } elseif (!hash_equals($new, $confirm)) {
            $passwordErrors['password_confirm'] = __('validation.password_match');
        }
        if (!$passwordErrors) {
            DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$userId]);
            Session::regenerate();
            AuditLog::record('password_changed', 'Changed account password', $userId);
            flash('success', __('profile.password_saved'));
            redirect('profile.php');
        }
    }
}

$user = Auth::findUser($userId);
$stats = DB::row(
    "SELECT
        (SELECT COUNT(*) FROM tickets WHERE customer_id = ?) AS tickets,
        (SELECT COUNT(*) FROM tickets WHERE customer_id = ? AND status IN ('open','pending','in_progress')) AS open_tickets,
        (SELECT COUNT(*) FROM chat_sessions WHERE customer_id = ? AND message_count > 0) AS conversations,
        (SELECT COUNT(*) FROM message_feedback WHERE user_id = ?) AS ratings",
    [$userId, $userId, $userId, $userId]
);

$pageTitle = __('profile.title');
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="container-xl ns-section pt-4">
    <div class="ns-profile-cover" aria-hidden="true"></div>
    <div class="ns-profile-head">
        <span class="ns-avatar ns-avatar-xl"><?= e(initials($user['full_name'])) ?></span>
        <div>
            <h1 class="h3 mb-1"><?= e($user['full_name']) ?></h1>
            <p class="text-muted mb-0"><?= e($user['email']) ?> · <span class="ns-badge ns-badge-muted"><?= e($user['role_name']) ?></span></p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['fa-ticket', __('profile.tickets'), $stats['tickets']],
            ['fa-folder-open', __('profile.open_tickets'), $stats['open_tickets']],
            ['fa-comments', __('profile.conversations'), $stats['conversations']],
            ['fa-thumbs-up', __('profile.ratings'), $stats['ratings']],
        ] as [$icon, $label, $value]): ?>
            <div class="col-6 col-lg-3">
                <div class="ns-mini-stat"><i class="fa-solid <?= e($icon) ?>"></i><strong><?= (int) $value ?></strong><span><?= e($label) ?></span></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="ns-panel">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-id-card"></i><?= e(__('profile.details')) ?></h2></div>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="profile">
                    <div class="mb-3">
                        <label class="form-label" for="full_name"><?= e(__('field.full_name')) ?></label>
                        <input class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" id="full_name" name="full_name" value="<?= e($_POST['full_name'] ?? $user['full_name']) ?>" maxlength="120" required>
                        <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback"><?= e($errors['full_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email"><?= e(__('field.email')) ?></label>
                        <input class="form-control" id="email" value="<?= e($user['email']) ?>" disabled>
                        <div class="form-text"><?= e(__('profile.email_locked')) ?></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="phone"><?= e(__('field.phone')) ?></label>
                            <input class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" id="phone" name="phone" value="<?= e($_POST['phone'] ?? (string) $user['phone']) ?>" maxlength="30">
                            <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
                        </div>
                        <?php if (Lang::isMultilingual()): ?>
                        <div class="col-sm-6">
                            <label class="form-label" for="preferred_language"><?= e(__('profile.language')) ?></label>
                            <select class="form-select" id="preferred_language" name="preferred_language">
                                <?php foreach (Lang::AVAILABLE as $code => $meta): ?>
                                    <option value="<?= e($code) ?>" <?= $user['preferred_language'] === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i><?= e(__('common.save')) ?></button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="ns-panel mb-4">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-clock-rotate-left"></i><?= e(__('profile.account')) ?></h2></div>
                <dl class="ns-dl">
                    <dt><?= e(__('profile.member_since')) ?></dt><dd><?= e(format_dt($user['created_at'], 'd M Y')) ?></dd>
                    <dt><?= e(__('profile.last_login')) ?></dt><dd><?= e(format_dt($user['last_login_at'])) ?></dd>
                    <dt><?= e(__('profile.status')) ?></dt><dd><span class="ns-badge ns-badge-status-resolved"><?= e(__('profile.active')) ?></span></dd>
                </dl>
            </div>
            <div class="ns-panel">
                <div class="ns-panel-head"><h2><i class="fa-solid fa-key"></i><?= e(__('profile.change_password')) ?></h2></div>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="password">
                    <?php foreach (['current_password' => __('profile.current_password'), 'password' => __('field.new_password'), 'password_confirm' => __('field.password_confirm')] as $name => $label): ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?= e($name) ?>"><?= e($label) ?></label>
                            <input class="form-control <?= isset($passwordErrors[$name]) ? 'is-invalid' : '' ?>" type="password" id="<?= e($name) ?>" name="<?= e($name) ?>" autocomplete="<?= $name === 'current_password' ? 'current-password' : 'new-password' ?>" required>
                            <?php if (isset($passwordErrors[$name])): ?><div class="invalid-feedback"><?= e($passwordErrors[$name]) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="form-text"><?= e(__('auth.password_rule')) ?></p>
                    <button class="btn ns-btn-ghost" type="submit"><?= e(__('profile.update_password')) ?></button>
                </form>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/layout/portal_footer.php'; ?>
