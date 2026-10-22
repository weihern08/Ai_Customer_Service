<?php
/**
 * Global helper functions shared by every page and API endpoint.
 */
declare(strict_types=1);

/* ---------------------------------------------------------------
 | Output escaping & formatting
 * ------------------------------------------------------------- */

/** Escape a value for safe HTML output (XSS protection). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape text and keep line breaks. */
function nl2br_e(?string $value): string
{
    return nl2br(e($value), false);
}

function str_limit(?string $value, int $limit = 100, string $end = '…'): string
{
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
    return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit)) . $end : $value;
}

/** Trims long text without collapsing line breaks (keeps list formatting readable). */
function str_limit_keep_lines(string $text, int $limit): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    $cut = mb_substr($text, 0, $limit);
    $lastBreak = mb_strrpos($cut, "\n");
    return rtrim($lastBreak !== false && $lastBreak > $limit * 0.5 ? mb_substr($cut, 0, $lastBreak) : $cut) . ' …';
}

/**
 * Renders stored plain text with a tiny, safe subset of formatting:
 * "## Heading", "- bullet", "1. step", "**bold**" and paragraphs. Everything is escaped first.
 */
function render_rich_text(?string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $text) ?: [];
    $html = '';
    $list = null;
    $paragraph = [];
    $inline = static fn (string $s): string => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($s)) ?? e($s);
    $flushParagraph = static function () use (&$paragraph, &$html, $inline): void {
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', array_map($inline, $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$list, &$html): void {
        if ($list) {
            $html .= "</$list>";
            $list = null;
        }
    };
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
        } elseif (preg_match('/^#{2,3}\s+(.+)$/u', $trimmed, $m)) {
            $flushParagraph();
            $closeList();
            $html .= '<h3>' . $inline($m[1]) . '</h3>';
        } elseif (preg_match('/^(?:([-•*])|\d+[.)])\s+(.+)$/u', $trimmed, $m)) {
            $flushParagraph();
            $type = $m[1] !== '' ? 'ul' : 'ol';
            if ($list !== $type) {
                $closeList();
                $html .= "<$type>";
                $list = $type;
            }
            $html .= '<li>' . $inline($m[2]) . '</li>';
        } else {
            $closeList();
            $paragraph[] = $trimmed;
        }
    }
    $flushParagraph();
    $closeList();
    return $html;
}

function format_dt(?string $datetime, string $format = 'd M Y, g:i A'): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : '—';
}

function time_ago(?string $datetime): string
{
    if (!$datetime || !($ts = strtotime($datetime))) {
        return '—';
    }
    $diff = time() - $ts;
    return match (true) {
        $diff < 60     => __('time.just_now'),
        $diff < 3600   => __('time.minutes_ago', ['n' => (int) floor($diff / 60)]),
        $diff < 86400  => __('time.hours_ago', ['n' => (int) floor($diff / 3600)]),
        $diff < 604800 => __('time.days_ago', ['n' => (int) floor($diff / 86400)]),
        default        => format_dt($datetime, 'd M Y'),
    };
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $i ? 1 : 0) . ' ' . $units[$i];
}

function slugify(string $text): string
{
    $text = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '', '-'));
    return $text !== '' ? substr($text, 0, 180) : 'item-' . bin2hex(random_bytes(3));
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters ?: '?';
}

/**
 * Bootstrap-style badge for statuses, priorities, sentiments, etc.
 * Labels are translated when a matching language key exists.
 */
function badge(string $group, ?string $value): string
{
    if ($value === null || $value === '') {
        return '<span class="ns-badge ns-badge-muted">—</span>';
    }
    $label = __("$group.$value");
    if ($label === "$group.$value") {
        $label = ucwords(str_replace('_', ' ', $value));
    }
    return '<span class="ns-badge ns-badge-' . e($group) . '-' . e($value) . '">' . e($label) . '</span>';
}

/* ---------------------------------------------------------------
 | URLs & redirects
 * ------------------------------------------------------------- */

