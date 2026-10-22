<?php
/**
 * POST /api/escalation.php
 *   Customer:  { action: "request", session_id, reason?, note? }
 *   Staff:     { action: "takeover" | "release" | "resolve", session_id }
 */
define('NS_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    json_error('Method not allowed.', 405);
}
$user = Auth::requireLogin();
require_csrf();

$data = json_input();
$action = (string) ($data['action'] ?? '');
$sessionId = (int) ($data['session_id'] ?? 0);

if ($action === 'request') {
    if (!Auth::can('portal.chat')) {
        json_error(__('error.forbidden'), 403);
    }
    $userId = (int) $user['id'];
    if ($sessionId > 0) {
        $session = ChatService::findForCustomer($sessionId, $userId);
        if (!$session) {
            json_error(__('error.not_found'), 404);
        }
    } else {
        $session = ChatService::activeSessionFor($userId) ?? ChatService::create($userId, current_lang());
    }
    if ($session['status'] === 'closed') {
        json_error(__('chat.error_closed'), 409, ['code' => 'closed']);
    }
    if ($session['status'] === 'human') {
        json_response(['ok' => true, 'already' => true, 'session' => ['id' => (int) $session['id'], 'status' => 'human', 'staff_name' => $session['staff_name']]]);
    }
    if (!$session['title']) {
        DB::run('UPDATE chat_sessions SET title = ? WHERE id = ?', [__('chat.human_request_title'), (int) $session['id']]);
    }
    $reason = in_array($data['reason'] ?? '', ['low_confidence', 'negative_sentiment'], true) ? $data['reason'] : 'customer_request';
    $note = trim(mb_substr((string) ($data['note'] ?? ''), 0, 500)) ?: null;
    EscalationService::open($session, $reason, $note, [], $userId);
    $fresh = ChatService::find((int) $session['id']);
    json_response(['ok' => true, 'session' => ['id' => (int) $fresh['id'], 'status' => $fresh['status'], 'staff_name' => $fresh['staff_name']]]);
}

// ---- Staff actions ----
if (!Auth::can('conversations.takeover')) {
    json_error(__('error.forbidden'), 403);
}
$session = ChatService::find($sessionId);
if (!$session) {
    json_error('Conversation not found.', 404);
}
if ($session['status'] === 'closed') {
    json_error('This conversation is already closed.', 409);
}

switch ($action) {
    case 'takeover':
        EscalationService::takeover($session, $user);
        break;
    case 'release':
        EscalationService::release($session, $user);
        break;
    case 'resolve':
        EscalationService::resolve($session, $user);
        break;
    default:
        json_error('Unknown action.', 400);
}
$fresh = ChatService::find($sessionId);
json_response(['ok' => true, 'session' => ['id' => $sessionId, 'status' => $fresh['status'], 'staff_name' => $fresh['staff_name']]]);
