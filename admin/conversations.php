<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('conversations.view');

$status = (string) input('status');
$sentiment = (string) input('sentiment');
$intent = (string) input('intent');
$search = mb_substr(trim((string) input('q')), 0, 100);
$from = (string) input('from');
$to = (string) input('to');

$where = ['s.message_count > 0'];
$params = [];
if ($status === 'open') {
    $where[] = "s.status <> 'closed'";
} elseif (in_array($status, ['active', 'escalated', 'human', 'closed'], true)) {
    $where[] = 's.status = ?';
    $params[] = $status;
}
if (in_array($sentiment, TextAnalyzer::SENTIMENTS, true)) {
    $where[] = 's.last_sentiment = ?';
    $params[] = $sentiment;
}
if (isset(TextAnalyzer::INTENTS[$intent])) {
    $where[] = 's.last_intent = ?';
    $params[] = $intent;
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR s.title LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 's.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 's.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM chat_sessions s JOIN users u ON u.id = s.customer_id WHERE $whereSql", $params), 15);
$rows = DB::all(
    "SELECT s.*, u.full_name AS customer_name, u.email AS customer_email, st.full_name AS staff_name
     FROM chat_sessions s
     JOIN users u ON u.id = s.customer_id
     LEFT JOIN users st ON st.id = s.assigned_staff_id
     WHERE $whereSql
     ORDER BY s.last_activity_at DESC" . $pager->limitSql(),
    $params
);

$pageTitle = 'Conversations';
$pageSubtitle = 'Monitor AI conversations and step in when a customer needs a person.';
$activeNav = 'conversations';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-card mb-4">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3">
            <label class="form-label small" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Customer, email or topic">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['open' => 'Open (not closed)', 'active' => 'AI handling', 'escalated' => 'Waiting for human', 'human' => 'Human handling', 'closed' => 'Closed'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="sentiment">Sentiment</label>
            <select class="form-select" id="sentiment" name="sentiment">
                <option value="">Any</option>
                <?php foreach (TextAnalyzer::SENTIMENTS as $s): ?>
                    <option value="<?= e($s) ?>" <?= $sentiment === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="intent">Intent</label>
            <select class="form-select" id="intent" name="intent">
                <option value="">Any</option>
                <?php foreach (TextAnalyzer::INTENTS as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $intent === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-6 col-md-1 d-grid">
            <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-filter"></i></button>
        </div>
    </form>
</div>

<div class="ns-card">
    <div class="ns-card-head"><h2><?= (int) $pager->total ?> conversation<?= $pager->total === 1 ? '' : 's' ?></h2></div>
    <?php if (!$rows): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-comments"></i><p class="mb-0">No conversations match these filters.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle">
                <thead>
                <tr><th>Customer</th><th>Topic</th><th>Started</th><th>Last activity</th><th>Intent</th><th>Sentiment</th><th>Confidence</th><th>Provider</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $s): ?>
                    <tr class="<?= $s['status'] === 'escalated' ? 'ns-row-alert' : '' ?>">
                        <td><strong><?= e($s['customer_name']) ?></strong><br><small class="text-muted"><?= e($s['customer_email']) ?></small></td>
                        <td class="ns-cell-wide"><?= e(str_limit((string) $s['title'], 60)) ?><br><small class="text-muted"><?= (int) $s['message_count'] ?> messages · <?= e(Lang::AVAILABLE[$s['language']]['short'] ?? $s['language']) ?></small></td>
                        <td class="text-nowrap small"><?= e(format_dt($s['created_at'], 'd M, g:i A')) ?></td>
                        <td class="text-nowrap small"><?= e(time_ago($s['last_activity_at'])) ?></td>
                        <td><span class="ns-badge ns-badge-muted"><?= e(TextAnalyzer::INTENTS[$s['last_intent']] ?? '—') ?></span></td>
                        <td><?= badge('sentiment', $s['last_sentiment']) ?></td>
                        <td><?= $s['last_confidence'] !== null ? '<span class="ns-confidence-cell" style="--v:' . (int) $s['last_confidence'] . '">' . (int) $s['last_confidence'] . '%</span>' : '—' ?></td>
                        <td class="small"><?= e(AiEngine::SLOT_LABELS[$s['last_provider']] ?? '—') ?></td>
                        <td><?= badge('chat', $s['status']) ?><?= (int) $s['is_escalated'] ? ' <i class="fa-solid fa-flag text-danger" title="Escalated"></i>' : '' ?><?php if ($s['staff_name']): ?><br><small class="text-muted"><?= e($s['staff_name']) ?></small><?php endif; ?></td>
                        <td class="text-end"><a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/conversation.php?id=' . $s['id'])) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $pager->render() ?>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
