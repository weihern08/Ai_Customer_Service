<?php
/**
 * NovaServe web installer.
 * Creates the database tables, the first administrator, config/config.php and a lock file.
 * Once storage/installed.lock exists the installer refuses to run again.
 */
define('NS_INSTALLER', true);
require __DIR__ . '/includes/bootstrap.php';

session_name('NOVASETUP');
session_start();

$lockFile = APP_ROOT . '/storage/installed.lock';
$configFile = APP_ROOT . '/config/config.php';

function setup_token(): string
{
    if (empty($_SESSION['setup_csrf'])) {
        $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['setup_csrf'];
}

$requirements = [
    ['PHP 8.1 or newer (8.3 recommended)', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), ''],
    ['mbstring extension', extension_loaded('mbstring'), ''],
    ['OpenSSL extension (API key encryption)', extension_loaded('openssl'), ''],
    ['cURL extension (AI providers)', extension_loaded('curl'), ''],
    ['Fileinfo extension (upload checks)', extension_loaded('fileinfo'), ''],
    ['config/ is writable', is_writable(APP_ROOT . '/config'), ''],
    ['storage/ is writable', is_writable(APP_ROOT . '/storage'), ''],
    ['uploads/tickets/ is writable', is_writable(APP_ROOT . '/uploads/tickets'), ''],
];
$requirementsMet = !in_array(false, array_column($requirements, 1), true);

$errors = [];
$done = false;
$log = [];
$form = [
    'db_host'      => '127.0.0.1',
    'db_port'      => '3306',
    'db_name'      => 'novaserve_support',
    'db_user'      => 'root',
    'base_url'     => Config::detectBaseUrl(),
    'admin_name'   => 'Platform Administrator',
    'admin_email'  => 'admin@novaserve.example',
    'sample'       => '1',
];

