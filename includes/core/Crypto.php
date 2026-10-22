<?php
declare(strict_types=1);

/**
 * AES-256-GCM encryption for secrets stored in the database (e.g. AI API keys).
 * The key comes from config/config.php ('app.key'), never from the database.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if ($key === null || !function_exists('openssl_encrypt')) {
            Logger::warning('APP key missing or OpenSSL unavailable; secret stored with base64 encoding only.');
            return 'b64:' . base64_encode($plain);
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (str_starts_with($stored, 'b64:')) {
            return (string) base64_decode(substr($stored, 4), true);
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored; // legacy plain value
        }
        $key = self::key();
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($key === null || $raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    /** Masks a secret for display: "sk-…a1b2". */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return mb_substr($secret, 0, 3) . str_repeat('•', 8) . mb_substr($secret, -4);
    }

    private static function key(): ?string
    {
        $hex = (string) Config::get('app.key', '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $hex)) {
            return null;
        }
        return hex2bin($hex);
    }
}
