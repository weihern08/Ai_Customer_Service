<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('logs.view');

$search = mb_substr(trim((string) input('q')), 0, 100);
$action = (string) input('action');
$from = (string) input('from');
$to = (string) input('to');

$where = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(l.description LIKE ? OR l.ip_address LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($action !== '' && preg_match('/^[a-z_]{2,60}$/', $action)) {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'l.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'l.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $whereSql", $params), 25);
$logs = DB::all(
    "SELECT l.*, u.full_name, u.email FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
     WHERE $whereSql ORDER BY l.id DESC" . $pager->limitSql(),
    $params
);
$usedActions = array_column(DB::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
$securityActions = ['login_failed', 'access_denied', 'upload_rejected'];

$pageTitle = 'Activity Logs';
$pageSubtitle = 'Audit trail of sign-ins, changes and security events.';
$activeNav = 'logs';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-card mb-4">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4"><label class="form-label small" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="User, description or IP address"></div>
        <div class="col-md-3">
            <label class="form-label small" for="action">Event</label>
            <select class="form-select" id="action" name="action">
                <option value="">All events</option>
                <?php foreach ($usedActions as $a): ?><option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e(AuditLog::label($a)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2"><label class="form-label small" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-md-1 d-grid"><button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-filter"></i></button></div>
    </form>
</div>
<div class="ns-card">
    <div class="ns-card-head"><h2><?= number_format($pager->total) ?> events</h2></div>
    <?php if (!$logs): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-clipboard"></i><p class="mb-0">No log entries match these filters.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle small">
                <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Description</th><th>IP address</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr class="<?= in_array($log['action'], $securityActions, true) ? 'ns-row-alert' : '' ?>">
                        <td class="text-nowrap"><?= e(format_dt($log['created_at'], 'd M Y, g:i:s A')) ?></td>
                        <td><?= $log['full_name'] ? e($log['full_name']) . '<br><span class="text-muted">' . e($log['email']) . '</span>' : '<span class="text-muted">Guest / system</span>' ?></td>
                        <td><span class="ns-badge ns-badge-log <?= in_array($log['action'], $securityActions, true) ? 'ns-badge-log-danger' : '' ?>"><?= e(AuditLog::label($log['action'])) ?></span></td>
                        <td class="ns-cell-wide"><?= e((string) $log['description']) ?></td>
                        <td class="text-nowrap"><code><?= e((string) $log['ip_address']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $pager->render() ?>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
