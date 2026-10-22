<?php
/**
 * GET /api/tickets.php            -> the signed-in customer's tickets (status tracking)
 * GET /api/tickets.php?id=12      -> one ticket with its public replies
 */
define('NS_API', true);
define('NS_PASSIVE', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = Auth::requirePermission('portal.tickets');
$userId = (int) $user['id'];

$id = input_int('id');
if ($id > 0) {
    $ticket = TicketService::find($id);
    if (!$ticket || (int) $ticket['customer_id'] !== $userId) {
        json_error(__('error.not_found'), 404);
    }
    $replies = array_map(static fn ($r) => [
        'author'   => $r['author_name'],
        'is_staff' => $r['author_role'] !== 'customer',
        'body'     => $r['body'],
        'time'     => $r['created_at'],
    ], TicketService::replies($id, false));
    json_response(['ok' => true, 'ticket' => [
        'ticket_no' => $ticket['ticket_no'],
        'subject'   => $ticket['subject'],
        'status'    => $ticket['status'],
        'priority'  => $ticket['priority'],
        'category'  => $ticket['category_name'],
        'assignee'  => $ticket['assignee_name'],
        'created'   => $ticket['created_at'],
        'updated'   => $ticket['updated_at'],
        'replies'   => $replies,
    ]]);
}

$rows = DB::all(
    'SELECT id, ticket_no, subject, status, priority, created_at, updated_at FROM tickets
     WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 100',
    [$userId]
);
json_response(['ok' => true, 'items' => $rows]);
