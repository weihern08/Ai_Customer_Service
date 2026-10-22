<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('settings.manage');

$fields = [
    'site_name'               => ['Portal name', 'text', 2, 80],
    'company_name'            => ['Company legal name', 'text', 2, 120],
    'company_tagline'         => ['Tagline', 'text', 0, 160],
    'support_email'           => ['Support email', 'email', 5, 190],
    'support_phone'           => ['Support phone', 'text', 5, 40],
    'support_whatsapp'        => ['WhatsApp number', 'text', 0, 40],
    'company_address'         => ['Office address', 'textarea', 0, 255],
    'business_hours'          => ['Business hours (one line per day range)', 'textarea', 5, 400],
];
$errors = [];

if (is_post()) {
    require_csrf();
    $values = [];
    foreach ($fields as $key => [$label, $type, $min, $max]) {
        $value = trim((string) input($key));
        $values[$key] = $value;
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            $errors[$key] = "$label must be between $min and $max characters.";
        } elseif ($type === 'email' && !valid_email($value)) {
            $errors[$key] = 'Please enter a valid email address.';
        }
    }
    $values['default_language'] = isset(Lang::AVAILABLE[(string) input('default_language')]) ? (string) input('default_language') : 'en';
    $values['allow_registration'] = input('allow_registration') === '1' ? '1' : '0';
    $values['multilingual_enabled'] = input('multilingual_enabled') === '1' ? '1' : '0';
    $timeout = input_int('session_timeout_minutes');
    $maxUpload = input_int('max_upload_mb');
    if ($timeout < 5 || $timeout > 480) {
        $errors['session_timeout_minutes'] = 'Session timeout must be between 5 and 480 minutes.';
    }
    if ($maxUpload < 1 || $maxUpload > 20) {
        $errors['max_upload_mb'] = 'Upload limit must be between 1 and 20 MB.';
    }
    $values['session_timeout_minutes'] = (string) $timeout;
    $values['max_upload_mb'] = (string) $maxUpload;

    if (!$errors) {
        $changed = [];
        foreach ($values as $key => $value) {
            if ((string) Settings::get($key, '') !== $value) {
                Settings::set($key, $value, 'general');
                $changed[] = $key;
            }
        }
        if ($changed) {
            AuditLog::record('settings_changed', 'Changed: ' . implode(', ', $changed));
        }
        flash('success', $changed ? 'Settings saved.' : 'No changes to save.');
        redirect('admin/settings.php');
    }
}

$v = static fn (string $key) => (string) (is_post() ? ($_POST[$key] ?? '') : Settings::get($key, ''));
$phpUploadLimit = ini_get('upload_max_filesize');

$pageTitle = 'System Settings';
$pageSubtitle = 'Company information shown to customers and used by the AI as verified facts.';
$activeNav = 'settings';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<form method="post" novalidate class="row g-4">
    <?= csrf_field() ?>
    <div class="col-xl-8">
        <div class="ns-card">
            <div class="ns-card-head"><h2>Organisation profile</h2></div>
            <div class="alert alert-info small"><i class="fa-solid fa-robot me-2"></i>The AI assistant treats these values as verified facts, so keep them accurate.</div>
            <div class="row g-3">
                <?php foreach ($fields as $key => [$label, $type]): ?>
                    <div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
                        <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        <?php if ($type === 'textarea'): ?>
                            <textarea class="form-control <?= isset($errors[$key]) ? 'is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="3"><?= e($v($key)) ?></textarea>
                        <?php else: ?>
                            <input class="form-control <?= isset($errors[$key]) ? 'is-invalid' : '' ?>" type="<?= e($type) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($v($key)) ?>">
                        <?php endif; ?>
                        <?php if (isset($errors[$key])): ?><div class="invalid-feedback"><?= e($errors[$key]) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Portal behaviour</h2></div>
            <div class="mb-3">
                <label class="form-label" for="default_language">Default language</label>
                <select class="form-select" id="default_language" name="default_language">
                    <?php foreach (Lang::AVAILABLE as $code => $meta): ?><option value="<?= e($code) ?>" <?= $v('default_language') === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="session_timeout_minutes">Idle session timeout (minutes)</label>
                <input class="form-control <?= isset($errors['session_timeout_minutes']) ? 'is-invalid' : '' ?>" type="number" id="session_timeout_minutes" name="session_timeout_minutes" value="<?= e($v('session_timeout_minutes')) ?>">
                <?php if (isset($errors['session_timeout_minutes'])): ?><div class="invalid-feedback"><?= e($errors['session_timeout_minutes']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="max_upload_mb">Maximum attachment size (MB)</label>
                <input class="form-control <?= isset($errors['max_upload_mb']) ? 'is-invalid' : '' ?>" type="number" id="max_upload_mb" name="max_upload_mb" value="<?= e($v('max_upload_mb')) ?>">
                <?php if (isset($errors['max_upload_mb'])): ?><div class="invalid-feedback"><?= e($errors['max_upload_mb']) ?></div><?php endif; ?>
                <div class="form-text">PHP's own limit on this server: <?= e((string) $phpUploadLimit) ?>.</div>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="allow_registration" name="allow_registration" value="1" <?= $v('allow_registration') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_registration">Allow customer self-registration</label>
            </div>
            <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" id="multilingual_enabled" name="multilingual_enabled" value="1" <?= $v('multilingual_enabled') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="multilingual_enabled">Multi-language mode (English, Bahasa Melayu, 中文)</label>
            </div>
            <div class="form-text">When off, the whole site is shown in English and the AI assistant replies in English.</div>
        </div>
        <div class="ns-card mb-4">
            <div class="ns-card-head"><h2>Environment</h2></div>
            <dl class="ns-dl small mb-0">
                <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
                <dt>Database</dt><dd><?= e((string) DB::value('SELECT VERSION()')) ?></dd>
                <dt>cURL</dt><dd><?= function_exists('curl_init') ? 'Available' : '<span class="text-danger">Missing</span>' ?></dd>
                <dt>Encryption key</dt><dd><?= preg_match('/^[a-f0-9]{64}$/i', (string) Config::get('app.key')) ? 'Configured' : '<span class="text-danger">Missing</span>' ?></dd>
                <dt>Uploads writable</dt><dd><?= is_writable(FileUploader::directory()) ? 'Yes' : '<span class="text-danger">No</span>' ?></dd>
                <dt>HTTPS</dt><dd><?= is_https() ? 'Yes' : 'No (recommended in production)' ?></dd>
            </dl>
        </div>
        <div class="d-grid"><button class="btn ns-btn-primary btn-lg" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save settings</button></div>
    </div>
</form>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
