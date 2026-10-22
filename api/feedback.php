<?php
/**
 * POST /api/feedback.php { message_id, rating: "helpful"|"not_helpful", comment? }
 */
define('NS_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    json_error('Method not allowed.', 405);
}
$user = Auth::requirePermission('portal.feedback');
require_csrf();

$data = json_input();
$messageId = (int) ($data['message_id'] ?? 0);
$rating = (string) ($data['rating'] ?? '');
$comment = trim(mb_substr((string) ($data['comment'] ?? ''), 0, 500));

if (!in_array($rating, ['helpful', 'not_helpful'], true)) {
    json_error(__('error.invalid_request'), 422);
}

// The message must be an AI reply inside one of this customer's conversations
$message = DB::row(
    "SELECT m.id, m.session_id FROM chat_messages m
     JOIN chat_sessions s ON s.id = m.session_id
     WHERE m.id = ? AND m.sender_type = 'ai' AND s.customer_id = ?",
    [$messageId, (int) $user['id']]
);
if (!$message) {
    json_error(__('error.not_found'), 404);
}

DB::run(
    'INSERT INTO message_feedback (message_id, session_id, user_id, rating, comment)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = COALESCE(VALUES(comment), comment)',
    [$messageId, (int) $message['session_id'], (int) $user['id'], $rating, $comment !== '' ? $comment : null]
);

json_response(['ok' => true, 'rating' => $rating, 'message' => __('chat.feedback_thanks')]);
