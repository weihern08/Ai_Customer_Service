<?php
declare(strict_types=1);

/**
 * AI conversation sessions and their messages.
 */
final class ChatService
{
    public const MAX_MESSAGE_LENGTH = 2000;

    public static function find(int $id): ?array
    {
        return DB::row(
            'SELECT s.*, c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                    st.full_name AS staff_name
             FROM chat_sessions s
             JOIN users c ON c.id = s.customer_id
             LEFT JOIN users st ON st.id = s.assigned_staff_id
             WHERE s.id = ?',
            [$id]
        );
    }

    /** Returns the session only if it belongs to the customer (ownership check). */
    public static function findForCustomer(int $id, int $customerId): ?array
    {
        $session = self::find($id);
        return ($session && (int) $session['customer_id'] === $customerId) ? $session : null;
    }

    public static function activeSessionFor(int $customerId): ?array
    {
        $id = DB::value(
            "SELECT id FROM chat_sessions WHERE customer_id = ? AND status <> 'closed' ORDER BY id DESC LIMIT 1",
            [$customerId]
        );
        return $id ? self::find((int) $id) : null;
    }

    public static function create(int $customerId, string $language): array
    {
        $id = DB::insert('chat_sessions', ['customer_id' => $customerId, 'language' => $language]);
        return self::find($id);
    }

    public static function listForCustomer(int $customerId, int $limit = 25): array
    {
        return DB::all(
            'SELECT id, title, status, last_activity_at, message_count FROM chat_sessions
             WHERE customer_id = ? AND message_count > 0 ORDER BY last_activity_at DESC LIMIT ' . max(1, $limit),
            [$customerId]
        );
    }

    public static function messages(int $sessionId, int $afterId = 0): array
    {
        return DB::all(
            'SELECT m.*, u.full_name AS sender_name, f.rating AS feedback_rating
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id
             LEFT JOIN message_feedback f ON f.message_id = m.id
             WHERE m.session_id = ? AND m.id > ?
             ORDER BY m.id ASC LIMIT 300',
            [$sessionId, $afterId]
        );
    }

    /** Recent customer/AI/staff turns for the AI prompt (oldest first, system notes excluded). */
    public static function historyForPrompt(int $sessionId, int $limit, int $beforeId): array
    {
        $rows = DB::all(
            "SELECT sender_type, body FROM chat_messages
             WHERE session_id = ? AND id < ? AND sender_type <> 'system'
             ORDER BY id DESC LIMIT " . max(0, min(20, $limit)),
            [$sessionId, $beforeId]
        );
        return array_reverse($rows);
    }

