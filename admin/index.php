<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('dashboard.view');
$showAnalytics = Auth::can('analytics.view');

$pageTitle = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . $user['full_name'] . '. Here is what is happening today.';
$activeNav = 'dashboard';

if ($showAnalytics) {
    $kpis = Analytics::kpis();
    $ai = Analytics::aiPerformance();
    $intents = Analytics::intents();
    $sentiments = Analytics::sentiments();
    $ticketStatuses = Analytics::ticketStatuses();
    $volume = Analytics::dailyVolume(14);
    $recentEscalations = DB::all(
        "SELECT e.*, u.full_name AS customer_name FROM escalations e JOIN users u ON u.id = e.customer_id
         WHERE e.status <> 'resolved' ORDER BY e.created_at DESC LIMIT 6"
    );
    $recentTickets = DB::all(
        'SELECT t.id, t.ticket_no, t.subject, t.status, t.priority, t.updated_at, u.full_name AS customer_name
         FROM tickets t JOIN users u ON u.id = t.customer_id ORDER BY t.updated_at DESC LIMIT 6'
    );
    $charts = [
        'intents' => [
            'type' => 'bar', 'horizontal' => true,
            'labels' => array_keys($intents), 'data' => array_values($intents), 'label' => 'Customer messages',
        ],
        'sentiment' => [
            'type' => 'doughnut',
            'labels' => array_map('ucfirst', array_keys($sentiments)), 'data' => array_values($sentiments),
            'colors' => ['#16a34a', '#94a3b8', '#f59e0b', '#ef4444'],
        ],
        'tickets' => [
            'type' => 'bar',
            'labels' => array_map(static fn ($s) => ucwords(str_replace('_', ' ', $s)), array_keys($ticketStatuses)),
            'data' => array_values($ticketStatuses), 'label' => 'Tickets',
            'colors' => ['#0ea5e9', '#f59e0b', '#6b4eff', '#16a34a', '#64748b'],
        ],
        'volume' => [
            'type' => 'line', 'labels' => $volume['labels'],
            'series' => [
                ['label' => 'Customer messages', 'data' => $volume['customer'], 'color' => '#0fb5a6'],
                ['label' => 'AI replies', 'data' => $volume['ai'], 'color' => '#6b4eff'],
                ['label' => 'Staff replies', 'data' => $volume['staff'], 'color' => '#ff6f59'],
            ],
        ],
        'aiPerformance' => [
            'type' => 'bar',
            'labels' => ['AI conversations', 'Human escalations', 'AI resolved', 'Fallback answers'],
            'data' => [$ai['conversations'], $ai['escalations'], $ai['ai_resolved'], $ai['fallback_messages']],
            'label' => 'Count', 'colors' => ['#6b4eff', '#ff6f59', '#16a34a', '#f59e0b'],
        ],
    ];
    $useCharts = true;
} else {
    $content = DB::row(
        "SELECT (SELECT COUNT(*) FROM kb_articles WHERE status = 'published') AS published,
                (SELECT COUNT(*) FROM kb_articles WHERE status = 'draft') AS drafts,
                (SELECT COUNT(*) FROM faq_entries WHERE is_active = 1) AS faqs,
                (SELECT COUNT(*) FROM content_categories WHERE is_active = 1) AS categories"
    );
    $topArticles = DB::all('SELECT title, slug, view_count, status FROM kb_articles ORDER BY view_count DESC LIMIT 6');
    $topFaqs = DB::all('SELECT id, question, view_count FROM faq_entries ORDER BY view_count DESC LIMIT 6');
}

require dirname(__DIR__) . '/includes/layout/admin_header.php';

