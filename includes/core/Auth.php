<?php
declare(strict_types=1);

/**
 * Authentication and role-based access control (RBAC).
 *
 * Roles are stored in the `roles` table; the permissions each role grants are defined here
 * so they are version-controlled together with the code that checks them.
 */
final class Auth
{
    public const PERMISSIONS = [
        'super_admin' => ['*'],
        'support_staff' => [
            'admin.access', 'dashboard.view', 'analytics.view',
            'conversations.view', 'conversations.takeover', 'escalations.manage',
            'tickets.view', 'tickets.manage', 'tickets.assign', 'customers.view',
        ],
        'content_manager' => [
            'admin.access', 'dashboard.view', 'faq.manage', 'kb.manage', 'categories.manage',
        ],
        'customer' => [
            'portal.chat', 'portal.tickets', 'portal.feedback', 'portal.profile',
        ],
    ];

    private const MAX_FAILED_LOGINS = 5;
    private const LOCKOUT_MINUTES = 15;

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            $id = (int) ($_SESSION['uid'] ?? 0);
            if ($id > 0) {
                $user = self::findUser($id);
                if (!$user || $user['account_status'] !== 'active') {
                    unset($_SESSION['uid']);
                    $user = null;
                }
                self::$user = $user;
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): ?string
    {
        return self::user()['role_slug'] ?? null;
    }

    public static function isStaff(): bool
    {
        return self::check() && self::role() !== 'customer';
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        if ($role === null) {
            return false;
        }
        $granted = self::PERMISSIONS[$role] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /**
     * Attempts a login. Returns null on success or a translated error message.
     */
    public static function attempt(string $email, string $password): ?string
    {
        $ip = client_ip();
        $recentFailures = (int) DB::value(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'login_failed' AND ip_address = ? AND created_at > (NOW() - INTERVAL " . self::LOCKOUT_MINUTES . ' MINUTE)',
            [$ip]
        );
        if ($recentFailures >= self::MAX_FAILED_LOGINS) {
            return __('auth.locked', ['minutes' => self::LOCKOUT_MINUTES]);
        }

        $user = DB::row('SELECT id, password_hash, account_status FROM users WHERE email = ?', [mb_strtolower($email)]);
        // Verify against a dummy hash when the user does not exist to keep response timing similar
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            AuditLog::record('login_failed', 'Failed login for ' . str_limit($email, 120), $user['id'] ?? null);
            return __('auth.invalid');
        }
        if ($user['account_status'] !== 'active') {
            AuditLog::record('login_failed', 'Login blocked: account suspended', (int) $user['id']);
            return __('auth.suspended');
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        self::login((int) $user['id']);
        return null;
    }

    public static function login(int $userId): void
    {
        Session::regenerate();
        $_SESSION['uid'] = $userId;
        $_SESSION['_last_activity'] = time();
        unset($_SESSION['_expired']);
        DB::run('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [client_ip(), $userId]);
        self::$resolved = false;
        $user = self::user();
        if ($user && !empty($user['preferred_language'])) {
            Lang::setCurrent($user['preferred_language']);
        }
        AuditLog::record('login', 'User signed in', $userId);
    }

    public static function logout(): void
    {
        if ($id = self::id()) {
            AuditLog::record('logout', 'User signed out', $id);
        }
        $lang = $_SESSION['lang'] ?? null;
        Session::destroy();
        session_start();
        session_regenerate_id(true);
        if ($lang) {
            $_SESSION['lang'] = $lang;
        }
        self::$user = null;
    }

    /* ---------------- Guards used at the top of protected pages ---------------- */

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user) {
            return $user;
        }
        if (!empty($_SESSION['_expired'])) {
            unset($_SESSION['_expired']);
            $message = __('auth.session_expired');
        } else {
            $message = __('auth.login_required');
        }
        if (is_api_request()) {
            json_error($message, 401, ['code' => 'auth_required']);
        }
        flash('warning', $message);
        redirect('login.php?next=' . rawurlencode(current_path()));
    }

    public static function requirePermission(string $permission): array
    {
        $user = self::requireLogin();
        if (!self::can($permission)) {
            AuditLog::record('access_denied', 'Blocked access to ' . str_limit(current_path(), 200) . " (needs $permission)", (int) $user['id']);
            abort(403);
        }
        return $user;
    }

    public static function requireCustomerPortal(): array
    {
        return self::requireLogin();
    }

    public static function findUser(int $id): ?array
    {
        return DB::row(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
    }
}
