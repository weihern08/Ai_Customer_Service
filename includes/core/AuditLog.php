<?php
declare(strict_types=1);

/**
 * Security / activity audit trail stored in `audit_logs`.
 */
final class AuditLog
{
    public const ACTIONS = [
        'login'               => 'Login',
        'logout'              => 'Logout',
        'login_failed'        => 'Failed Login',
        'access_denied'       => 'Access Denied',
        'customer_registered' => 'Customer Created',
        'user_created'        => 'User Created',
        'user_updated'        => 'User Updated',
        'profile_updated'     => 'Profile Updated',
        'password_changed'    => 'Password Changed',
        'ticket_created'      => 'Ticket Created',
        'ticket_updated'      => 'Ticket Updated',
        'ticket_assigned'     => 'Ticket Assigned',
        'ticket_replied'      => 'Ticket Reply',
        'ai_config_changed'   => 'AI Configuration Changed',
        'settings_changed'    => 'Settings Changed',
        'faq_created'         => 'FAQ Created',
        'faq_updated'         => 'FAQ Updated',
        'faq_deleted'         => 'FAQ Deleted',
        'article_created'     => 'Knowledge Article Created',
        'article_updated'     => 'Knowledge Article Updated',
        'article_deleted'     => 'Knowledge Article Deleted',
        'category_changed'    => 'Category Changed',
        'chat_escalated'      => 'Conversation Escalated',
        'human_takeover'      => 'Human Takeover',
        'chat_released'       => 'Conversation Returned to AI',
        'chat_resolved'       => 'Conversation Resolved',
        'upload_rejected'     => 'Upload Rejected',
    ];

    public static function record(string $action, string $description = '', ?int $userId = null): void
    {
        try {
            DB::insert('audit_logs', [
                'user_id'     => $userId ?? Auth::id(),
                'action'      => substr($action, 0, 60),
                'description' => mb_substr($description, 0, 500),
                'ip_address'  => client_ip(),
                'user_agent'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            // Logging must never break the user's request
            Logger::error('Audit log failed: ' . $e->getMessage());
        }
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
}
