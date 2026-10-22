<?php
declare(strict_types=1);

/**
 * Interface translations. Each language is a PHP array file in /lang (en.php, ms.php, zh.php).
 * Missing keys fall back to English, then to the key itself.
 */
final class Lang
{
    public const AVAILABLE = [
        'en' => ['label' => 'English',       'short' => 'EN', 'speech' => 'en-US', 'ai_name' => 'English'],
        'ms' => ['label' => 'Bahasa Melayu', 'short' => 'BM', 'speech' => 'ms-MY', 'ai_name' => 'Bahasa Melayu (Malay)'],
        'zh' => ['label' => '中文',           'short' => '中文', 'speech' => 'zh-CN', 'ai_name' => 'Simplified Chinese'],
    ];

    private static string $current = 'en';
    private static array $lines = [];
    private static bool $multilingual = false;

    /**
     * Picks the language from ?lang=, the session, the user's preference, or the site default.
     * When multi-language mode is off (System Settings), everything is shown in English.
     */
    public static function boot(): void
    {
        self::$multilingual = Settings::bool('multilingual_enabled');
        if (!self::$multilingual) {
            self::$current = 'en';
            return;
        }
        $requested = $_GET['lang'] ?? null;
        if (is_string($requested) && isset(self::AVAILABLE[$requested])) {
            $_SESSION['lang'] = $requested;
            if (!empty($_SESSION['uid'])) {
                DB::run('UPDATE users SET preferred_language = ? WHERE id = ?', [$requested, (int) $_SESSION['uid']]);
            }
        }
        $lang = $_SESSION['lang'] ?? Settings::get('default_language', 'en');
        self::$current = isset(self::AVAILABLE[$lang]) ? $lang : 'en';
    }

    public static function isMultilingual(): bool
    {
        return self::$multilingual;
    }

    public static function setCurrent(string $lang): void
    {
        if (self::$multilingual && isset(self::AVAILABLE[$lang])) {
            self::$current = $lang;
            $_SESSION['lang'] = $lang;
        }
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function get(string $key, array $replace = [], ?string $lang = null): string
    {
        $lang = self::$multilingual ? ($lang ?? self::$current) : 'en';
        $text = self::lines($lang)[$key] ?? self::lines('en')[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }
        return $text;
    }

    /** Returns all keys starting with a prefix (used to pass strings to JavaScript). */
    public static function group(string $prefix): array
    {
        $result = [];
        $merged = self::lines(self::$current) + self::lines('en');
        foreach ($merged as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public static function lines(string $lang): array
    {
        if (!isset(self::$lines[$lang])) {
            $file = APP_ROOT . '/lang/' . $lang . '.php';
            self::$lines[$lang] = (isset(self::AVAILABLE[$lang]) && is_file($file)) ? (array) require $file : [];
        }
        return self::$lines[$lang];
    }
}
