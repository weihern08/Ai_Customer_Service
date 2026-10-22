<?php
declare(strict_types=1);

/**
 * Support tickets: creation, numbering, replies, internal notes and workflow updates.
 */
final class TicketService
{
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    public const STATUSES = ['open', 'pending', 'in_progress', 'resolved', 'closed'];
    public const ACTIVE_STATUSES = ['open', 'pending', 'in_progress'];

    /** Ticket numbers look like NS-202610-000042 (prefix, year+month, zero-padded database id). */
    public static function formatNumber(int $id, ?string $yearMonth = null): string
    {
        return sprintf('NS-%s-%06d', $yearMonth ?? date('Ym'), $id);
    }

    public static function create(array $customer, array $data, array $files): array
    {
        return DB::transaction(static function () use ($customer, $data, $files) {
            $id = DB::insert('tickets', [
                'ticket_no'       => 'TMP-' . bin2hex(random_bytes(8)),
                'customer_id'     => (int) $customer['id'],
                'category_id'     => $data['category_id'] ?: null,
                'subject'         => $data['subject'],
                'description'     => $data['description'],
                'priority'        => $data['priority'],
                'chat_session_id' => $data['chat_session_id'] ?? null,
            ]);
            $number = self::formatNumber($id);
            DB::update('tickets', ['ticket_no' => $number], 'id = ?', [$id]);
            if ($files) {
                FileUploader::store($files, $id, null, (int) $customer['id']);
            }
            Notifier::toSupportTeam(
                'ticket_new',
                ($data['priority'] === 'urgent' ? 'New urgent ticket ' : 'New ticket ') . $number,
                $customer['full_name'] . ': ' . str_limit($data['subject'], 100),
                'admin/ticket.php?id=' . $id
            );
            AuditLog::record('ticket_created', "Created ticket $number", (int) $customer['id']);
            return ['id' => $id, 'ticket_no' => $number];
        });
    }

    public static function find(int $id): ?array
    {
        return DB::row(
            'SELECT t.*, c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                    a.full_name AS assignee_name, cat.name AS category_name
             FROM tickets t
             JOIN users c ON c.id = t.customer_id
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN content_categories cat ON cat.id = t.category_id
             WHERE t.id = ?',
            [$id]
        );
    }

    /**
     * Customers see their own tickets. Support staff see tickets assigned to them and the
     * unassigned queue. Super admins see everything.
     */
    public static function canView(array $ticket, array $user): bool
    {
        if ($user['role_slug'] === 'customer') {
            return (int) $ticket['customer_id'] === (int) $user['id'];
        }
        if (Auth::can('tickets.view_all')) {
            return true;
        }
        return Auth::can('tickets.view')
            && ($ticket['assigned_to'] === null || (int) $ticket['assigned_to'] === (int) $user['id']);
    }

    public static function replies(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT r.*, u.full_name AS author_name, ro.slug AS author_role
                FROM ticket_replies r
                LEFT JOIN users u ON u.id = r.author_id
                LEFT JOIN roles ro ON ro.id = u.role_id
                WHERE r.ticket_id = ?' . ($includeInternal ? '' : ' AND r.is_internal = 0') . ' ORDER BY r.id ASC';
        return DB::all($sql, [$ticketId]);
    }

    /** Attachments grouped by reply id (key 0 = attached to the original ticket). */
    public static function filesByReply(int $ticketId): array
    {
        $grouped = [];
        foreach (DB::all('SELECT * FROM ticket_files WHERE ticket_id = ? ORDER BY id', [$ticketId]) as $file) {
            $grouped[(int) ($file['reply_id'] ?? 0)][] = $file;
        }
        return $grouped;
    }

