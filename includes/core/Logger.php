<?php
declare(strict_types=1);

/**
 * Server-side application log (storage/logs/app-YYYY-MM-DD.log).
 * Technical details go here instead of being shown to visitors.
 */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        $dir = APP_ROOT . '/storage/logs';
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } else {
            error_log(trim($line));
        }
    }
}
