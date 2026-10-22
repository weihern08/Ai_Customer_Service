<?php
/**
 * GET  /api/messages.php?session_id=1&after_id=10   -> new messages (customer: own sessions, staff: any)
 * POST /api/messages.php { session_id, message }     -> staff live-chat reply (auto take-over)
 */
define('NS_API', true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    define('NS_PASSIVE', true); // polling must not keep idle sessions alive
}
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requireLogin();

if (!is_post()) {
    $sessionId = input_int('session_id');
    $afterId = max(0, input_int('after_id'));
    $isStaffView = Auth::can('conversations.view') && $user['role_slug'] !== 'customer';

    $session = $isStaffView ? ChatService::find($sessionId) : ChatService::findForCustomer($sessionId, (int) $user['id']);
    if (!$session && $user['role_slug'] !== 'customer' && Auth::can('portal.chat')) {
        $session = ChatService::findForCustomer($sessionId, (int) $user['id']);
    }
    if (!$session) {
        json_error(__('error.not_found'), 404);
    }
    $messages = array_map(
        static fn ($m) => ChatService::formatMessage($m, $isStaffView),
        ChatService::messages((int) $session['id'], $afterId)
    );
    json_response([
        'ok'       => true,
        'session'  => [
            'id'             => (int) $session['id'],
            'status'         => $session['status'],
            'staff_name'     => $session['staff_name'],
            'last_sentiment' => $isStaffView ? $session['last_sentiment'] : null,
        ],
        'messages' => $messages,
    ]);
}

// ---- Staff reply ----
if (!Auth::can('conversations.takeover')) {
    json_error(__('error.forbidden'), 403);
}
require_csrf();
$data = json_input();
$session = ChatService::find((int) ($data['session_id'] ?? 0));
if (!$session) {
    json_error('Conversation not found.', 404);
}
if ($session['status'] === 'closed') {
    json_error('This conversation is closed.', 409);
}
$text = trim((string) ($data['message'] ?? ''));
if ($text === '' || mb_strlen($text) > ChatService::MAX_MESSAGE_LENGTH) {
    json_error('Please enter a message (max ' . ChatService::MAX_MESSAGE_LENGTH . ' characters).', 422);
}

if ($session['status'] !== 'human' || (int) $session['assigned_staff_id'] !== (int) $user['id']) {
    EscalationService::takeover($session, $user);
}
$id = ChatService::addMessage((int) $session['id'], 'staff', (int) $user['id'], $text, [
    'language' => $session['language'],
    'provider' => 'human',
]);

json_response(['ok' => true, 'message' => ChatService::formatMessage(ChatService::messageById($id), true)]);
