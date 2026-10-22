<?php
require __DIR__ . '/includes/bootstrap.php';

$user = Auth::requirePermission('portal.chat');
$assistant = (string) Settings::get('ai_assistant_name', 'Nova');

$requestedId = input_int('session');
if ($requestedId > 0) {
    $session = ChatService::findForCustomer($requestedId, (int) $user['id']);
    if (!$session) {
        abort(404);
    }
} else {
    $session = ChatService::activeSessionFor((int) $user['id']);
}

$messages = $session ? array_map(static fn ($m) => ChatService::formatMessage($m), ChatService::messages((int) $session['id'])) : [];
$history = ChatService::listForCustomer((int) $user['id']);
$suggestions = [
    __('chat.suggest.plans'), __('chat.suggest.slow'), __('chat.suggest.password'),
    __('chat.suggest.refund'), __('chat.suggest.hours'), __('chat.suggest.cancel'),
];

$chatData = [
    'session' => $session ? [
        'id'         => (int) $session['id'],
        'status'     => $session['status'],
        'staff_name' => $session['staff_name'],
    ] : ['id' => 0, 'status' => 'active', 'staff_name' => null],
    'messages'   => $messages,
    'assistant'  => $assistant,
    'userName'   => $user['full_name'],
    'prefill'    => mb_substr((string) input('q'), 0, 300),
    'threshold'  => (int) Settings::get('ai_confidence_threshold', 60),
    'maxLength'  => ChatService::MAX_MESSAGE_LENGTH,
    'urls'       => [
        'chat'       => url('api/chat.php'),
        'messages'   => url('api/messages.php'),
        'feedback'   => url('api/feedback.php'),
        'escalation' => url('api/escalation.php'),
        'base'       => base_url(),
    ],
];

