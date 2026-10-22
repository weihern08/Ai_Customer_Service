<?php
declare(strict_types=1);

/**
 * Read-only access to config/config.php using dot notation, e.g. Config::get('db.host').
 */
final class Config
{
    private static array $items = [];

    public static function load(array $items): void
    {
        self::$items = $items;
        if (empty(self::$items['app']['base_url'])) {
            self::$items['app']['base_url'] = self::detectBaseUrl();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * Works out the URL path of the application folder relative to the web server's document root,
     * e.g. "/Ai_Customer_Service" on XAMPP or "" when installed at the domain root.
     */
    public static function detectBaseUrl(): string
    {
        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $appRoot = realpath(APP_ROOT);
        if ($docRoot && $appRoot && stripos($appRoot, $docRoot) === 0) {
            $relative = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
            return rtrim('/' . ltrim($relative, '/'), '/');
        }
        // Fallback: derive from the executing script location
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($script), '/');
        foreach (['/admin', '/api'] as $sub) {
            if (str_ends_with($dir, $sub)) {
                $dir = substr($dir, 0, -strlen($sub));
            }
        }
        return $dir === '.' ? '' : $dir;
    }
}