$kpi = static function (string $icon, string $tone, string $label, int|string $value, string $href = '') {
    $tag = $href ? 'a href="' . e(url($href)) . '"' : 'div';
    $close = $href ? 'a' : 'div';
    echo "<div class=\"col-6 col-xl-3\"><$tag class=\"ns-kpi ns-kpi-" . e($tone) . '"><span class="ns-kpi-icon"><i class="fa-solid ' . e($icon) . '"></i></span>'
        . '<div><span class="ns-kpi-label">' . e($label) . '</span><strong class="ns-kpi-value">' . e((string) $value) . "</strong></div></$close></div>";
};
?>
<?php if ($showAnalytics): ?>
    <div class="row g-3 mb-4">
        <?php
        $kpi('fa-users', 'teal', 'Total customers', $kpis['customers'], Auth::can('customers.view') ? 'admin/customers.php' : '');
        $kpi('fa-comments', 'violet', 'Active conversations', $kpis['active_conversations'], 'admin/conversations.php?status=open');
        $kpi('fa-ticket', 'amber', 'Open tickets', $kpis['open_tickets'], 'admin/tickets.php?status=active');
        $kpi('fa-hand-holding-heart', 'coral', 'Escalated conversations', $kpis['escalated'], 'admin/escalations.php');
        $kpi('fa-robot', 'violet', 'AI resolved', $kpis['ai_resolved']);
        $kpi('fa-headset', 'teal', 'Human resolved', $kpis['human_resolved']);
        $kpi('fa-book-open', 'sky', 'Knowledge articles', $kpis['articles']);
        $kpi('fa-circle-question', 'amber', 'FAQ entries', $kpis['faqs']);
        ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Conversation volume <small>last 14 days</small></h2></div>
                <div class="ns-chart ns-chart-lg"><canvas data-ns-chart="volume" aria-label="Conversation volume chart" role="img"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>AI performance</h2><a href="<?= e(url('admin/ai_monitor.php')) ?>">Details</a></div>
                <div class="ns-metric-grid">
                    <div><span>AI resolution rate</span><strong><?= e((string) $ai['resolution_rate']) ?>%</strong></div>
                    <div><span>Average confidence</span><strong><?= e((string) $ai['avg_confidence']) ?>%</strong></div>
                    <div><span>Helpful responses</span><strong><?= e((string) $ai['helpful_rate']) ?>%</strong></div>
                    <div><span>Escalation rate</span><strong><?= e((string) $ai['escalation_rate']) ?>%</strong></div>
                </div>
                <div class="ns-chart ns-chart-sm"><canvas data-ns-chart="aiPerformance" aria-label="AI performance chart" role="img"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Intent distribution</h2></div>
                <?php if (!$intents): ?><p class="ns-empty">No customer messages yet.</p><?php endif; ?>
                <div class="ns-chart"><canvas data-ns-chart="intents" aria-label="Intent distribution chart" role="img"></canvas></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Sentiment</h2></div>
                <div class="ns-chart"><canvas data-ns-chart="sentiment" aria-label="Sentiment chart" role="img"></canvas></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Ticket status</h2></div>
                <div class="ns-chart"><canvas data-ns-chart="tickets" aria-label="Ticket status chart" role="img"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Waiting for a human</h2><a href="<?= e(url('admin/escalations.php')) ?>">View queue</a></div>
                <?php if (!$recentEscalations): ?>
                    <div class="ns-empty-state ns-empty-state-sm"><i class="fa-regular fa-face-smile"></i><p class="mb-0">No open escalations. Nice work!</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table ns-table align-middle">
                            <thead><tr><th>Customer</th><th>Reason</th><th>Sentiment</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($recentEscalations as $esc): ?>
                                <tr>
                                    <td><strong><?= e($esc['customer_name']) ?></strong><br><small class="text-muted"><?= e(time_ago($esc['created_at'])) ?></small></td>
                                    <td><?= e(EscalationService::REASONS[$esc['reason']] ?? $esc['reason']) ?></td>
                                    <td><?= badge('sentiment', $esc['sentiment']) ?></td>
                                    <td><?= badge('escalation', $esc['status']) ?></td>
                                    <td class="text-end"><a class="btn btn-sm ns-btn-ghost" href="<?= e(url('admin/conversation.php?id=' . $esc['session_id'])) ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Recently updated tickets</h2><a href="<?= e(url('admin/tickets.php')) ?>">All tickets</a></div>
                <div class="table-responsive">
                    <table class="table ns-table align-middle">
                        <thead><tr><th>Ticket</th><th>Customer</th><th>Priority</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentTickets as $t): ?>
                            <tr>
                                <td><a href="<?= e(url('admin/ticket.php?id=' . $t['id'])) ?>" class="fw-semibold"><?= e($t['ticket_no']) ?></a><br><small class="text-muted"><?= e(str_limit($t['subject'], 40)) ?></small></td>
                                <td><?= e($t['customer_name']) ?></td>
                                <td><?= badge('priority', $t['priority']) ?></td>
                                <td><?= badge('status', $t['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="nsCharts"><?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php
        $kpi('fa-book-open', 'teal', 'Published articles', (int) $content['published'], 'admin/articles.php');
        $kpi('fa-pen-ruler', 'amber', 'Draft articles', (int) $content['drafts'], 'admin/articles.php?status=draft');
        $kpi('fa-circle-question', 'violet', 'Active FAQs', (int) $content['faqs'], 'admin/faqs.php');
        $kpi('fa-layer-group', 'coral', 'Categories', (int) $content['categories'], 'admin/categories.php');
        ?>
    </div>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Most viewed articles</h2><a href="<?= e(url('admin/articles.php')) ?>">Manage</a></div>
                <ul class="ns-rank-list">
                    <?php foreach ($topArticles as $a): ?>
                        <li><a href="<?= e(url('article.php?slug=' . rawurlencode($a['slug']))) ?>" target="_blank" rel="noopener"><?= e($a['title']) ?></a> <?= $a['status'] === 'draft' ? badge('content', 'draft') : '' ?><span><?= (int) $a['view_count'] ?> views</span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="ns-card h-100">
                <div class="ns-card-head"><h2>Most viewed FAQs</h2><a href="<?= e(url('admin/faqs.php')) ?>">Manage</a></div>
                <ul class="ns-rank-list">
                    <?php foreach ($topFaqs as $f): ?>
                        <li><a href="<?= e(url('admin/faq_form.php?id=' . $f['id'])) ?>"><?= e($f['question']) ?></a><span><?= (int) $f['view_count'] ?> views</span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
