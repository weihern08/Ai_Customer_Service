<?php
/**
 * POST /api/admin_ai_test.php { slot: "primary" | "fallback" }
 * Sends a tiny test prompt using the SAVED settings. The key itself never leaves the server.
 */
define('NS_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    json_error('Method not allowed.', 405);
}
Auth::requirePermission('ai.configure');
require_csrf();

$slot = (string) (json_input()['slot'] ?? '');
if (!in_array($slot, ['primary', 'fallback'], true)) {
    json_error('Unknown provider slot.', 422);
}

$started = microtime(true);
try {
    $provider = AiEngine::makeProvider($slot);
    $result = $provider->chat([
        ['role' => 'system', 'content' => 'You are a connectivity test. Reply with exactly: OK'],
        ['role' => 'user', 'content' => 'Connection test'],
    ]);
    json_response([
        'ok'         => true,
        'model'      => $result['model'],
        'reply'      => mb_substr($result['content'], 0, 200),
        'latency_ms' => (int) round((microtime(true) - $started) * 1000),
    ]);
} catch (AiProviderException $e) {
    json_response([
        'ok'         => false,
        'error'      => $e->notConfigured ? 'This provider is not fully configured (URL, model and API key are required).' : $e->getMessage(),
        'latency_ms' => (int) round((microtime(true) - $started) * 1000),
    ]);
}
