<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('conversations.view');
$session = ChatService::find(input_int('id'));
if (!$session) {
    abort(404);
}

$messages = array_map(static fn ($m) => ChatService::formatMessage($m, true), ChatService::messages((int) $session['id']));
$escalations = DB::all(
    'SELECT e.*, u.full_name AS handler_name FROM escalations e LEFT JOIN users u ON u.id = e.handled_by
     WHERE e.session_id = ? ORDER BY e.id DESC',
    [(int) $session['id']]
);
$customerStats = DB::row(
    "SELECT (SELECT COUNT(*) FROM tickets WHERE customer_id = ?) AS tickets,
            (SELECT COUNT(*) FROM chat_sessions WHERE customer_id = ? AND message_count > 0) AS conversations",
    [(int) $session['customer_id'], (int) $session['customer_id']]
);
$linkedTickets = DB::all('SELECT id, ticket_no, status FROM tickets WHERE chat_session_id = ?', [(int) $session['id']]);
$canTakeover = Auth::can('conversations.takeover');
$isMine = $session['status'] === 'human' && (int) $session['assigned_staff_id'] === (int) $user['id'];

$liveData = [
    'sessionId' => (int) $session['id'],
    'status'    => $session['status'],
    'staffName' => $session['staff_name'],
    'isMine'    => $isMine,
    'canAct'    => $canTakeover,
    'messages'  => $messages,
    'urls'      => ['messages' => url('api/messages.php'), 'escalation' => url('api/escalation.php')],
];

$pageTitle = 'Conversation #' . $session['id'];
$pageSubtitle = $session['customer_name'] . ' · started ' . format_dt($session['created_at']);
$activeNav = 'conversations';
$pageScripts = ['js/staff-chat.js'];
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="ns-card ns-staff-chat p-0">
            <div class="ns-staff-chat-head">
                <div>
                    <strong><?= e((string) $session['title'] ?: 'Untitled conversation') ?></strong>
                    <div class="small text-muted" data-ns-live-status></div>
                </div>
                <?php if ($canTakeover): ?>
                    <div class="d-flex flex-wrap gap-2" data-ns-staff-actions>
                        <button class="btn btn-sm ns-btn-primary" type="button" data-ns-action="takeover"><i class="fa-solid fa-headset me-1"></i>Take over</button>
                        <button class="btn btn-sm ns-btn-ghost" type="button" data-ns-action="release" data-confirm="Hand this conversation back to the AI assistant?"><i class="fa-solid fa-robot me-1"></i>Return to AI</button>
                        <button class="btn btn-sm btn-outline-success" type="button" data-ns-action="resolve" data-confirm="Mark this conversation as resolved and close it?"><i class="fa-solid fa-circle-check me-1"></i>Resolve &amp; close</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ns-staff-transcript" data-ns-transcript role="log" aria-live="polite"></div>
            <?php if ($canTakeover): ?>
                <form class="ns-staff-composer" data-ns-staff-composer>
                    <label class="visually-hidden" for="staffReply">Reply to customer</label>
                    <textarea id="staffReply" rows="2" maxlength="<?= ChatService::MAX_MESSAGE_LENGTH ?>" placeholder="Type a reply to the customer… (sending a reply takes over the conversation)"></textarea>
                    <button class="btn ns-btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i><span class="d-none d-sm-inline ms-2">Send</span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Customer</h2><?php if (Auth::can('customers.view')): ?><a href="<?= e(url('admin/customer.php?id=' . $session['customer_id'])) ?>">Profile</a><?php endif; ?></div>
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="ns-avatar"><?= e(initials($session['customer_name'])) ?></span>
                <div><strong><?= e($session['customer_name']) ?></strong><br><small class="text-muted"><?= e($session['customer_email']) ?></small></div>
            </div>
            <dl class="ns-dl">
                <dt>Phone</dt><dd><?= e($session['customer_phone'] ?: '—') ?></dd>
                <dt>Tickets</dt><dd><?= (int) $customerStats['tickets'] ?></dd>
                <dt>Conversations</dt><dd><?= (int) $customerStats['conversations'] ?></dd>
            </dl>
        </div>
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Conversation insight</h2></div>
            <dl class="ns-dl">
                <dt>Status</dt><dd><?= badge('chat', $session['status']) ?></dd>
                <dt>Language</dt><dd><?= e(Lang::AVAILABLE[$session['language']]['label'] ?? $session['language']) ?></dd>
                <dt>Last intent</dt><dd><?= e(TextAnalyzer::INTENTS[$session['last_intent']] ?? '—') ?></dd>
                <dt>Last sentiment</dt><dd><?= badge('sentiment', $session['last_sentiment']) ?></dd>
                <dt>Last confidence</dt><dd><?= $session['last_confidence'] !== null ? (int) $session['last_confidence'] . '%' : '—' ?></dd>
                <dt>AI provider</dt><dd><?= e(AiEngine::SLOT_LABELS[$session['last_provider']] ?? '—') ?></dd>
                <dt>Assigned agent</dt><dd><?= e($session['staff_name'] ?? '—') ?></dd>
                <dt>Resolution</dt><dd><?= e($session['resolution'] ? ucfirst($session['resolution']) : '—') ?></dd>
                <dt>Last activity</dt><dd><?= e(format_dt($session['last_activity_at'])) ?></dd>
            </dl>
            <?php foreach ($linkedTickets as $t): ?>
                <a class="btn btn-sm ns-btn-ghost w-100 mt-2" href="<?= e(url('admin/ticket.php?id=' . $t['id'])) ?>"><i class="fa-solid fa-ticket me-1"></i><?= e($t['ticket_no']) ?> (<?= e(str_replace('_', ' ', $t['status'])) ?>)</a>
            <?php endforeach; ?>
        </div>
        <div class="ns-card">
            <div class="ns-card-head"><h2>Escalation history</h2></div>
            <?php if (!$escalations): ?><p class="ns-empty mb-0">This conversation was never escalated.</p><?php endif; ?>
            <?php foreach ($escalations as $esc): ?>
                <div class="ns-escalation-item">
                    <div class="d-flex justify-content-between"><strong><?= e(EscalationService::REASONS[$esc['reason']] ?? $esc['reason']) ?></strong><?= badge('escalation', $esc['status']) ?></div>
                    <?php if ($esc['note']): ?><p class="small mb-1"><?= e($esc['note']) ?></p><?php endif; ?>
                    <small class="text-muted"><?= e(format_dt($esc['created_at'])) ?><?= $esc['handler_name'] ? ' · ' . e($esc['handler_name']) : '' ?> · confidence <?= $esc['confidence'] !== null ? (int) $esc['confidence'] . '%' : '—' ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script type="application/json" id="nsLiveChat"><?= json_encode($liveData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
