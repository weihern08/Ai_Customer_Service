<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('users.manage');

$search = mb_substr(trim((string) input('q')), 0, 100);
$role = (string) input('role');
$roles = DB::all('SELECT * FROM roles ORDER BY id');

$where = '1 = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like);
}
if (in_array($role, array_column($roles, 'slug'), true)) {
    $where .= ' AND r.slug = ?';
    $params[] = $role;
}
$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE $where", $params), 20);
$users = DB::all(
    "SELECT u.id, u.full_name, u.email, u.account_status, u.last_login_at, u.created_at, r.slug AS role_slug, r.name AS role_name
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE $where ORDER BY r.id, u.full_name" . $pager->limitSql(),
    $params
);
$roleCounts = [];
foreach (DB::all('SELECT r.slug, COUNT(u.id) AS n FROM roles r LEFT JOIN users u ON u.role_id = r.id GROUP BY r.slug') as $row) {
    $roleCounts[$row['slug']] = (int) $row['n'];
}

$pageTitle = 'Users & Staff';
$pageSubtitle = 'Manage administrators, support staff, content managers and customers.';
$activeNav = 'users';
$pageActions = '<a class="btn ns-btn-primary" href="' . e(url('admin/user_form.php')) . '"><i class="fa-solid fa-user-plus me-2"></i>Add user</a>';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-pill-row mb-3">
    <a class="ns-pill <?= $role === '' ? 'active' : '' ?>" href="?<?= e(http_build_query(['q' => $search])) ?>">All <span><?= array_sum($roleCounts) ?></span></a>
    <?php foreach ($roles as $r): ?>
        <a class="ns-pill <?= $role === $r['slug'] ? 'active' : '' ?>" href="?<?= e(http_build_query(['role' => $r['slug'], 'q' => $search])) ?>"><?= e($r['name']) ?> <span><?= $roleCounts[$r['slug']] ?? 0 ?></span></a>
    <?php endforeach; ?>
</div>
<div class="ns-card mb-4">
    <form class="ns-inline-search" method="get" role="search">
        <?php if ($role): ?><input type="hidden" name="role" value="<?= e($role) ?>"><?php endif; ?>
        <i class="fa-solid fa-magnifying-glass"></i>
        <label class="visually-hidden" for="q">Search users</label>
        <input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Search by name or email">
    </form>
</div>
<div class="ns-card">
    <div class="table-responsive">
        <table class="table ns-table align-middle">
            <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= $u['account_status'] === 'suspended' ? 'ns-row-muted' : '' ?>">
                    <td><div class="d-flex align-items-center gap-2"><span class="ns-avatar ns-avatar-sm"><?= e(initials($u['full_name'])) ?></span><div><strong><?= e($u['full_name']) ?></strong><?= (int) $u['id'] === Auth::id() ? ' <span class="ns-badge ns-badge-muted">You</span>' : '' ?><br><small class="text-muted"><?= e($u['email']) ?></small></div></div></td>
                    <td><?= badge('role', $u['role_slug']) ?></td>
                    <td><?= badge('account', $u['account_status']) ?></td>
                    <td class="small"><?= e(time_ago($u['last_login_at'])) ?></td>
                    <td class="small"><?= e(format_dt($u['created_at'], 'd M Y')) ?></td>
                    <td class="text-end"><a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/user_form.php?id=' . $u['id'])) ?>"><i class="fa-solid fa-pen me-1"></i>Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render() ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
