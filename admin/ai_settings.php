<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('ai.configure');
$errors = [];

$validEndpoint = static function (string $url): bool {
    if ($url === '') {
        return true;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = (string) parse_url($url, PHP_URL_HOST);
    // Remote providers must use HTTPS so the API key is never sent in clear text
    return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
};

if (is_post()) {
    require_csrf();
    $new = [
        'ai_enabled'              => input('ai_enabled') === '1' ? '1' : '0',
        'ai_assistant_name'       => trim((string) input('ai_assistant_name')),
        'ai_primary_url'          => trim((string) input('ai_primary_url')),
        'ai_primary_model'        => trim((string) input('ai_primary_model')),
        'ai_fallback_enabled'     => input('ai_fallback_enabled') === '1' ? '1' : '0',
        'ai_fallback_url'         => trim((string) input('ai_fallback_url')),
        'ai_fallback_model'       => trim((string) input('ai_fallback_model')),
        'ai_temperature'          => (string) input('ai_temperature'),
        'ai_max_tokens'           => (string) input('ai_max_tokens'),
        'ai_timeout_seconds'      => (string) input('ai_timeout_seconds'),
        'ai_history_messages'     => (string) input('ai_history_messages'),
        'ai_confidence_threshold' => (string) input('ai_confidence_threshold'),
        'ai_auto_escalate_angry'  => input('ai_auto_escalate_angry') === '1' ? '1' : '0',
        'ai_system_prompt'        => trim((string) input('ai_system_prompt')),
    ];

    if (mb_strlen($new['ai_assistant_name']) < 2 || mb_strlen($new['ai_assistant_name']) > 40) {
        $errors['ai_assistant_name'] = 'Use 2 to 40 characters.';
    }
    foreach (['primary', 'fallback'] as $slot) {
        if (!$validEndpoint($new["ai_{$slot}_url"])) {
            $errors["ai_{$slot}_url"] = 'Enter a valid HTTPS URL (HTTP is only allowed for localhost).';
        }
        if ($new["ai_{$slot}_model"] !== '' && !preg_match('#^[\w.\-:/@]{1,100}$#', $new["ai_{$slot}_model"])) {
            $errors["ai_{$slot}_model"] = 'Model names may contain letters, numbers and . - _ : / @ only.';
        }
    }
    $ranges = [
        'ai_temperature'          => [0, 2, 'Temperature must be between 0 and 2.'],
        'ai_max_tokens'           => [50, 4000, 'Max tokens must be between 50 and 4000.'],
        'ai_timeout_seconds'      => [5, 90, 'Timeout must be between 5 and 90 seconds.'],
        'ai_history_messages'     => [0, 20, 'History must be between 0 and 20 messages.'],
        'ai_confidence_threshold' => [0, 100, 'Threshold must be between 0 and 100.'],
    ];
    foreach ($ranges as $key => [$min, $max, $message]) {
        if (!is_numeric($new[$key]) || (float) $new[$key] < $min || (float) $new[$key] > $max) {
            $errors[$key] = $message;
        }
    }
    if (mb_strlen($new['ai_system_prompt']) > 8000) {
        $errors['ai_system_prompt'] = 'The system prompt must be 8,000 characters or fewer.';
    }

    if (!$errors) {
        $changed = [];
        foreach ($new as $key => $value) {
            if ((string) Settings::get($key, '') !== $value) {
                Settings::set($key, $value, 'ai');
                $changed[] = $key;
            }
        }
        // API keys: blank = keep current value, "clear" checkbox removes it
        foreach (['primary', 'fallback'] as $slot) {
            $keyName = "ai_{$slot}_key";
            if (input("clear_{$slot}_key") === '1') {
                Settings::set($keyName, '', 'ai');
                $changed[] = "$keyName (cleared)";
            } elseif (($submitted = trim((string) ($_POST[$keyName] ?? ''))) !== '') {
                Settings::set($keyName, $submitted, 'ai');
                $changed[] = "$keyName (replaced)";
            }
        }
        if ($changed) {
            AuditLog::record('ai_config_changed', 'Changed: ' . implode(', ', $changed));
        }
        flash('success', $changed ? 'AI configuration saved.' : 'No changes to save.');
        redirect('admin/ai_settings.php');
    }
}

$v = static fn (string $key) => is_post() && isset($_POST[$key]) && !in_array($key, Settings::SECRET_KEYS, true)
    ? (string) $_POST[$key]
    : (string) Settings::get($key, '');
$primaryKey = (string) Settings::get('ai_primary_key', '');
$fallbackKey = (string) Settings::get('ai_fallback_key', '');
$cls = static fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
$err = static fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';

$pageTitle = 'AI Configuration';
$pageSubtitle = 'Providers, behaviour and escalation rules for the AI assistant.';
$activeNav = 'ai_settings';
require dirname(__DIR__) . '/includes/layout/admin_header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i>Please correct the highlighted fields.</div><?php endif; ?>
<form method="post" novalidate autocomplete="off">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="ns-card mb-4">
                <div class="ns-card-head"><h2>General</h2></div>
                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <div class="form-check form-switch fs-6">
                            <input class="form-check-input" type="checkbox" id="ai_enabled" name="ai_enabled" value="1" <?= $v('ai_enabled') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="ai_enabled">AI responses enabled</label>
                        </div>
                        <div class="form-text">When off, the assistant answers only from the FAQ and knowledge base (local mode).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ai_assistant_name">Assistant name</label>
                        <input class="form-control<?= $cls('ai_assistant_name') ?>" id="ai_assistant_name" name="ai_assistant_name" value="<?= e($v('ai_assistant_name')) ?>">
                        <?= $err('ai_assistant_name') ?>
                    </div>
                </div>
            </div>

            <?php foreach (['primary' => ['Primary AI provider', $primaryKey], 'fallback' => ['Fallback AI provider', $fallbackKey]] as $slot => [$title, $currentKey]): ?>
                <div class="ns-card mb-4">
                    <div class="ns-card-head">
                        <h2><?= e($title) ?></h2>
                        <button type="button" class="btn btn-sm ns-btn-ghost" data-ns-ai-test="<?= e($slot) ?>" data-url="<?= e(url('api/admin_ai_test.php')) ?>"><i class="fa-solid fa-plug-circle-check me-1"></i>Test saved settings</button>
                    </div>
                    <?php if ($slot === 'fallback'): ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="ai_fallback_enabled" name="ai_fallback_enabled" value="1" <?= $v('ai_fallback_enabled') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ai_fallback_enabled">Use this provider when the primary provider fails</label>
                        </div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="ai_<?= $slot ?>_url">Chat completions endpoint URL</label>
                            <input class="form-control<?= $cls("ai_{$slot}_url") ?>" id="ai_<?= $slot ?>_url" name="ai_<?= $slot ?>_url" value="<?= e($v("ai_{$slot}_url")) ?>" placeholder="https://api.example.com/v1/chat/completions">
                            <?= $err("ai_{$slot}_url") ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ai_<?= $slot ?>_model">Model</label>
                            <input class="form-control<?= $cls("ai_{$slot}_model") ?>" id="ai_<?= $slot ?>_model" name="ai_<?= $slot ?>_model" value="<?= e($v("ai_{$slot}_model")) ?>" placeholder="model-name">
                            <?= $err("ai_{$slot}_model") ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="ai_<?= $slot ?>_key">API key</label>
                            <input class="form-control" type="password" id="ai_<?= $slot ?>_key" name="ai_<?= $slot ?>_key" autocomplete="new-password"
                                   placeholder="<?= $currentKey !== '' ? e('Saved: ' . Crypto::mask($currentKey) . ' — leave blank to keep') : 'Not set' ?>">
                            <div class="d-flex justify-content-between flex-wrap gap-2 mt-1">
                                <span class="form-text mt-0"><i class="fa-solid fa-lock me-1"></i>Stored encrypted on the server and never sent to the browser.</span>
                                <?php if ($currentKey !== ''): ?>
                                    <div class="form-check form-check-inline small m-0">
                                        <input class="form-check-input" type="checkbox" id="clear_<?= $slot ?>_key" name="clear_<?= $slot ?>_key" value="1">
                                        <label class="form-check-label" for="clear_<?= $slot ?>_key">Remove saved key</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="ns-test-result mt-3" data-ns-ai-test-result="<?= e($slot) ?>" hidden></div>
                </div>
            <?php endforeach; ?>

            <div class="ns-card">
                <div class="ns-card-head"><h2>System prompt</h2><button type="button" class="btn btn-sm ns-btn-ghost" data-ns-reset-prompt>Use built-in default</button></div>
                <textarea class="form-control ns-mono<?= $cls('ai_system_prompt') ?>" id="ai_system_prompt" name="ai_system_prompt" rows="14"><?= e($v('ai_system_prompt')) ?></textarea>
                <?= $err('ai_system_prompt') ?>
                <div class="form-text">Defines the assistant's personality and rules. Safety rules, verified company facts, the retrieved knowledge and the reply language are always added automatically and cannot be removed here.</div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="ns-card mb-4">
                <div class="ns-card-head"><h2>Generation</h2></div>
                <?php foreach ([
                    'ai_temperature' => ['Temperature', '0.1', 'Lower = more factual and consistent. 0.2–0.4 recommended.'],
                    'ai_max_tokens' => ['Maximum output tokens', '1', 'Upper limit for the length of each reply.'],
                    'ai_timeout_seconds' => ['Request timeout (seconds)', '1', 'After this, the next provider in the chain is tried.'],
                    'ai_history_messages' => ['Conversation memory (messages)', '1', 'Previous messages sent with each question.'],
                ] as $key => [$label, $step, $help]): ?>
                    <div class="mb-3">
                        <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        <input class="form-control<?= $cls($key) ?>" type="number" step="<?= e($step) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($v($key)) ?>">
                        <?= $err($key) ?>
                        <div class="form-text"><?= e($help) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="ns-card mb-4">
                <div class="ns-card-head"><h2>Escalation</h2></div>
                <label class="form-label" for="ai_confidence_threshold">Confidence threshold: <strong data-ns-range-out="ai_confidence_threshold"><?= e($v('ai_confidence_threshold')) ?>%</strong></label>
                <input class="form-range<?= $cls('ai_confidence_threshold') ?>" type="range" min="0" max="100" step="5" id="ai_confidence_threshold" name="ai_confidence_threshold" value="<?= e($v('ai_confidence_threshold')) ?>">
                <?= $err('ai_confidence_threshold') ?>
                <div class="form-text mb-3">Answers below this score show a “talk to a human” recommendation.</div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="ai_auto_escalate_angry" name="ai_auto_escalate_angry" value="1" <?= $v('ai_auto_escalate_angry') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ai_auto_escalate_angry">Automatically queue angry customers for a human</label>
                </div>
                <div class="form-text">Support staff are always notified when anger is detected.</div>
            </div>
            <div class="ns-card mb-4">
                <div class="ns-card-head"><h2>Compatible providers</h2></div>
                <p class="small text-muted">Any API using the OpenAI chat-completions format works. Examples:</p>
                <ul class="ns-provider-list small">
                    <li><strong>OpenAI</strong><code>https://api.openai.com/v1/chat/completions</code></li>
                    <li><strong>Google Gemini</strong><code>https://generativelanguage.googleapis.com/v1beta/openai/chat/completions</code></li>
                    <li><strong>Groq</strong><code>https://api.groq.com/openai/v1/chat/completions</code></li>
                    <li><strong>OpenRouter</strong><code>https://openrouter.ai/api/v1/chat/completions</code></li>
                    <li><strong>Ollama (local)</strong><code>http://localhost:11434/v1/chat/completions</code></li>
                </ul>
            </div>
            <div class="d-grid"><button class="btn ns-btn-primary btn-lg" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save configuration</button></div>
        </div>
    </div>
</form>
<script type="application/json" id="nsDefaultPrompt"><?= json_encode(PromptBuilder::DEFAULT_SYSTEM_PROMPT, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require dirname(__DIR__) . '/includes/layout/admin_footer.php'; ?>
