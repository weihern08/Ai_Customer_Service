<?php
declare(strict_types=1);

/**
 * Aggregated statistics for the admin dashboard and the AI monitoring page.
 */
final class Analytics
{
    public static function kpis(): array
    {
        $row = DB::row(
            "SELECT
                (SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'customer') AS customers,
                (SELECT COUNT(*) FROM chat_sessions WHERE status <> 'closed' AND message_count > 0) AS active_conversations,
                (SELECT COUNT(*) FROM tickets WHERE status IN ('open','pending','in_progress')) AS open_tickets,
                (SELECT COUNT(*) FROM escalations WHERE status <> 'resolved') AS escalated,
                (SELECT COUNT(*) FROM chat_sessions WHERE resolution = 'ai') AS ai_resolved,
                (SELECT COUNT(*) FROM chat_sessions WHERE resolution = 'human') AS human_resolved,
                (SELECT COUNT(*) FROM kb_articles WHERE status = 'published') AS articles,
                (SELECT COUNT(*) FROM faq_entries WHERE is_active = 1) AS faqs"
        );
        return array_map('intval', $row ?? []);
    }

    /** Intent distribution of customer messages. */
    public static function intents(?string $from = null, ?string $to = null): array
    {
        [$range, $params] = self::range('created_at', $from, $to);
        $rows = DB::all(
            "SELECT intent, COUNT(*) AS total FROM chat_messages
             WHERE sender_type = 'customer' AND intent IS NOT NULL $range
             GROUP BY intent ORDER BY total DESC",
            $params
        );
        $result = [];
        foreach ($rows as $row) {
            $result[TextAnalyzer::INTENTS[$row['intent']] ?? $row['intent']] = (int) $row['total'];
        }
        return $result;
    }

    public static function sentiments(?string $from = null, ?string $to = null): array
    {
        [$range, $params] = self::range('created_at', $from, $to);
        $result = array_fill_keys(TextAnalyzer::SENTIMENTS, 0);
        foreach (DB::all(
            "SELECT sentiment, COUNT(*) AS total FROM chat_messages
             WHERE sender_type = 'customer' AND sentiment IS NOT NULL $range GROUP BY sentiment",
            $params
        ) as $row) {
            $result[$row['sentiment']] = (int) $row['total'];
        }
        return $result;
    }

    public static function ticketStatuses(): array
    {
        $result = array_fill_keys(TicketService::STATUSES, 0);
        foreach (DB::all('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status') as $row) {
            $result[$row['status']] = (int) $row['total'];
        }
        return $result;
    }

