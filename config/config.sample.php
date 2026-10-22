<?php
/**
 * NovaServe configuration template.
 *
 * The web installer (install.php) writes config/config.php automatically.
 * For a manual setup, copy this file to config/config.php and edit the values.
 */
return [
    'app' => [
        // Leave empty to auto-detect, or set e.g. '/novaserve-support' or 'https://support.example.com'
        'base_url'    => '',
        // 'production' hides technical errors from visitors, 'development' shows them
        'env'         => 'production',
        // 64 hexadecimal characters. Used to encrypt AI API keys stored in the database.
        // Generate one with: php -r "echo bin2hex(random_bytes(32));"
        'key'         => '',
        'timezone'    => 'Asia/Kuala_Lumpur',
        // Optional absolute path for attachments (recommended: outside public_html on cPanel)
        'upload_path' => '',
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'novaserve_support',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];
