<?php
declare(strict_types=1);

/**
 * In-app notifications (bell icon). Stored per recipient in `notifications`.
 */
final class Notifier
{
    public static function toUser(int $userId, string $type, string $title, string $body = '', string $link = ''): void
    {
        DB::insert('notifications', [
            'user_id' => $userId,
            'type'    => $type,
            'title'   => mb_substr($title, 0, 150),
            'body'    => mb_substr($body, 0, 255),
            'link'    => mb_substr($link, 0, 255),
        ]);
    }

    /** Sends to every active user holding one of the given roles. */
    public static function toRoles(array $roleSlugs, string $type, string $title, string $body = '', string $link = '', ?int $exceptUserId = null): void
    {
        $users = DB::all(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.account_status = \'active\' AND r.slug IN (' . DB::placeholders($roleSlugs) . ')',
            $roleSlugs
        );
        foreach ($users as $user) {
            if ((int) $user['id'] !== $exceptUserId) {
                self::toUser((int) $user['id'], $type, $title, $body, $link);
            }
        }
    }

    /** Support team = support staff + super admins. */
    public static function toSupportTeam(string $type, string $title, string $body = '', string $link = '', ?int $exceptUserId = null): void
    {
        self::toRoles(['support_staff', 'super_admin'], $type, $title, $body, $link, $exceptUserId);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
    }

    public static function latest(int $userId, int $limit = 10): array
    {
        return DB::all(
            'SELECT id, type, title, body, link, is_read, created_at FROM notifications
             WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        );
    }
}
