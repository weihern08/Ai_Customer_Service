<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Auth::requirePermission('users.manage');
$id = input_int('id');
$target = $id ? Auth::findUser($id) : null;
if ($id && !$target) {
    abort(404);
}
$roles = DB::all('SELECT * FROM roles ORDER BY id');
$roleIds = array_map('intval', array_column($roles, 'id'));
$superAdminRoleId = (int) DB::value("SELECT id FROM roles WHERE slug = 'super_admin'");
$isSelf = $target && (int) $target['id'] === (int) $admin['id'];
$errors = [];

$values = [
    'full_name'      => $target['full_name'] ?? '',
    'email'          => $target['email'] ?? '',
    'phone'          => $target['phone'] ?? '',
    'role_id'        => (string) ($target['role_id'] ?? DB::value("SELECT id FROM roles WHERE slug = 'support_staff'")),
    'account_status' => $target['account_status'] ?? 'active',
];

if (is_post()) {
    require_csrf();
    $values = [
        'full_name'      => trim((string) input('full_name')),
        'email'          => mb_strtolower(trim((string) input('email'))),
        'phone'          => trim((string) input('phone')),
        'role_id'        => (string) input_int('role_id'),
        'account_status' => input('account_status') === 'suspended' ? 'suspended' : 'active',
    ];
    $password = (string) ($_POST['password'] ?? '');

    if (mb_strlen($values['full_name']) < 2 || mb_strlen($values['full_name']) > 120) {
        $errors['full_name'] = 'Name must be between 2 and 120 characters.';
    }
    if (!valid_email($values['email'])) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$values['email'], $id])) {
        $errors['email'] = 'This email address is already registered.';
    }
    if ($values['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $values['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }
    if (!in_array((int) $values['role_id'], $roleIds, true)) {
        $errors['role_id'] = 'Please choose a role.';
    }
    if (!$target || $password !== '') {
        if ($problem = password_problem($password)) {
            $errors['password'] = $problem;
        }
    }
    // Safety rules: you cannot lock yourself out, and at least one active super admin must remain
    if ($isSelf && ((int) $values['role_id'] !== (int) $target['role_id'] || $values['account_status'] !== 'active')) {
        $errors['role_id'] = 'You cannot change your own role or suspend your own account.';
    }
    if (!isset($errors['role_id']) && $target && (int) $target['role_id'] === $superAdminRoleId && ((int) $values['role_id'] !== $superAdminRoleId || $values['account_status'] !== 'active')) {
        $activeAdmins = (int) DB::value("SELECT COUNT(*) FROM users WHERE role_id = ? AND account_status = 'active'", [$superAdminRoleId]);
        if ($activeAdmins <= 1) {
            $errors['role_id'] = 'At least one active Super Admin is required.';
        }
    }

    if (!$errors) {
        $data = [
            'full_name'      => $values['full_name'],
            'email'          => $values['email'],
            'phone'          => $values['phone'] ?: null,
            'role_id'        => (int) $values['role_id'],
            'account_status' => $values['account_status'],
        ];
        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $roleName = $roles[array_search((int) $values['role_id'], $roleIds, true)]['name'];
        if ($target) {
            DB::update('users', $data, 'id = ?', [$id]);
            $changes = [];
            if ((int) $target['role_id'] !== (int) $values['role_id']) {
                $changes[] = 'role → ' . $roleName;
            }
            if ($target['account_status'] !== $values['account_status']) {
                $changes[] = 'status → ' . $values['account_status'];
            }
            if ($password !== '') {
                $changes[] = 'password reset';
            }
            AuditLog::record('user_updated', 'Updated user ' . $values['email'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
            flash('success', 'User updated.');
        } else {
            $id = DB::insert('users', $data);
            AuditLog::record('user_created', "Created $roleName account: " . $values['email']);
            flash('success', 'User created. Share the temporary password securely and ask them to change it.');
        }
        redirect('admin/users.php');
    }
}

$pageTitle = $target ? 'Edit user' : 'Add user';
$pageSubtitle = $target ? $target['email'] : 'Create a staff or customer account.';
$activeNav = 'users';
require dirname(__DIR__) . '/includes/layout/admin_header.php';

$cls = static fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
$err = static fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
?>
<form method="post" novalidate class="row g-4">
    <?= csrf_field() ?>
    <div class="col-xl-7">
        <div class="ns-card">
            <div class="ns-card-head"><h2>Account details</h2></div>
            <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control<?= $cls('full_name') ?>" id="full_name" name="full_name" value="<?= e($values['full_name']) ?>" required><?= $err('full_name') ?></div>
            <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control<?= $cls('email') ?>" type="email" id="email" name="email" value="<?= e($values['email']) ?>" required><?= $err('email') ?></div>
            <div class="mb-3"><label class="form-label" for="phone">Phone</label><input class="form-control<?= $cls('phone') ?>" id="phone" name="phone" value="<?= e((string) $values['phone']) ?>"><?= $err('phone') ?></div>
            <div class="mb-0">
                <label class="form-label" for="password"><?= $target ? 'Reset password' : 'Temporary password' ?></label>
                <input class="form-control<?= $cls('password') ?>" type="password" id="password" name="password" autocomplete="new-password" <?= $target ? 'placeholder="Leave blank to keep the current password"' : 'required' ?>>
                <?= $err('password') ?>
                <div class="form-text">At least 8 characters with letters and numbers.</div>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Access</h2></div>
            <div class="mb-3">
                <label class="form-label" for="role_id">Role</label>
                <select class="form-select<?= $cls('role_id') ?>" id="role_id" name="role_id" <?= $isSelf ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $values['role_id'] === (string) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($isSelf): ?><input type="hidden" name="role_id" value="<?= e($values['role_id']) ?>"><?php endif; ?>
                <?= $err('role_id') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="account_status">Account status</label>
                <select class="form-select" id="account_status" name="account_status" <?= $isSelf ? 'disabled' : '' ?>>
                    <option value="active" <?= $values['account_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="suspended" <?= $values['account_status'] === 'suspended' ? 'selected' : '' ?>>Suspended (cannot sign in)</option>
                </select>
                <?php if ($isSelf): ?><input type="hidden" name="account_status" value="active"><?php endif; ?>
            </div>
            <div class="ns-role-help small">
                <?php foreach ($roles as $r): ?><p class="mb-1"><strong><?= e($r['name']) ?>:</strong> <?= e((string) $r['description']) ?></p><?php endforeach; ?>
            </div>
        </div>
        <div class="d-grid gap-2">
            <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save user</button>
            <a class="btn ns-btn-ghost" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
        </div>
        <?php if ($target): ?>
            <p class="small text-muted mt-3">Created <?= e(format_dt($target['created_at'])) ?> · Last login <?= e(format_dt($target['last_login_at'])) ?> from <?= e($target['last_login_ip'] ?? '—') ?></p>
        <?php endif; ?>
    </div>
</form>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
