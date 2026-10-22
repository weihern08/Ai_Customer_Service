<?php
declare(strict_types=1);

/**
 * Human escalation workflow:  AI → (queue) → Human agent → resolved / handed back to AI.
 */
final class EscalationService
{
    public const REASONS = [
        'customer_request'   => 'Customer request',
        'negative_sentiment' => 'Negative / angry sentiment',
        'low_confidence'     => 'Low AI confidence',
        'staff_initiated'    => 'Staff initiated',
    ];

    public static function openFor(int $sessionId): ?array
    {
        return DB::row(
            "SELECT * FROM escalations WHERE session_id = ? AND status <> 'resolved' ORDER BY id DESC LIMIT 1",
            [$sessionId]
        );
    }

    /**
     * Puts a conversation in the human queue. Returns the escalation id (existing one if already queued).
     */
    public static function open(array $session, string $reason, ?string $note, array $meta, ?int $actorId): int
    {
        if ($existing = self::openFor((int) $session['id'])) {
            return (int) $existing['id'];
        }
        $reason = isset(self::REASONS[$reason]) ? $reason : 'customer_request';
        $id = DB::insert('escalations', [
            'session_id'  => (int) $session['id'],
            'customer_id' => (int) $session['customer_id'],
            'reason'      => $reason,
            'note'        => $note !== null ? mb_substr($note, 0, 500) : null,
            'intent'      => $meta['intent'] ?? $session['last_intent'],
            'sentiment'   => $meta['sentiment'] ?? $session['last_sentiment'],
            'confidence'  => $meta['confidence'] ?? $session['last_confidence'],
        ]);
        if ($session['status'] === 'active') {
            DB::run("UPDATE chat_sessions SET status = 'escalated', is_escalated = 1 WHERE id = ?", [(int) $session['id']]);
        } else {
            DB::run('UPDATE chat_sessions SET is_escalated = 1 WHERE id = ?', [(int) $session['id']]);
        }
        ChatService::systemNote($session, 'chat.system.queued');

        $customerName = (string) ($session['customer_name'] ?? 'A customer');
        Notifier::toSupportTeam(
            'escalation',
            $reason === 'negative_sentiment' ? 'Escalation: upset customer' : 'Human support requested',
            $customerName . ' - ' . self::REASONS[$reason] . ($note ? ': ' . str_limit($note, 100) : ''),
            'admin/conversation.php?id=' . $session['id']
        );
        AuditLog::record('chat_escalated', "Conversation #{$session['id']} escalated ($reason)", $actorId);
        return $id;
    }

    public static function takeover(array $session, array $staff): void
    {
        $sessionId = (int) $session['id'];
        $escalation = self::openFor($sessionId);
        if (!$escalation) {
            $escalationId = DB::insert('escalations', [
                'session_id'  => $sessionId,
                'customer_id' => (int) $session['customer_id'],
                'reason'      => 'staff_initiated',
                'intent'      => $session['last_intent'],
                'sentiment'   => $session['last_sentiment'],
                'confidence'  => $session['last_confidence'],
            ]);
        } else {
            $escalationId = (int) $escalation['id'];
        }
        DB::run(
            "UPDATE escalations SET status = 'in_progress', handled_by = ? WHERE id = ?",
            [(int) $staff['id'], $escalationId]
        );
        DB::run(
            "UPDATE chat_sessions SET status = 'human', is_escalated = 1, assigned_staff_id = ? WHERE id = ?",
            [(int) $staff['id'], $sessionId]
        );
        ChatService::systemNote($session, 'chat.system.joined', ['name' => $staff['full_name']]);
        Notifier::toUser(
            (int) $session['customer_id'],
            'chat_takeover',
            Lang::get('notify.agent_joined', ['name' => $staff['full_name']], $session['language'] ?: 'en'),
            str_limit((string) $session['title'], 100),
            'chat.php?session=' . $sessionId
        );
        AuditLog::record('human_takeover', "Took over conversation #$sessionId", (int) $staff['id']);
    }

    public static function release(array $session, array $staff): void
    {
        $sessionId = (int) $session['id'];
        DB::run("UPDATE chat_sessions SET status = 'active', assigned_staff_id = NULL WHERE id = ?", [$sessionId]);
        DB::run(
            "UPDATE escalations SET status = 'resolved', resolved_at = NOW() WHERE session_id = ? AND status <> 'resolved'",
            [$sessionId]
        );
        ChatService::systemNote($session, 'chat.system.released');
        AuditLog::record('chat_released', "Returned conversation #$sessionId to the AI assistant", (int) $staff['id']);
    }

    public static function resolve(array $session, array $staff): void
    {
        ChatService::systemNote($session, 'chat.system.resolved');
        ChatService::close($session, 'human');
        AuditLog::record('chat_resolved', "Resolved conversation #{$session['id']}", (int) $staff['id']);
    }

    public static function pendingCount(): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM escalations WHERE status = 'pending'");
    }
}
