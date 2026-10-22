<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('escalations.manage');

$status = (string) input('status', 'open');
$where = '1 = 1';
$params = [];
if ($status === 'open') {
    $where = "e.status <> 'resolved'";
} elseif (in_array($status, ['pending', 'in_progress', 'resolved'], true)) {
    $where = 'e.status = ?';
    $params[] = $status;
}

$pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM escalations e WHERE $where", $params), 15);
$rows = DB::all(
    "SELECT e.*, u.full_name AS customer_name, u.email AS customer_email, h.full_name AS handler_name,
            s.title AS session_title, s.status AS session_status
     FROM escalations e
     JOIN users u ON u.id = e.customer_id
     JOIN chat_sessions s ON s.id = e.session_id
     LEFT JOIN users h ON h.id = e.handled_by
     WHERE $where
     ORDER BY FIELD(e.status, 'pending', 'in_progress', 'resolved'), e.created_at DESC" . $pager->limitSql(),
    $params
);
$counts = [];
foreach (DB::all('SELECT status, COUNT(*) AS n FROM escalations GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}

$pageTitle = 'Escalations';
$pageSubtitle = 'Customers who asked for a human, were upset, or received a low-confidence answer.';
$activeNav = 'escalations';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="ns-pill-row mb-3">
    <?php foreach (['open' => 'Open', 'pending' => 'Waiting', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label):
        $n = match ($k) {
            'open' => ($counts['pending'] ?? 0) + ($counts['in_progress'] ?? 0),
            'all' => array_sum($counts),
            default => $counts[$k] ?? 0,
        }; ?>
        <a class="ns-pill <?= $status === $k ? 'active' : '' ?>" href="?status=<?= e($k) ?>"><?= e($label) ?> <span><?= $n ?></span></a>
    <?php endforeach; ?>
</div>

<div class="ns-card">
    <?php if (!$rows): ?>
        <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-face-smile"></i><p class="mb-0">The queue is empty.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ns-table align-middle">
                <thead><tr><th>Customer</th><th>Conversation</th><th>Reason</th><th>Intent</th><th>Sentiment</th><th>Confidence</th><th>Time</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $esc): ?>
                    <tr class="<?= $esc['status'] === 'pending' ? 'ns-row-alert' : '' ?>">
                        <td><strong><?= e($esc['customer_name']) ?></strong><br><small class="text-muted"><?= e($esc['customer_email']) ?></small></td>
                        <td class="ns-cell-wide">#<?= (int) $esc['session_id'] ?> · <?= e(str_limit((string) $esc['session_title'], 50)) ?><?php if ($esc['note']): ?><br><small class="text-muted"><?= e(str_limit($esc['note'], 80)) ?></small><?php endif; ?></td>
                        <td><?= e(EscalationService::REASONS[$esc['reason']] ?? $esc['reason']) ?></td>
                        <td><span class="ns-badge ns-badge-muted"><?= e(TextAnalyzer::INTENTS[$esc['intent']] ?? '—') ?></span></td>
                        <td><?= badge('sentiment', $esc['sentiment']) ?></td>
                        <td><?= $esc['confidence'] !== null ? (int) $esc['confidence'] . '%' : '—' ?></td>
                        <td class="small text-nowrap"><?= e(time_ago($esc['created_at'])) ?></td>
                        <td><?= badge('escalation', $esc['status']) ?><?php if ($esc['handler_name']): ?><br><small class="text-muted"><?= e($esc['handler_name']) ?></small><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($esc['status'] === 'pending' && $esc['session_status'] !== 'closed' && Auth::can('conversations.takeover')): ?>
                                <button class="btn btn-sm ns-btn-primary" type="button" data-ns-quick-takeover="<?= (int) $esc['session_id'] ?>" data-url="<?= e(url('api/escalation.php')) ?>" data-redirect="<?= e(url('admin/conversation.php?id=' . $esc['session_id'])) ?>">Take over</button>
                            <?php endif; ?>
                            <a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/conversation.php?id=' . $esc['session_id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $pager->render() ?>
    <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
