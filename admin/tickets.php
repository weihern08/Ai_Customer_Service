<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('tickets.view');
$viewAll = Auth::can('tickets.view_all');

$status = (string) input('status', 'active');
$priority = (string) input('priority');
$assigned = (string) input('assigned');
$categoryId = input_int('category');
$search = mb_substr(trim((string) input('q')), 0, 100);

$where = ['1 = 1'];
$params = [];
if (!$viewAll) {
    // Support staff: their own tickets plus the unassigned queue
    $where[] = '(t.assigned_to = ? OR t.assigned_to IS NULL)';
    $params[] = (int) $user['id'];
}
if ($status === 'active') {
    $where[] = "t.status IN ('open','pending','in_progress')";
} elseif (in_array($status, TicketService::STATUSES, true)) {
    $where[] = 't.status = ?';
    $params[] = $status;
}
if (in_array($priority, TicketService::PRIORITIES, true)) {
    $where[] = 't.priority = ?';
    $params[] = $priority;
}
if ($assigned === 'me') {
    $where[] = 't.assigned_to = ?';
    $params[] = (int) $user['id'];
} elseif ($assigned === 'none') {
    $where[] = 't.assigned_to IS NULL';
}
if ($categoryId) {
    $where[] = 't.category_id = ?';
    $params[] = $categoryId;
}
if ($search !== '') {
    $where[] = '(t.ticket_no LIKE ? OR t.subject LIKE ? OR c.full_name LIKE ? OR c.email LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM tickets t JOIN users c ON c.id = t.customer_id WHERE $whereSql", $params), 15);
$tickets = DB::all(
    "SELECT t.*, c.full_name AS customer_name, a.full_name AS assignee_name, cat.name AS category_name,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
     FROM tickets t
     JOIN users c ON c.id = t.customer_id
     LEFT JOIN users a ON a.id = t.assigned_to
     LEFT JOIN content_categories cat ON cat.id = t.category_id
     WHERE $whereSql
     ORDER BY FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.updated_at DESC" . $pager->limitSql(),
    $params
);
$categories = ContentService::categories(false);

$pageTitle = 'Tickets';
$pageSubtitle = $viewAll ? 'All customer support tickets.' : 'Tickets assigned to you and the unassigned queue.';
$activeNav = 'tickets';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-card mb-4">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3">
            <label class="form-label small" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Ticket no, subject or customer">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach (TicketService::STATUSES as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="priority">Priority</label>
            <select class="form-select" id="priority" name="priority">
                <option value="">Any</option>
                <?php foreach (TicketService::PRIORITIES as $p): ?>
                    <option value="<?= e($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="assigned">Assignee</label>
            <select class="form-select" id="assigned" name="assigned">
                <option value="">Anyone</option>
                <option value="me" <?= $assigned === 'me' ? 'selected' : '' ?>>Assigned to me</option>
                <option value="none" <?= $assigned === 'none' ? 'selected' : '' ?>>Unassigned</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">Any</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-grid"><button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-filter"></i></button></div>
    </form>
</div>

<div class="ns-card">
    <div class="ns-card-head"><h2><?= (int) $pager->total ?> ticket<?= $pager->total === 1 ? '' : 's' ?></h2></div>
    <?php if (!$tickets): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-envelope-open"></i><p class="mb-0">No tickets match these filters.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle">
                <thead><tr><th>Ticket</th><th>Customer</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Updated</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr class="<?= $t['priority'] === 'urgent' && in_array($t['status'], TicketService::ACTIVE_STATUSES, true) ? 'ns-row-alert' : '' ?>">
                        <td class="ns-cell-wide"><a class="fw-semibold" href="<?= e(url('admin/ticket.php?id=' . $t['id'])) ?>"><?= e($t['ticket_no']) ?></a><br><span class="small"><?= e(str_limit($t['subject'], 60)) ?></span></td>
                        <td><?= e($t['customer_name']) ?></td>
                        <td class="small"><?= e($t['category_name'] ?? '—') ?></td>
                        <td><?= badge('priority', $t['priority']) ?></td>
                        <td><?= badge('status', $t['status']) ?></td>
                        <td class="small"><?= e($t['assignee_name'] ?? 'Unassigned') ?></td>
                        <td class="small text-nowrap"><?= e(time_ago($t['updated_at'])) ?></td>
                        <td class="text-end"><a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/ticket.php?id=' . $t['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $pager->render() ?>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
