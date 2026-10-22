<?php
/**
 * POST /api/chat.php
 *   { action: "send", session_id, message, lang }  -> customer message + AI reply
 *   { action: "new" }                               -> archive the current conversation
 */
define('NS_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    json_error('Method not allowed.', 405);
}
$user = Auth::requirePermission('portal.chat');
require_csrf();

$data = json_input();
$action = (string) ($data['action'] ?? 'send');
$userId = (int) $user['id'];

if ($action === 'new') {
    if ($active = ChatService::activeSessionFor($userId)) {
        if ((int) $active['message_count'] === 0) {
            DB::run('DELETE FROM chat_sessions WHERE id = ?', [(int) $active['id']]);
        } else {
            ChatService::close($active, ChatService::inferResolution($active));
        }
    }
    json_response(['ok' => true, 'session' => ['id' => 0, 'status' => 'active', 'staff_name' => null]]);
}

if ($action !== 'send') {
    json_error(__('error.invalid_request'), 400);
}

$text = trim((string) ($data['message'] ?? ''));
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
if ($text === '') {
    json_error(__('chat.error_empty'), 422);
}
if (mb_strlen($text) > ChatService::MAX_MESSAGE_LENGTH) {
    json_error(__('chat.error_long', ['n' => ChatService::MAX_MESSAGE_LENGTH]), 422);
}

// Basic flood protection: at most 15 messages per minute per customer
$recent = (int) DB::value(
    "SELECT COUNT(*) FROM chat_messages m JOIN chat_sessions s ON s.id = m.session_id
     WHERE s.customer_id = ? AND m.sender_type = 'customer' AND m.created_at > (NOW() - INTERVAL 1 MINUTE)",
    [$userId]
);
if ($recent >= 15) {
    json_error(__('chat.error_rate'), 429);
}

$sessionId = (int) ($data['session_id'] ?? 0);
if ($sessionId > 0) {
    $session = ChatService::findForCustomer($sessionId, $userId);
    if (!$session) {
        json_error(__('error.not_found'), 404);
    }
    if ($session['status'] === 'closed') {
        json_error(__('chat.error_closed'), 409, ['code' => 'closed']);
    }
} else {
    $lang = (string) ($data['lang'] ?? current_lang());
    $session = ChatService::activeSessionFor($userId) ?? ChatService::create($userId, isset(Lang::AVAILABLE[$lang]) ? $lang : 'en');
}

$uiLang = (string) ($data['lang'] ?? current_lang());
if (!isset(Lang::AVAILABLE[$uiLang])) {
    $uiLang = current_lang();
}

@set_time_limit(90);
$result = ChatService::handleCustomerMessage($session, $user, $text, $uiLang);
$fresh = ChatService::find((int) $session['id']);

json_response([
    'ok'      => true,
    'session' => [
        'id'         => (int) $fresh['id'],
        'status'     => $fresh['status'],
        'staff_name' => $fresh['staff_name'],
    ],
] + $result);
