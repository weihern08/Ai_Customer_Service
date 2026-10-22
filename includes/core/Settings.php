<?php
declare(strict_types=1);

/**
 * Key/value system settings stored in the `app_settings` table.
 * Secret values (API keys) are encrypted at rest with Crypto.
 */
final class Settings
{
    private static ?array $cache = null;

    /** Defaults used when a key is missing from the database. */
    public const DEFAULTS = [
        // General
        'site_name'               => 'NovaServe Support',
        'company_name'            => 'NovaServe Technologies Sdn. Bhd.',
        'company_tagline'         => 'Fibre, cloud and smart-home services for modern Malaysian homes.',
        'support_email'           => 'care@novaserve.example',
        'support_phone'           => '+60 3-0000 6682',
        'support_whatsapp'        => '+60 11-0000 6682',
        'company_address'         => 'Level 9, Menara Aurora, 21 Jalan Inovasi, 63000 Cyberjaya, Selangor',
        'business_hours'          => "Mon–Fri: 9:00 AM – 6:00 PM\nSat: 9:00 AM – 1:00 PM\nSun & public holidays: Closed",
        'default_language'        => 'en',
        'multilingual_enabled'    => '0',
        'session_timeout_minutes' => '30',
        'allow_registration'      => '1',
        'max_upload_mb'           => '5',
        // AI
        'ai_enabled'              => '1',
        'ai_assistant_name'       => 'Nova',
        'ai_primary_url'          => 'https://api.openai.com/v1/chat/completions',
        'ai_primary_key'          => '',
        'ai_primary_model'        => 'gpt-4o-mini',
        'ai_fallback_enabled'     => '0',
        'ai_fallback_url'         => '',
        'ai_fallback_key'         => '',
        'ai_fallback_model'       => '',
        'ai_temperature'          => '0.3',
        'ai_max_tokens'           => '500',
        'ai_timeout_seconds'      => '20',
        'ai_history_messages'     => '6',
        'ai_confidence_threshold' => '60',
        'ai_auto_escalate_angry'  => '1',
        'ai_system_prompt'        => '',
    ];

    /** Keys stored encrypted. */
    public const SECRET_KEYS = ['ai_primary_key', 'ai_fallback_key'];

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($key, self::$cache)) {
            $value = self::$cache[$key];
            if (in_array($key, self::SECRET_KEYS, true)) {
                return Crypto::decrypt((string) $value);
            }
            return $value;
        }
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function bool(string $key): bool
    {
        return (string) self::get($key, '0') === '1';
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        $value = (string) $value;
        if (in_array($key, self::SECRET_KEYS, true)) {
            $value = $value === '' ? '' : Crypto::encrypt($value);
        }
        DB::run(
            'INSERT INTO app_settings (setting_key, setting_value, setting_group, is_secret)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), is_secret = VALUES(is_secret)',
            [$key, $value, $group, in_array($key, self::SECRET_KEYS, true) ? 1 : 0]
        );
        self::$cache = null;
    }

    public static function hasValue(string $key): bool
    {
        return (string) self::get($key, '') !== '';
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            foreach (DB::all('SELECT setting_key, setting_value FROM app_settings') as $row) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            Logger::error('Settings could not be loaded: ' . $e->getMessage());
            throw $e;
        }
    }
}