$pageTitle = __('chat.title', ['name' => $assistant]);
$activeNav = 'chat';
$bodyClass = 'ns-chat-page';
$hideFooter = true;
$pageScripts = ['js/voice.js', 'js/chat.js'];
require __DIR__ . '/includes/layout/portal_header.php';
?>
<section class="ns-chat-app">
    <aside class="ns-chat-sidebar offcanvas-lg offcanvas-start" id="chatSidebar" tabindex="-1" aria-labelledby="chatSidebarLabel">
        <div class="offcanvas-header d-lg-none">
            <h2 class="offcanvas-title h5" id="chatSidebarLabel"><?= e(__('chat.sidebar')) ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#chatSidebar" aria-label="<?= e(__('common.close')) ?>"></button>
        </div>
        <div class="ns-chat-sidebar-body">
            <button class="btn ns-btn-primary w-100 mb-4" type="button" data-ns-new-chat>
                <i class="fa-solid fa-plus me-2"></i><?= e(__('chat.new')) ?>
            </button>

            <h3 class="ns-side-title"><?= e(__('chat.suggested')) ?></h3>
            <div class="ns-suggestions mb-4">
                <?php foreach ($suggestions as $suggestion): ?>
                    <button type="button" class="ns-suggestion" data-ns-suggestion="<?= e($suggestion) ?>"><i class="fa-regular fa-lightbulb"></i><?= e($suggestion) ?></button>
                <?php endforeach; ?>
            </div>

            <h3 class="ns-side-title"><?= e(__('chat.history')) ?></h3>
            <div class="ns-history mb-4">
                <?php if (!$history): ?>
                    <p class="text-muted small mb-0"><?= e(__('chat.history_empty')) ?></p>
                <?php endif; ?>
                <?php foreach ($history as $item): ?>
                    <a class="ns-history-item <?= $session && (int) $session['id'] === (int) $item['id'] ? 'active' : '' ?>" href="<?= e(url('chat.php?session=' . $item['id'])) ?>">
                        <span class="text-truncate"><?= e($item['title'] ?: __('chat.untitled')) ?></span>
                        <small><?= e(time_ago($item['last_activity_at'])) ?> · <?= e(__('chat.state.' . $item['status'])) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>

            <h3 class="ns-side-title"><?= e(__('voice.title')) ?></h3>
            <div class="ns-voice-panel" data-ns-voice-panel>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="autoSpeak" data-ns-auto-speak>
                    <label class="form-check-label small" for="autoSpeak"><?= e(__('voice.auto_read')) ?></label>
                </div>
                <label class="form-label small mb-1" for="voiceSelect"><?= e(__('voice.voice')) ?></label>
                <select class="form-select form-select-sm mb-2" id="voiceSelect" data-ns-voice-select></select>
                <label class="form-label small mb-1" for="voiceRate"><?= e(__('voice.rate')) ?> <span data-ns-rate-label>1.0×</span></label>
                <input type="range" class="form-range" id="voiceRate" min="0.6" max="1.6" step="0.1" value="1" data-ns-voice-rate>
                <p class="ns-voice-note" data-ns-voice-support><i class="fa-solid fa-circle-info me-1"></i><?= e(__('voice.support_note')) ?></p>
            </div>
        </div>
    </aside>

    <div class="ns-chat-main">
        <header class="ns-chat-header">
            <button class="btn ns-icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#chatSidebar" aria-controls="chatSidebar" aria-label="<?= e(__('chat.sidebar')) ?>">
                <i class="fa-solid fa-bars-staggered"></i>
            </button>
            <span class="ns-ai-avatar" data-ns-chat-avatar><i class="fa-solid fa-star-of-life"></i></span>
            <div class="ns-chat-identity">
                <strong data-ns-chat-name><?= e($assistant) ?></strong>
                <span class="ns-chat-status" data-ns-chat-status></span>
            </div>
            <div class="ns-chat-actions">
                <button class="btn ns-btn-human" type="button" data-ns-escalate>
                    <i class="fa-solid fa-headset"></i><span class="d-none d-sm-inline ms-2"><?= e(__('chat.talk_human')) ?></span>
                </button>
                <div class="dropdown">
                    <button class="btn ns-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e(__('chat.more')) ?>">
                        <i class="fa-solid fa-ellipsis-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end ns-dropdown">
                        <li><button class="dropdown-item" type="button" data-ns-new-chat><i class="fa-solid fa-broom me-2"></i><?= e(__('chat.clear')) ?></button></li>
                        <li><a class="dropdown-item" href="#" data-ns-ticket-from-chat><i class="fa-solid fa-ticket me-2"></i><?= e(__('chat.create_ticket')) ?></a></li>
                        <li><button class="dropdown-item" type="button" data-ns-stop-speaking><i class="fa-solid fa-volume-xmark me-2"></i><?= e(__('voice.stop')) ?></button></li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="ns-chat-banner" data-ns-chat-banner hidden></div>

        <div class="ns-messages" data-ns-messages role="log" aria-live="polite" aria-label="<?= e(__('chat.transcript')) ?>"></div>

        <form class="ns-composer" data-ns-composer autocomplete="off">
            <div class="ns-composer-box">
                <label class="visually-hidden" for="chatInput"><?= e(__('chat.input_label')) ?></label>
                <textarea id="chatInput" rows="1" maxlength="<?= ChatService::MAX_MESSAGE_LENGTH ?>" placeholder="<?= e(__('chat.placeholder', ['name' => $assistant])) ?>" data-ns-input></textarea>
                <button class="btn ns-mic-btn" type="button" data-ns-mic aria-label="<?= e(__('voice.speak')) ?>" title="<?= e(__('voice.speak')) ?>">
                    <i class="fa-solid fa-microphone"></i>
                </button>
                <button class="btn ns-send-btn" type="submit" data-ns-send aria-label="<?= e(__('chat.send')) ?>">
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </div>
            <div class="ns-composer-hint">
                <span data-ns-voice-status><?= e(__('chat.hint')) ?></span>
                <span data-ns-counter>0/<?= ChatService::MAX_MESSAGE_LENGTH ?></span>
            </div>
        </form>
    </div>
</section>
<script type="application/json" id="nsChatData"><?= json_encode($chatData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require __DIR__ . '/includes/layout/portal_footer.php'; ?>
