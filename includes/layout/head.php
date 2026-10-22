<?php
/** Shared <head> for portal and admin layouts. Expects $pageTitle and optional $extraHead. */
$siteName = (string) Settings::get('site_name', 'NovaServe Support');
?>
<!doctype html>
<html lang="<?= e(current_lang() === 'zh' ? 'zh-Hans' : current_lang()) ?>">
<head>
    <meta charset="utf-8">
    <script>document.documentElement.classList.add('ns-js');</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="<?= e(Settings::get('company_tagline')) ?>">
    <title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $siteName : $siteName) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('images/logo.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $extraHead ?? '' ?>
    <script>
        window.NS = <?= json_encode([
            'baseUrl'    => base_url(),
            'csrf'       => csrf_token(),
            'lang'       => current_lang(),
            'speechLang' => Lang::AVAILABLE[current_lang()]['speech'],
            'loggedIn'   => Auth::check(),
            'i18n'       => Lang::group('js.'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
</head>
