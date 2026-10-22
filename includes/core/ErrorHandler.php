<?php
declare(strict_types=1);

/**
 * Converts uncaught errors into friendly pages (or JSON for API calls) and logs the details.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        Logger::warning($message, ['file' => $file, 'line' => $line]);
        return true;
    }

    public static function handleException(Throwable $e): void
    {
        Logger::error(get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'url'   => $_SERVER['REQUEST_URI'] ?? 'cli',
            'trace' => self::$debug ? $e->getTraceAsString() : null,
        ]);

        $isDbError = $e instanceof PDOException;
        $message = $isDbError
            ? 'Our service is temporarily unavailable. Please try again in a few minutes.'
            : 'Something went wrong. Please try again later.';

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e . PHP_EOL);
            return;
        }
        if (is_api_request()) {
            json_response([
                'ok'    => false,
                'error' => $message,
                'debug' => self::$debug ? $e->getMessage() : null,
            ], 500);
        }
        render_error_page(500, 'Something went wrong', $message, self::$debug ? (string) $e : null);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    }
}
