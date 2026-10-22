<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('analytics.view');

$from = (string) input('from', date('Y-m-d', strtotime('-29 days')));
$to = (string) input('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-29 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$m = Analytics::aiPerformance($from, $to);
$daily = Analytics::providerDaily($from, $to);
$failures = Analytics::recentFailures($from, $to);
$intents = Analytics::intents($from, $to);
$sentiments = Analytics::sentiments($from, $to);
$lowConfidence = DB::all(
    "SELECT m.id, m.session_id, m.body, m.confidence, m.provider, m.created_at, q.body AS question
     FROM chat_messages m
     LEFT JOIN chat_messages q ON q.id = (SELECT MAX(id) FROM chat_messages WHERE session_id = m.session_id AND id < m.id AND sender_type = 'customer')
     WHERE m.sender_type = 'ai' AND m.needs_human = 1 AND m.created_at BETWEEN ? AND ?
     ORDER BY m.id DESC LIMIT 8",
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

$charts = [
    'providers' => [
        'type' => 'bar', 'stacked' => true, 'labels' => $daily['labels'],
        'series' => [
            ['label' => 'Primary AI', 'data' => $daily['primary'], 'color' => '#6b4eff'],
            ['label' => 'Fallback AI', 'data' => $daily['fallback'], 'color' => '#0fb5a6'],
            ['label' => 'Local knowledge', 'data' => $daily['local'], 'color' => '#f59e0b'],
            ['label' => 'Failed attempts', 'data' => $daily['failed'], 'color' => '#ef4444'],
        ],
    ],
    'feedback' => [
        'type' => 'doughnut', 'labels' => ['Helpful', 'Not helpful'],
        'data' => [$m['helpful'], $m['feedback_total'] - $m['helpful']], 'colors' => ['#16a34a', '#ef4444'],
    ],
    'intents' => ['type' => 'bar', 'horizontal' => true, 'labels' => array_keys($intents), 'data' => array_values($intents), 'label' => 'Messages'],
    'sentiment' => [
        'type' => 'doughnut', 'labels' => array_map('ucfirst', array_keys($sentiments)), 'data' => array_values($sentiments),
        'colors' => ['#16a34a', '#94a3b8', '#f59e0b', '#ef4444'],
    ],
];

$pageTitle = 'AI Monitoring';
$pageSubtitle = 'Provider health, fallback usage, confidence and customer feedback.';
$activeNav = 'ai_monitor';
$useCharts = true;
require dirname(__DIR__) . '/includes/layout/admin_header.php';

$stat = static function (string $label, string $value, string $hint = '', string $tone = '') {
    echo '<div class="col-6 col-md-4 col-xl-3"><div class="ns-stat ' . e($tone) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong>'
        . ($hint ? '<small>' . e($hint) . '</small>' : '') . '</div></div>';
};
?>
<div class="ns-card mb-4">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-6 col-md-3"><label class="form-label small" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-md-2 d-grid"><button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button></div>
        <div class="col-md-4 text-md-end small text-muted">
            Status: <?= Settings::bool('ai_enabled') ? '<span class="ns-badge ns-badge-status-resolved">AI enabled</span>' : '<span class="ns-badge ns-badge-status-pending">Local mode only</span>' ?>
            <?= Settings::bool('ai_fallback_enabled') ? '<span class="ns-badge ns-badge-muted">Fallback on</span>' : '' ?>
        </div>
    </form>
</div>

<div class="row g-3 mb-4">
    <?php
    $stat('Total AI requests', number_format($m['total_requests']), 'customer questions answered');
    $stat('Successful AI calls', number_format($m['ai_success']), 'primary + fallback', 'ns-stat-good');
    $stat('Failed AI calls', number_format($m['ai_failed']), 'timeouts and API errors', $m['ai_failed'] ? 'ns-stat-bad' : '');
    $stat('Fallback requests', number_format($m['fallback_messages']), 'answered by fallback AI or local knowledge', 'ns-stat-warn');
    $stat('Human escalations', number_format($m['escalations']), $m['escalation_rate'] . '% of conversations');
    $stat('Average confidence', $m['avg_confidence'] . '%', $m['low_confidence'] . ' answers below threshold');
    $stat('Helpful feedback', $m['helpful_rate'] . '%', $m['helpful'] . ' of ' . $m['feedback_total'] . ' ratings', 'ns-stat-good');
    $stat('AI resolution rate', $m['resolution_rate'] . '%', $m['ai_resolved'] . ' conversations resolved by AI');
    ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="ns-card h-100">
            <div class="ns-card-head"><h2>Answers by provider per day</h2><small class="text-muted">Avg. AI latency <?= number_format($m['avg_latency_ms']) ?> ms</small></div>
            <?php if (!$daily['labels']): ?><p class="ns-empty">No AI activity in this period.</p><?php endif; ?>
            <div class="ns-chart ns-chart-lg"><canvas data-ns-chart="providers" role="img" aria-label="Provider usage chart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="ns-card h-100">
            <div class="ns-card-head"><h2>Customer feedback</h2></div>
            <div class="ns-chart"><canvas data-ns-chart="feedback" role="img" aria-label="Feedback chart"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7"><div class="ns-card h-100"><div class="ns-card-head"><h2>Detected intents</h2></div><div class="ns-chart"><canvas data-ns-chart="intents" role="img" aria-label="Intent chart"></canvas></div></div></div>
    <div class="col-lg-5"><div class="ns-card h-100"><div class="ns-card-head"><h2>Sentiment</h2></div><div class="ns-chart"><canvas data-ns-chart="sentiment" role="img" aria-label="Sentiment chart"></canvas></div></div></div>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="ns-card h-100">
            <div class="ns-card-head"><h2>Recent provider failures</h2></div>
            <?php if (!$failures): ?>
                <div class="ns-empty-state ns-empty-state-sm"><i class="fa-solid fa-circle-check text-success"></i><p class="mb-0">No failures in this period.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table ns-table align-middle small">
                        <thead><tr><th>Time</th><th>Provider</th><th>Model</th><th>Error</th></tr></thead>
                        <tbody>
                        <?php foreach ($failures as $f): ?>
                            <tr><td class="text-nowrap"><?= e(format_dt($f['created_at'], 'd M, g:i A')) ?></td><td><?= e(AiEngine::SLOT_LABELS[$f['provider_slot']] ?? $f['provider_slot']) ?></td><td><?= e((string) $f['model']) ?></td><td><?= e(str_limit((string) $f['error_message'], 90)) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="ns-card h-100">
            <div class="ns-card-head"><h2>Low-confidence answers</h2><small class="text-muted">Candidates for new FAQ entries</small></div>
            <?php if (!$lowConfidence): ?>
                <p class="ns-empty mb-0">No low-confidence answers in this period.</p>
            <?php endif; ?>
            <?php foreach ($lowConfidence as $row): ?>
                <a class="ns-lowconf-item" href="<?= e(url('admin/conversation.php?id=' . $row['session_id'])) ?>">
                    <span class="ns-confidence-cell" style="--v:<?= (int) $row['confidence'] ?>"><?= (int) $row['confidence'] ?>%</span>
                    <span><strong><?= e(str_limit((string) $row['question'], 90)) ?></strong><small><?= e(AiEngine::SLOT_LABELS[$row['provider']] ?? '') ?> · <?= e(time_ago($row['created_at'])) ?></small></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script type="application/json" id="nsCharts"><?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