    public static function addReply(array $ticket, array $author, string $body, bool $internal, array $files): int
    {
        return DB::transaction(static function () use ($ticket, $author, $body, $internal, $files) {
            $ticketId = (int) $ticket['id'];
            $isCustomer = $author['role_slug'] === 'customer';
            $internal = $internal && !$isCustomer; // customers can never post internal notes

            $replyId = DB::insert('ticket_replies', [
                'ticket_id'   => $ticketId,
                'author_id'   => (int) $author['id'],
                'body'        => $body,
                'is_internal' => $internal ? 1 : 0,
            ]);
            if ($files) {
                FileUploader::store($files, $ticketId, $replyId, (int) $author['id']);
            }

            $newStatus = $ticket['status'];
            if ($isCustomer && in_array($ticket['status'], ['pending', 'resolved'], true)) {
                $newStatus = 'open';
            } elseif (!$isCustomer && !$internal && $ticket['status'] === 'open') {
                $newStatus = 'in_progress';
            }
            DB::run('UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?', [$newStatus, $ticketId]);

            if ($isCustomer) {
                $link = 'admin/ticket.php?id=' . $ticketId;
                $title = 'Customer replied on ' . $ticket['ticket_no'];
                if ($ticket['assigned_to']) {
                    Notifier::toUser((int) $ticket['assigned_to'], 'ticket_reply', $title, str_limit($body, 120), $link);
                } else {
                    Notifier::toSupportTeam('ticket_reply', $title, str_limit($body, 120), $link);
                }
            } elseif (!$internal) {
                $customerLang = (string) (DB::value('SELECT preferred_language FROM users WHERE id = ?', [(int) $ticket['customer_id']]) ?: 'en');
                Notifier::toUser(
                    (int) $ticket['customer_id'],
                    'ticket_reply',
                    Lang::get('notify.ticket_reply', ['no' => $ticket['ticket_no']], $customerLang),
                    str_limit($ticket['subject'], 100),
                    'ticket_view.php?id=' . $ticketId
                );
            }
            AuditLog::record('ticket_replied', ($internal ? 'Internal note on ' : 'Reply on ') . $ticket['ticket_no'], (int) $author['id']);
            return $replyId;
        });
    }

    /**
     * Staff workflow update (status, priority, assignment). Returns a list of human-readable changes.
     */
    public static function updateWorkflow(array $ticket, array $staff, string $status, string $priority, ?int $assignTo): array
    {
        $changes = [];
        $data = [];
        if ($status !== $ticket['status'] && in_array($status, self::STATUSES, true)) {
            $data['status'] = $status;
            $data['closed_at'] = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null;
            $changes[] = "status {$ticket['status']} → $status";
        }
        if ($priority !== $ticket['priority'] && in_array($priority, self::PRIORITIES, true)) {
            $data['priority'] = $priority;
            $changes[] = "priority {$ticket['priority']} → $priority";
        }
        $currentAssignee = $ticket['assigned_to'] !== null ? (int) $ticket['assigned_to'] : null;
        if ($assignTo !== $currentAssignee) {
            $data['assigned_to'] = $assignTo;
            $changes[] = $assignTo ? 'assigned to user #' . $assignTo : 'unassigned';
        }
        if (!$data) {
            return [];
        }
        DB::update('tickets', $data, 'id = ?', [(int) $ticket['id']]);

        if (array_key_exists('assigned_to', $data)) {
            AuditLog::record('ticket_assigned', "{$ticket['ticket_no']} " . ($assignTo ? "assigned to user #$assignTo" : 'unassigned'), (int) $staff['id']);
            if ($assignTo && $assignTo !== (int) $staff['id']) {
                Notifier::toUser($assignTo, 'ticket_assigned', 'Ticket assigned to you: ' . $ticket['ticket_no'], str_limit($ticket['subject'], 100), 'admin/ticket.php?id=' . $ticket['id']);
            }
        }
        if (isset($data['status'])) {
            $customerLang = (string) (DB::value('SELECT preferred_language FROM users WHERE id = ?', [(int) $ticket['customer_id']]) ?: 'en');
            Notifier::toUser(
                (int) $ticket['customer_id'],
                'ticket_status',
                Lang::get('notify.ticket_status', ['no' => $ticket['ticket_no'], 'status' => Lang::get('status.' . $status, [], $customerLang)], $customerLang),
                str_limit($ticket['subject'], 100),
                'ticket_view.php?id=' . $ticket['id']
            );
        }
        AuditLog::record('ticket_updated', $ticket['ticket_no'] . ': ' . implode(', ', $changes), (int) $staff['id']);
        return $changes;
    }

    public static function staffMembers(): array
    {
        return DB::all(
            "SELECT u.id, u.full_name, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.slug IN ('support_staff', 'super_admin') AND u.account_status = 'active' ORDER BY u.full_name"
        );
    }
}
