<?php
/**
 * GET  /api/notifications.php           -> unread count + latest notifications
 * POST /api/notifications.php { action: "read", id } | { action: "read_all" }
 */
define('NS_API', true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    define('NS_PASSIVE', true);
}
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requireLogin();
$userId = (int) $user['id'];

if (is_post()) {
    require_csrf();
    $data = json_input();
    if (($data['action'] ?? '') === 'read_all') {
        DB::run('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userId]);
    } elseif (($data['action'] ?? '') === 'read') {
        DB::run('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [(int) ($data['id'] ?? 0), $userId]);
    } else {
        json_error(__('error.invalid_request'), 400);
    }
    json_response(['ok' => true, 'unread' => Notifier::unreadCount($userId)]);
}

$items = [];
if (input('count_only') !== '1') {
    foreach (Notifier::latest($userId, 12) as $n) {
        $items[] = [
            'id'      => (int) $n['id'],
            'type'    => $n['type'],
            'title'   => $n['title'],
            'body'    => $n['body'],
            'link'    => $n['link'] ? url($n['link']) : null,
            'time'    => time_ago($n['created_at']),
            'is_read' => (bool) $n['is_read'],
        ];
    }
}
json_response(['ok' => true, 'unread' => Notifier::unreadCount($userId), 'items' => $items]);