    /** Message volume per day for the last N days (customer messages vs AI replies vs staff replies). */
    public static function dailyVolume(int $days = 14): array
    {
        $rows = DB::all(
            'SELECT DATE(created_at) AS day, sender_type, COUNT(*) AS total FROM chat_messages
             WHERE created_at >= (CURDATE() - INTERVAL ' . max(1, $days - 1) . ' DAY)
             GROUP BY DATE(created_at), sender_type'
        );
        $series = ['labels' => [], 'customer' => [], 'ai' => [], 'staff' => []];
        $index = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $index[$day] = count($series['labels']);
            $series['labels'][] = date('d M', strtotime($day));
            $series['customer'][] = 0;
            $series['ai'][] = 0;
            $series['staff'][] = 0;
        }
        foreach ($rows as $row) {
            if (isset($index[$row['day']], $series[$row['sender_type']])) {
                $series[$row['sender_type']][$index[$row['day']]] = (int) $row['total'];
            }
        }
        return $series;
    }

    /**
     * AI performance metrics, optionally limited to a date range (Y-m-d).
     */
    public static function aiPerformance(?string $from = null, ?string $to = null): array
    {
        [$logRange, $logParams] = self::range('created_at', $from, $to);
        $logs = DB::row(
            "SELECT
                COUNT(DISTINCT message_id) AS total_requests,
                SUM(provider_slot IN ('primary','fallback') AND outcome = 'success') AS ai_success,
                SUM(provider_slot IN ('primary','fallback') AND outcome = 'failed') AS ai_failed,
                SUM(provider_slot = 'fallback') AS fallback_attempts,
                SUM(provider_slot = 'local') AS local_answers,
                AVG(CASE WHEN outcome = 'success' AND provider_slot <> 'local' THEN latency_ms END) AS avg_latency
             FROM ai_request_logs WHERE 1 = 1 $logRange",
            $logParams
        ) ?? [];

        [$msgRange, $msgParams] = self::range('created_at', $from, $to);
        $messages = DB::row(
            "SELECT COUNT(*) AS ai_messages,
                    AVG(confidence) AS avg_confidence,
                    SUM(provider IN ('fallback','local')) AS fallback_messages,
                    SUM(needs_human = 1) AS low_confidence
             FROM chat_messages WHERE sender_type = 'ai' $msgRange",
            $msgParams
        ) ?? [];

        [$fbRange, $fbParams] = self::range('created_at', $from, $to);
        $feedback = DB::row(
            "SELECT COUNT(*) AS total, SUM(rating = 'helpful') AS helpful FROM message_feedback WHERE 1 = 1 $fbRange",
            $fbParams
        ) ?? [];

        [$sRange, $sParams] = self::range('created_at', $from, $to);
        $sessions = DB::row(
            "SELECT COUNT(*) AS conversations,
                    SUM(is_escalated = 1) AS escalations,
                    SUM(resolution = 'ai') AS ai_resolved,
                    SUM(resolution IS NOT NULL) AS resolved
             FROM chat_sessions WHERE message_count > 0 $sRange",
            $sParams
        ) ?? [];

        $feedbackTotal = (int) ($feedback['total'] ?? 0);
        $resolved = (int) ($sessions['resolved'] ?? 0);
        $conversations = (int) ($sessions['conversations'] ?? 0);

        return [
            'total_requests'    => (int) ($logs['total_requests'] ?? 0),
            'ai_success'        => (int) ($logs['ai_success'] ?? 0),
            'ai_failed'         => (int) ($logs['ai_failed'] ?? 0),
            'fallback_attempts' => (int) ($logs['fallback_attempts'] ?? 0),
            'local_answers'     => (int) ($logs['local_answers'] ?? 0),
            'avg_latency_ms'    => (int) round((float) ($logs['avg_latency'] ?? 0)),
            'ai_messages'       => (int) ($messages['ai_messages'] ?? 0),
            'fallback_messages' => (int) ($messages['fallback_messages'] ?? 0),
            'low_confidence'    => (int) ($messages['low_confidence'] ?? 0),
            'avg_confidence'    => round((float) ($messages['avg_confidence'] ?? 0), 1),
            'feedback_total'    => $feedbackTotal,
            'helpful'           => (int) ($feedback['helpful'] ?? 0),
            'helpful_rate'      => $feedbackTotal ? round(100 * (int) $feedback['helpful'] / $feedbackTotal, 1) : 0.0,
            'conversations'     => $conversations,
            'escalations'       => (int) ($sessions['escalations'] ?? 0),
            'escalation_rate'   => $conversations ? round(100 * (int) $sessions['escalations'] / $conversations, 1) : 0.0,
            'ai_resolved'       => (int) ($sessions['ai_resolved'] ?? 0),
            'resolution_rate'   => $resolved ? round(100 * (int) $sessions['ai_resolved'] / $resolved, 1) : 0.0,
        ];
    }

    /** Requests per provider slot per day, for the AI monitoring chart. */
    public static function providerDaily(?string $from, ?string $to): array
    {
        [$range, $params] = self::range('created_at', $from, $to);
        $rows = DB::all(
            "SELECT DATE(created_at) AS day, provider_slot, outcome, COUNT(*) AS total
             FROM ai_request_logs WHERE 1 = 1 $range
             GROUP BY DATE(created_at), provider_slot, outcome ORDER BY day",
            $params
        );
        $days = [];
        foreach ($rows as $row) {
            $key = $row['outcome'] === 'failed' ? 'failed' : $row['provider_slot'];
            $days[$row['day']][$key] = ($days[$row['day']][$key] ?? 0) + (int) $row['total'];
        }
        $series = ['labels' => [], 'primary' => [], 'fallback' => [], 'local' => [], 'failed' => []];
        foreach ($days as $day => $counts) {
            $series['labels'][] = date('d M', strtotime($day));
            foreach (['primary', 'fallback', 'local', 'failed'] as $k) {
                $series[$k][] = $counts[$k] ?? 0;
            }
        }
        return $series;
    }

    public static function recentFailures(?string $from, ?string $to, int $limit = 10): array
    {
        [$range, $params] = self::range('l.created_at', $from, $to);
        return DB::all(
            "SELECT l.*, s.customer_id FROM ai_request_logs l
             LEFT JOIN chat_sessions s ON s.id = l.session_id
             WHERE l.outcome = 'failed' $range ORDER BY l.id DESC LIMIT " . max(1, $limit),
            $params
        );
    }

    /** Builds an optional "AND column BETWEEN" filter from Y-m-d strings. */
    private static function range(string $column, ?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= " AND $column >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= " AND $column <= ?";
            $params[] = $to . ' 23:59:59';
        }
        return [$sql, $params];
    }
}