function base_url(): string
{
    return rtrim((string) Config::get('app.base_url', ''), '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/assets/' . $path;
    return url('assets/' . $path) . '?v=' . (is_file($file) ? filemtime($file) : APP_VERSION);
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

/** Accepts only local relative paths for "?next=" redirects (prevents open redirects). */
function safe_next(?string $next, string $default = 'index.php'): string
{
    $next = (string) $next;
    if ($next === '' || preg_match('#^(https?:)?//#i', $next) || str_contains($next, '\\') || preg_match('/[\r\n]/', $next)) {
        return $default;
    }
    $base = base_url();
    if ($base !== '' && str_starts_with($next, $base . '/')) {
        $next = substr($next, strlen($base) + 1);
    }
    return ltrim($next, '/') ?: $default;
}

function current_path(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

/** Build a query string for the current page, overriding some parameters. */
function query_with(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return $params ? '?' . http_build_query($params) : '?';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/* ---------------------------------------------------------------
 | Request helpers
 * ------------------------------------------------------------- */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** True when an upload exceeded post_max_size (PHP then silently empties $_POST and $_FILES). */
function post_too_large(): bool
{
    return is_post() && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function input(string $key, mixed $default = ''): mixed
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function input_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_numeric($value) ? (int) $value : $default;
}

/** Reads a JSON request body (for fetch() calls) merged over $_POST. */
function json_input(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
        $data += $_POST;
    }
    return $data;
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function is_api_request(): bool
{
    if (defined('NS_API')) {
        return true;
    }
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        && !str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190;
}

/** Password policy: 8+ chars with letters and numbers. */
function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return __('auth.password_rule');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return __('auth.password_rule');
    }
    return null;
}

/* ---------------------------------------------------------------
 | Responses
 * ------------------------------------------------------------- */

function json_response(array $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_response(['ok' => false, 'error' => $message] + $extra, $status);
}

/** Minimal standalone error page (does not depend on the database). */
function render_error_page(int $code, string $title, string $message, ?string $debug = null): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
    }
    $home = function_exists('url') ? url('index.php') : '/';
    $css = url('assets/css/app.css');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="' . e($css) . '"></head><body class="ns-error-body">'
        . '<main class="ns-error-card"><div class="ns-error-code">' . $code . '</div>'
        . '<h1>' . e($title) . '</h1><p>' . e($message) . '</p>'
        . '<a class="btn ns-btn-primary" href="' . e($home) . '">Back to home</a>'
        . ($debug ? '<pre class="ns-debug">' . e($debug) . '</pre>' : '')
        . '</main></body></html>';
    exit;
}

function abort(int $code, ?string $message = null): never
{
    $titles = [403 => __('error.forbidden_title'), 404 => __('error.not_found_title'), 419 => __('error.expired_title')];
    $messages = [403 => __('error.forbidden'), 404 => __('error.not_found'), 419 => __('error.csrf')];
    // 419 (expired CSRF token) is not a standard status; Apache rewrites unknown codes to 500, so send 403
    $httpCode = $code === 419 ? 403 : $code;
    if (is_api_request()) {
        json_error($message ?? ($messages[$code] ?? __('error.generic')), $httpCode, $code === 419 ? ['code' => 'csrf'] : []);
    }
    render_error_page($httpCode, $titles[$code] ?? __('error.generic_title'), $message ?? ($messages[$code] ?? __('error.generic')));
}

/* ---------------------------------------------------------------
 | Flash messages & old input
 * ------------------------------------------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function remember_input(array $data): void
{
    unset($data['password'], $data['password_confirm'], $data['current_password'], $data['_csrf']);
    $_SESSION['_old'] = $data;
}

function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

/* ---------------------------------------------------------------
 | CSRF protection
 * ------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Validates the token from a form field, JSON body or X-CSRF-Token header. Aborts on failure. */
function require_csrf(): void
{
    $token = $_POST['_csrf']
        ?? (json_input()['_csrf'] ?? null)
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!is_string($token) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        abort(419);
    }
}

/* ---------------------------------------------------------------
 | Translation
 * ------------------------------------------------------------- */

function __(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

function current_lang(): string
{
    return Lang::current();
}