    public static function addMessage(int $sessionId, string $senderType, ?int $senderId, string $body, array $meta = []): int
    {
        $id = DB::insert('chat_messages', [
            'session_id'  => $sessionId,
            'sender_type' => $senderType,
            'sender_id'   => $senderId,
            'body'        => $body,
            'language'    => $meta['language'] ?? null,
            'intent'      => $meta['intent'] ?? null,
            'sentiment'   => $meta['sentiment'] ?? null,
            'confidence'  => $meta['confidence'] ?? null,
            'provider'    => $meta['provider'] ?? null,
            'model'       => $meta['model'] ?? null,
            'sources'     => !empty($meta['sources']) ? json_encode($meta['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'needs_human' => !empty($meta['needs_human']) ? 1 : 0,
        ]);
        DB::run(
            'UPDATE chat_sessions SET message_count = message_count + 1, last_activity_at = NOW() WHERE id = ?',
            [$sessionId]
        );
        return $id;
    }

    public static function systemNote(array $session, string $langKey, array $replace = []): int
    {
        $text = Lang::get($langKey, $replace, $session['language'] ?: 'en');
        return self::addMessage((int) $session['id'], 'system', null, $text, ['language' => $session['language']]);
    }

    /**
     * Main entry point when a customer sends a chat message.
     */
    public static function handleCustomerMessage(array $session, array $customer, string $text, string $uiLanguage): array
    {
        $sessionId = (int) $session['id'];
        $analysis = TextAnalyzer::analyze($text, $uiLanguage);

        $customerMessageId = self::addMessage($sessionId, 'customer', (int) $customer['id'], $text, [
            'language'  => $analysis['language'],
            'intent'    => $analysis['intent'],
            'sentiment' => $analysis['sentiment'],
        ]);

        DB::run(
            'UPDATE chat_sessions SET language = ?, last_intent = ?, last_sentiment = ?, title = COALESCE(title, ?) WHERE id = ?',
            [$analysis['language'], $analysis['intent'], $analysis['sentiment'], str_limit($text, 140), $sessionId]
        );

        $escalated = false;
        if ($analysis['sentiment'] === 'angry' && !(int) $session['angry_flagged']) {
            DB::run('UPDATE chat_sessions SET angry_flagged = 1 WHERE id = ?', [$sessionId]);
            Notifier::toSupportTeam(
                'angry_customer',
                'Angry customer detected',
                $customer['full_name'] . ': "' . str_limit($text, 120) . '"',
                'admin/conversation.php?id=' . $sessionId
            );
            if (Settings::bool('ai_auto_escalate_angry') && $session['status'] === 'active') {
                EscalationService::open($session, 'negative_sentiment', 'Automatic escalation: angry sentiment detected.', [
                    'intent' => $analysis['intent'], 'sentiment' => 'angry', 'confidence' => $session['last_confidence'],
                ], (int) $customer['id']);
                $escalated = true;
            }
        }

        $customerMessage = self::messageById($customerMessageId);

        // A human agent has taken over: the AI stays silent and the agent is notified.
        if ($session['status'] === 'human') {
            self::notifyAgentOfReply($session, $customer, $text);
            return [
                'mode'             => 'human',
                'customer_message' => self::formatMessage($customerMessage),
                'ai_message'       => null,
                'escalated'        => $escalated,
            ];
        }

        $history = self::historyForPrompt($sessionId, (int) Settings::get('ai_history_messages', 6), $customerMessageId);
        $result = (new AiEngine())->respond($text, $history, $uiLanguage, $analysis);

        $aiMessageId = self::addMessage($sessionId, 'ai', null, $result['reply'], [
            'language'    => $result['language'],
            'intent'      => $result['intent'],
            'sentiment'   => $result['sentiment'],
            'confidence'  => $result['confidence'],
            'provider'    => $result['provider'],
            'model'       => $result['model'],
            'sources'     => $result['sources'],
            'needs_human' => $result['recommend_human'],
        ]);

        foreach ($result['attempts'] as $attempt) {
            DB::insert('ai_request_logs', [
                'session_id'    => $sessionId,
                'message_id'    => $aiMessageId,
                'provider_slot' => $attempt['slot'],
                'model'         => $attempt['model'],
                'outcome'       => $attempt['outcome'],
                'latency_ms'    => $attempt['latency_ms'],
                'error_message' => $attempt['error'],
            ]);
        }

        DB::run(
            'UPDATE chat_sessions SET last_confidence = ?, last_provider = ? WHERE id = ?',
            [$result['confidence'], $result['provider'], $sessionId]
        );

        return [
            'mode'             => 'ai',
            'customer_message' => self::formatMessage($customerMessage),
            'ai_message'       => self::formatMessage(self::messageById($aiMessageId)),
            'recommend_human'  => $result['recommend_human'],
            'recommend_reason' => $result['recommend_reason'],
            'escalated'        => $escalated,
        ];
    }

    public static function close(array $session, ?string $resolution): void
    {
        if ($session['status'] === 'closed') {
            return;
        }
        DB::run(
            "UPDATE chat_sessions SET status = 'closed', resolution = COALESCE(resolution, ?), closed_at = NOW() WHERE id = ?",
            [$resolution, (int) $session['id']]
        );
        DB::run(
            "UPDATE escalations SET status = 'resolved', resolved_at = NOW() WHERE session_id = ? AND status <> 'resolved'",
            [(int) $session['id']]
        );
    }

    /** Resolution used when a customer starts a new chat: who effectively handled the old one. */
    public static function inferResolution(array $session): ?string
    {
        if ((int) $session['message_count'] === 0) {
            return null;
        }
        if ($session['assigned_staff_id'] || (int) $session['is_escalated']) {
            return 'human';
        }
        return 'ai';
    }

    public static function messageById(int $id): ?array
    {
        return DB::row(
            'SELECT m.*, u.full_name AS sender_name, f.rating AS feedback_rating
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_id
             LEFT JOIN message_feedback f ON f.message_id = m.id
             WHERE m.id = ?',
            [$id]
        );
    }

    /** Shape of a message in JSON responses. Bodies are plain text; the browser escapes them. */
    public static function formatMessage(?array $row, bool $forStaff = false): ?array
    {
        if (!$row) {
            return null;
        }
        $data = [
            'id'          => (int) $row['id'],
            'sender_type' => $row['sender_type'],
            'sender_name' => $row['sender_type'] === 'ai' ? (string) Settings::get('ai_assistant_name', 'Nova') : (string) ($row['sender_name'] ?? ''),
            'body'        => $row['body'],
            'time'        => format_dt($row['created_at'], 'g:i A'),
            'datetime'    => $row['created_at'],
            'confidence'  => $row['confidence'] !== null ? (int) $row['confidence'] : null,
            'sources'     => $row['sources'] ? (json_decode($row['sources'], true) ?: []) : [],
            'needs_human' => (bool) $row['needs_human'],
            'feedback'    => $row['feedback_rating'] ?? null,
            'language'    => $row['language'],
        ];
        if ($forStaff) {
            $data += [
                'intent'    => $row['intent'],
                'sentiment' => $row['sentiment'],
                'provider'  => $row['provider'],
                'model'     => $row['model'],
            ];
        }
        return $data;
    }

    private static function notifyAgentOfReply(array $session, array $customer, string $text): void
    {
        $staffId = (int) ($session['assigned_staff_id'] ?? 0);
        if (!$staffId) {
            return;
        }
        $link = 'admin/conversation.php?id=' . $session['id'];
        $pending = (int) DB::value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ? AND is_read = 0',
            [$staffId, $link]
        );
        if ($pending === 0) {
            Notifier::toUser($staffId, 'chat_reply', 'Customer replied in live chat', $customer['full_name'] . ': ' . str_limit($text, 120), $link);
        }
    }
}
