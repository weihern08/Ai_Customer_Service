<?php
declare(strict_types=1);

/**
 * Hardened PHP session handling with an idle timeout.
 */
final class Session
{
    public static function start(int $idleMinutes, bool $touch = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('NOVASESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idleSeconds = max(5, $idleMinutes) * 60;
        if (isset($_SESSION['uid'], $_SESSION['_last_activity']) && ($now - (int) $_SESSION['_last_activity']) > $idleSeconds) {
            $lang = $_SESSION['lang'] ?? null;
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_expired'] = true;
            if ($lang) {
                $_SESSION['lang'] = $lang;
            }
        }
        // Background polling requests must not keep an idle session alive forever
        if ($touch || !isset($_SESSION['_last_activity'])) {
            $_SESSION['_last_activity'] = $now;
        }
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }
}