$installed = is_file($lockFile);

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(setup_token(), (string) ($_POST['_csrf'] ?? ''))) {
        $errors[] = 'Your setup session expired. Please submit the form again.';
    }
    foreach ($form as $key => $default) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');

    if (!$requirementsMet) {
        $errors[] = 'Please fix the server requirements first.';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $form['db_name'])) {
        $errors[] = 'Database name may only contain letters, numbers and underscores.';
    }
    if ($form['db_host'] === '' || $form['db_user'] === '') {
        $errors[] = 'Database host and username are required.';
    }
    if (!ctype_digit($form['db_port'])) {
        $errors[] = 'Database port must be a number.';
    }
    if ($form['base_url'] !== '' && !preg_match('#^(https?://[^\s]+|/[^\s]*)$#', $form['base_url'])) {
        $errors[] = 'Base URL must start with "/" or "http(s)://", or be left empty.';
    }
    if (mb_strlen($form['admin_name']) < 2) {
        $errors[] = 'Administrator name is required.';
    }
    if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Administrator email is not valid.';
    }
    if (strlen($adminPassword) < 10 || !preg_match('/[A-Za-z]/', $adminPassword) || !preg_match('/\d/', $adminPassword)) {
        $errors[] = 'Administrator password must be at least 10 characters with letters and numbers.';
    }

    if (!$errors) {
        $dbConfig = [
            'host' => $form['db_host'], 'port' => (int) $form['db_port'], 'name' => $form['db_name'],
            'user' => $form['db_user'], 'pass' => $dbPass, 'charset' => 'utf8mb4',
        ];
        try {
            $server = DB::connect($dbConfig, false);
            $log[] = 'Connected to the database server (' . $server->getAttribute(PDO::ATTR_SERVER_VERSION) . ').';
            try {
                $server->exec('CREATE DATABASE IF NOT EXISTS `' . $form['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $log[] = 'Database "' . $form['db_name'] . '" is ready.';
            } catch (PDOException $e) {
                $log[] = 'Could not create the database automatically (common on shared hosting). Using the existing database.';
            }
            $pdo = DB::connect($dbConfig, true);

            $existing = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users','tickets','chat_sessions')")->fetchColumn();
            if ($existing > 0 && ($_POST['confirm_overwrite'] ?? '') !== '1') {
                $errors[] = 'This database already contains NovaServe tables. Tick "replace existing tables" to reinstall (all existing data will be deleted).';
                $needsOverwrite = true;
            } else {
                $count = SqlImporter::runFile($pdo, APP_ROOT . '/database/schema.sql');
                $log[] = "Created tables and core data ($count statements).";
                if ($form['sample'] === '1') {
                    $count = SqlImporter::runFile($pdo, APP_ROOT . '/database/sample_data.sql');
                    $log[] = "Imported fictional sample data ($count statements).";
                }

                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, password_hash = ? WHERE id = 1');
                $stmt->execute([$form['admin_name'], mb_strtolower($form['admin_email']), password_hash($adminPassword, PASSWORD_DEFAULT)]);
                $log[] = 'Administrator account configured.';

                $config = [
                    'app' => [
                        'base_url'    => rtrim($form['base_url'], '/'),
                        'env'         => 'production',
                        'key'         => bin2hex(random_bytes(32)),
                        'timezone'    => 'Asia/Kuala_Lumpur',
                        'upload_path' => '',
                    ],
                    'db' => $dbConfig,
                ];
                $php = "<?php\n// Generated by install.php on " . date('Y-m-d H:i:s') . "\nreturn " . var_export($config, true) . ";\n";
                if (file_put_contents($configFile, $php, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write config/config.php. Check folder permissions.');
                }
                @chmod($configFile, 0640);
                $log[] = 'Saved config/config.php (with a new encryption key).';

                file_put_contents($lockFile, 'Installed ' . date('c') . "\n", LOCK_EX);
                $log[] = 'Installer locked (storage/installed.lock).';

                $pdo->prepare('INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (1, ?, ?, ?)')
                    ->execute(['settings_changed', 'Platform installed via web installer', $_SERVER['REMOTE_ADDR'] ?? '']);
                $done = true;
                unset($_SESSION['setup_csrf']);
            }
        } catch (Throwable $e) {
            Logger::error('Installation failed: ' . $e->getMessage());
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
$appUrl = rtrim($form['base_url'], '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install · NovaServe Support</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="ns-install-body">
<main class="ns-install">
    <div class="ns-install-brand">
        <?php require APP_ROOT . '/includes/layout/logo.php'; ?>
        <div><strong>NovaServe Support</strong><span>Installation wizard</span></div>
    </div>

    <?php if ($installed && !$done): ?>
        <div class="ns-install-card text-center">
            <i class="fa-solid fa-lock fa-2x text-warning mb-3"></i>
            <h1 class="h4">Already installed</h1>
            <p class="text-muted">For security, the installer is locked. To reinstall, delete <code>storage/installed.lock</code> on the server (this requires file access), then reload this page.</p>
            <a class="btn ns-btn-primary" href="index.php">Go to the portal</a>
        </div>
    <?php elseif ($done): ?>
        <div class="ns-install-card">
            <div class="text-center mb-3"><i class="fa-solid fa-circle-check fa-3x text-success"></i></div>
            <h1 class="h4 text-center">Installation complete</h1>
            <ul class="ns-install-log">
                <?php foreach ($log as $line): ?><li><i class="fa-solid fa-check"></i><?= e($line) ?></li><?php endforeach; ?>
            </ul>
            <div class="alert alert-info small">
                Sign in with <strong><?= e($form['admin_email']) ?></strong> and the password you chose.
                <?php if ($form['sample'] === '1'): ?><br>Sample staff and customer accounts use the password <code>Demo@12345</code> (see README).<?php endif; ?>
            </div>
            <div class="d-flex gap-2 justify-content-center">
                <a class="btn ns-btn-primary" href="<?= e($appUrl) ?>/login.php">Sign in</a>
                <a class="btn ns-btn-ghost" href="<?= e($appUrl) ?>/index.php">Open the portal</a>
            </div>
        </div>
    <?php else: ?>
        <div class="ns-install-card mb-4">
            <h1 class="h5 mb-3"><i class="fa-solid fa-server me-2"></i>Server requirements</h1>
            <ul class="ns-req-list">
                <?php foreach ($requirements as [$label, $ok, $detail]): ?>
                    <li class="<?= $ok ? 'ok' : 'fail' ?>"><i class="fa-solid <?= $ok ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i><?= e($label) ?> <?= $detail ? '<small>(' . e($detail) . ')</small>' : '' ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <form class="ns-install-card" method="post" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(setup_token()) ?>">
            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <?php if ($log && $errors): ?>
                <ul class="ns-install-log small"><?php foreach ($log as $line): ?><li><i class="fa-solid fa-check"></i><?= e($line) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>

            <h2 class="h5 mb-3"><i class="fa-solid fa-database me-2"></i>Database connection</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-8"><label class="form-label" for="db_host">Host</label><input class="form-control" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="db_port">Port</label><input class="form-control" id="db_port" name="db_port" value="<?= e($form['db_port']) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="db_name">Database name</label><input class="form-control" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="db_user">Username</label><input class="form-control" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="db_pass">Password</label><input class="form-control" type="password" id="db_pass" name="db_pass" autocomplete="new-password"></div>
                <div class="col-12 form-text mt-1">XAMPP default: user <code>root</code> with an empty password. On cPanel, create the database and user in “MySQL Databases” first.</div>
            </div>

            <h2 class="h5 mb-3"><i class="fa-solid fa-user-shield me-2"></i>First administrator</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6"><label class="form-label" for="admin_name">Full name</label><input class="form-control" id="admin_name" name="admin_name" value="<?= e($form['admin_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="admin_email">Email</label><input class="form-control" type="email" id="admin_email" name="admin_email" value="<?= e($form['admin_email']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="admin_password">Password</label><input class="form-control" type="password" id="admin_password" name="admin_password" minlength="10" required autocomplete="new-password"><div class="form-text">10+ characters, letters and numbers.</div></div>
                <div class="col-md-6"><label class="form-label" for="base_url">Base URL</label><input class="form-control" id="base_url" name="base_url" value="<?= e($form['base_url']) ?>"><div class="form-text">Detected automatically. Empty = domain root.</div></div>
            </div>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="sample" name="sample" value="1" <?= $form['sample'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="sample">Import fictional sample data (FAQ, articles, customers, tickets and conversations)</label>
            </div>
            <?php if (!empty($needsOverwrite)): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="confirm_overwrite" name="confirm_overwrite" value="1">
                    <label class="form-check-label text-danger" for="confirm_overwrite">Replace existing tables (deletes all current NovaServe data in this database)</label>
                </div>
            <?php endif; ?>
            <button class="btn ns-btn-primary btn-lg w-100 mt-3" type="submit" <?= $requirementsMet ? '' : 'disabled' ?>><i class="fa-solid fa-rocket me-2"></i>Install NovaServe</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
