<?php
declare(strict_types=1);

/**
 * Validates and stores ticket attachments.
 *  - extension whitelist + MIME sniffing (finfo)
 *  - size limit
 *  - random stored filename (the original name is only kept as display text)
 *  - stored in a folder protected by .htaccess (no direct access, no script execution)
 */
final class FileUploader
{
    /** extension => accepted MIME types */
    public const ALLOWED = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    public const MAX_FILES = 3;

    public static function directory(): string
    {
        $custom = (string) Config::get('app.upload_path', '');
        $dir = $custom !== '' ? rtrim($custom, '/\\') : APP_ROOT . '/uploads/tickets';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function maxBytes(): int
    {
        return max(1, (int) Settings::get('max_upload_mb', 5)) * 1024 * 1024;
    }

    /**
     * Normalises the $_FILES[field] structure into a list of files that were actually submitted.
     */
    public static function collect(string $field): array
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
            if (!empty($_FILES[$field]['name']) && is_string($_FILES[$field]['name'])) {
                return [$_FILES[$field]];
            }
            return [];
        }
        $files = [];
        foreach ($_FILES[$field]['name'] as $i => $name) {
            if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name'     => $name,
                'type'     => $_FILES[$field]['type'][$i] ?? '',
                'tmp_name' => $_FILES[$field]['tmp_name'][$i] ?? '',
                'error'    => $_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES[$field]['size'][$i] ?? 0,
            ];
        }
        return $files;
    }

    /**
     * Validates all files first. Returns a list of error messages (empty = all valid).
     */
    public static function validate(array $files): array
    {
        $errors = [];
        if (count($files) > self::MAX_FILES) {
            $errors[] = __('upload.too_many', ['n' => self::MAX_FILES]);
            return $errors;
        }
        foreach ($files as $file) {
            $name = self::cleanOriginalName((string) $file['name']);
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? __('upload.too_large', ['name' => $name, 'mb' => self::maxBytes() / 1048576])
                    : __('upload.failed', ['name' => $name]);
                continue;
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                $errors[] = __('upload.failed', ['name' => $name]);
                continue;
            }
            if ((int) $file['size'] <= 0 || (int) $file['size'] > self::maxBytes()) {
                $errors[] = __('upload.too_large', ['name' => $name, 'mb' => self::maxBytes() / 1048576]);
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::ALLOWED[$ext]) || preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|exe|js|html?|svg)\./i', $name)) {
                $errors[] = __('upload.bad_type', ['name' => $name]);
                AuditLog::record('upload_rejected', "Rejected extension for file: $name");
                continue;
            }
            $mime = self::detectMime($file['tmp_name']);
            if ($mime !== null && !in_array($mime, self::ALLOWED[$ext], true)) {
                $errors[] = __('upload.bad_type', ['name' => $name]);
                AuditLog::record('upload_rejected', "MIME mismatch ($mime) for file: $name");
                continue;
            }
            if (self::containsScript($file['tmp_name'], $ext)) {
                $errors[] = __('upload.bad_type', ['name' => $name]);
                AuditLog::record('upload_rejected', "Embedded script detected in file: $name");
            }
        }
        return $errors;
    }

    /**
     * Moves validated files into storage and records them. Call validate() first.
     */
    public static function store(array $files, int $ticketId, ?int $replyId, int $userId): int
    {
        $stored = 0;
        $dir = self::directory();
        foreach ($files as $file) {
            $original = self::cleanOriginalName((string) $file['name']);
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $storedName = date('Ym') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $storedName)) {
                Logger::error('Could not move uploaded file', ['name' => $original]);
                continue;
            }
            @chmod($dir . DIRECTORY_SEPARATOR . $storedName, 0644);
            DB::insert('ticket_files', [
                'ticket_id'     => $ticketId,
                'reply_id'      => $replyId,
                'uploaded_by'   => $userId,
                'original_name' => $original,
                'stored_name'   => $storedName,
                'mime_type'     => self::detectMime($dir . DIRECTORY_SEPARATOR . $storedName) ?? 'application/octet-stream',
                'size_bytes'    => (int) $file['size'],
            ]);
            $stored++;
        }
        return $stored;
    }

    public static function acceptAttribute(): string
    {
        return '.' . implode(',.', array_keys(self::ALLOWED));
    }

    private static function cleanOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}\s._()-]/u', '_', $name) ?? 'file';
        return mb_substr(trim($name) ?: 'file', 0, 150);
    }

    private static function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open') || !is_file($path)) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path) ?: null;
        finfo_close($finfo);
        return $mime;
    }

    /** Rejects files that contain PHP open tags (e.g. a script disguised as an image). */
    private static function containsScript(string $path, string $ext): bool
    {
        if (in_array($ext, ['docx', 'pdf'], true)) {
            return false; // compressed formats; protected by storage rules instead
        }
        $head = (string) file_get_contents($path, false, null, 0, 1024 * 256);
        return (bool) preg_match('/<\?php|<\?=|<script\b/i', $head);
    }
}
