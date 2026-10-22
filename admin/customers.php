<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('customers.view');

$search = mb_substr(trim((string) input('q')), 0, 100);
$where = "r.slug = 'customer'";
$params = [];
if ($search !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like, $like);
}

$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE $where", $params), 20);
$customers = DB::all(
    "SELECT u.id, u.full_name, u.email, u.phone, u.account_status, u.created_at, u.last_login_at, u.preferred_language,
            (SELECT COUNT(*) FROM tickets t WHERE t.customer_id = u.id) AS tickets,
            (SELECT COUNT(*) FROM chat_sessions s WHERE s.customer_id = u.id AND s.message_count > 0) AS conversations
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE $where ORDER BY u.created_at DESC" . $pager->limitSql(),
    $params
);

$pageTitle = 'Customers';
$pageSubtitle = 'Search customers by name, email or phone.';
$activeNav = 'customers';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-card mb-4">
    <form class="ns-inline-search" method="get" role="search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <label class="visually-hidden" for="q">Search customers</label>
        <input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Search by name, email or phone">
    </form>
</div>
<div class="ns-card">
    <div class="ns-card-head"><h2><?= (int) $pager->total ?> customer<?= $pager->total === 1 ? '' : 's' ?></h2></div>
    <?php if (!$customers): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-address-book"></i><p class="mb-0">No customers found.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle">
                <thead><tr><th>Customer</th><th>Phone</th><th>Language</th><th>Tickets</th><th>Conversations</th><th>Joined</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><div class="d-flex align-items-center gap-2"><span class="ns-avatar ns-avatar-sm"><?= e(initials($c['full_name'])) ?></span><div><strong><?= e($c['full_name']) ?></strong><br><small class="text-muted"><?= e($c['email']) ?></small></div></div></td>
                        <td class="small"><?= e($c['phone'] ?: '—') ?></td>
                        <td class="small"><?= e(Lang::AVAILABLE[$c['preferred_language']]['label'] ?? $c['preferred_language']) ?></td>
                        <td><?= (int) $c['tickets'] ?></td>
                        <td><?= (int) $c['conversations'] ?></td>
                        <td class="small text-nowrap"><?= e(format_dt($c['created_at'], 'd M Y')) ?></td>
                        <td class="small text-nowrap"><?= e(time_ago($c['last_login_at'])) ?></td>
                        <td><?= badge('account', $c['account_status']) ?></td>
                        <td class="text-end"><a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/customer.php?id=' . $c['id'])) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $pager->render() ?>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
