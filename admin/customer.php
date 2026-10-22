<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$viewer = Auth::requirePermission('customers.view');
$customer = Auth::findUser(input_int('id'));
if (!$customer || $customer['role_slug'] !== 'customer') {
    abort(404);
}
$customerId = (int) $customer['id'];

$tickets = DB::all(
    'SELECT id, ticket_no, subject, status, priority, assigned_to, updated_at FROM tickets WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 20',
    [$customerId]
);
$sessions = DB::all(
    'SELECT id, title, status, last_sentiment, last_intent, last_confidence, last_activity_at, message_count
     FROM chat_sessions WHERE customer_id = ? AND message_count > 0 ORDER BY last_activity_at DESC LIMIT 20',
    [$customerId]
);
$feedback = DB::row(
    "SELECT COUNT(*) AS total, SUM(rating = 'helpful') AS helpful FROM message_feedback WHERE user_id = ?",
    [$customerId]
);
$sentimentMix = DB::all(
    "SELECT m.sentiment, COUNT(*) AS n FROM chat_messages m JOIN chat_sessions s ON s.id = m.session_id
     WHERE s.customer_id = ? AND m.sender_type = 'customer' AND m.sentiment IS NOT NULL GROUP BY m.sentiment",
    [$customerId]
);

$pageTitle = $customer['full_name'];
$pageSubtitle = 'Customer since ' . format_dt($customer['created_at'], 'd M Y');
$activeNav = 'customers';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="ns-card mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="ns-avatar ns-avatar-xl"><?= e(initials($customer['full_name'])) ?></span>
                <div><h2 class="h5 mb-0"><?= e($customer['full_name']) ?></h2><small class="text-muted"><?= e($customer['email']) ?></small></div>
            </div>
            <dl class="ns-dl">
                <dt>Phone</dt><dd><?= e($customer['phone'] ?: '—') ?></dd>
                <dt>Language</dt><dd><?= e(Lang::AVAILABLE[$customer['preferred_language']]['label'] ?? '—') ?></dd>
                <dt>Status</dt><dd><?= badge('account', $customer['account_status']) ?></dd>
                <dt>Joined</dt><dd><?= e(format_dt($customer['created_at'])) ?></dd>
                <dt>Last login</dt><dd><?= e(format_dt($customer['last_login_at'])) ?></dd>
                <dt>AI ratings</dt><dd><?= (int) $feedback['helpful'] ?> helpful / <?= (int) $feedback['total'] ?> total</dd>
            </dl>
            <?php if (Auth::can('users.manage')): ?>
                <a class="btn btn-sm ns-btn-ghost w-100" href="<?= e(url('admin/user_form.php?id=' . $customerId)) ?>"><i class="fa-solid fa-user-pen me-1"></i>Edit account</a>
            <?php endif; ?>
        </div>
        <div class="ns-card">
            <div class="ns-card-head"><h2>Sentiment history</h2></div>
            <?php if (!$sentimentMix): ?><p class="ns-empty mb-0">No chat messages yet.</p><?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($sentimentMix as $s): ?>
                    <span><?= badge('sentiment', $s['sentiment']) ?> × <?= (int) $s['n'] ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Tickets (<?= count($tickets) ?>)</h2></div>
            <?php if (!$tickets): ?><p class="ns-empty mb-0">No tickets.</p><?php else: ?>
                <div class="table-responsive">
                    <table class="table ns-table align-middle">
                        <thead><tr><th>Ticket</th><th>Priority</th><th>Status</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($tickets as $t): ?>
                            <tr>
                                <td>
                                    <?php if (TicketService::canView($t + ['customer_id' => $customerId], $viewer)): ?>
                                        <a class="fw-semibold" href="<?= e(url('admin/ticket.php?id=' . $t['id'])) ?>"><?= e($t['ticket_no']) ?></a>
                                    <?php else: ?>
                                        <span class="fw-semibold"><?= e($t['ticket_no']) ?></span>
                                    <?php endif; ?>
                                    <br><small><?= e(str_limit($t['subject'], 60)) ?></small>
                                </td>
                                <td><?= badge('priority', $t['priority']) ?></td>
                                <td><?= badge('status', $t['status']) ?></td>
                                <td class="small"><?= e(time_ago($t['updated_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="ns-card">
            <div class="ns-card-head"><h2>AI conversations (<?= count($sessions) ?>)</h2></div>
            <?php if (!$sessions): ?><p class="ns-empty mb-0">No conversations.</p><?php else: ?>
                <div class="table-responsive">
                    <table class="table ns-table align-middle">
                        <thead><tr><th>Topic</th><th>Intent</th><th>Sentiment</th><th>Confidence</th><th>Status</th><th>Last activity</th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $s): ?>
                            <tr>
                                <td><a href="<?= e(url('admin/conversation.php?id=' . $s['id'])) ?>"><?= e(str_limit((string) $s['title'], 50)) ?></a><br><small class="text-muted"><?= (int) $s['message_count'] ?> messages</small></td>
                                <td class="small"><?= e(TextAnalyzer::INTENTS[$s['last_intent']] ?? '—') ?></td>
                                <td><?= badge('sentiment', $s['last_sentiment']) ?></td>
                                <td><?= $s['last_confidence'] !== null ? (int) $s['last_confidence'] . '%' : '—' ?></td>
                                <td><?= badge('chat', $s['status']) ?></td>
                                <td class="small"><?= e(time_ago($s['last_activity_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
