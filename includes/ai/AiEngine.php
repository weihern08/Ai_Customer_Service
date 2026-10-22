<?php
declare(strict_types=1);

/**
 * Orchestrates one AI answer:
 *
 *   analyse text (language / intent / sentiment / keywords)
 *        ↓
 *   retrieve FAQ + knowledge-base context (RAG)
 *        ↓
 *   Primary AI  →(fails)→  Fallback AI  →(fails)→  Local knowledge responder
 *        ↓
 *   confidence score + human-escalation recommendation
 *
 * The engine never throws because of an AI outage; the local responder always produces an answer.
 */
final class AiEngine
{
    public const SLOT_LABELS = [
        'primary'  => 'Primary AI',
        'fallback' => 'Fallback AI',
        'local'    => 'Local Knowledge',
        'human'    => 'Human Agent',
    ];

    /**
     * @param array $history Previous chat_messages rows (oldest first)
     */
    public function respond(string $message, array $history, string $uiLanguage, ?array $analysis = null): array
    {
        $analysis ??= TextAnalyzer::analyze($message, $uiLanguage);
        // Reply in the customer's language only when multi-language mode is on
        $language = Lang::isMultilingual() ? $analysis['language'] : 'en';
        $sources = KnowledgeRetriever::search($analysis['keywords']);
        $attempts = [];
        $result = null;

        // A direct request for a human is handled by rules: instant and predictable.
        $useAi = $analysis['intent'] !== 'human_support' && Settings::bool('ai_enabled');

        if ($useAi) {
            $prompt = PromptBuilder::build($message, $sources, $history, $language);
            foreach (['primary', 'fallback'] as $slot) {
                if ($slot === 'fallback' && !Settings::bool('ai_fallback_enabled')) {
                    continue;
                }
                $started = microtime(true);
                try {
                    $provider = self::makeProvider($slot);
                    $output = $provider->chat($prompt);
                    $attempts[] = self::attempt($slot, $output['model'], 'success', $started);
                    $result = $this->fromAiOutput($output, $slot, $analysis, $sources);
                    break;
                } catch (AiProviderException $e) {
                    if (!$e->notConfigured) {
                        $attempts[] = self::attempt($slot, (string) Settings::get("ai_{$slot}_model"), 'failed', $started, $e->getMessage());
                        Logger::warning("AI $slot provider failed", ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        if ($result === null) {
            $started = microtime(true);
            $local = LocalResponder::respond($message, $analysis, $sources, $language);
            $attempts[] = self::attempt('local', $local['model'], 'success', $started);
            $result = [
                'reply'      => $local['reply'],
                'provider'   => 'local',
                'model'      => $local['model'],
                'confidence' => $local['confidence'],
            ];
        }

        $threshold = (int) Settings::get('ai_confidence_threshold', 60);
        $reason = match (true) {
            $analysis['intent'] === 'human_support' => 'customer_request',
            $analysis['sentiment'] === 'angry'      => 'negative_sentiment',
            $result['confidence'] < $threshold      => 'low_confidence',
            default                                 => null,
        };

        return $result + [
            'intent'           => $analysis['intent'],
            'sentiment'        => $analysis['sentiment'],
            'language'         => $language,
            'keywords'         => $analysis['keywords'],
            'sources'          => array_map(static fn ($s) => [
                'type'  => $s['type'],
                'id'    => $s['id'],
                'title' => $s['title'],
                'url'   => $s['url'],
            ], $sources),
            'recommend_human'  => $reason !== null,
            'recommend_reason' => $reason,
            'threshold'        => $threshold,
            'attempts'         => $attempts,
        ];
    }

    private function fromAiOutput(array $output, string $slot, array $analysis, array $sources): array
    {
        $content = $output['content'];
        $unverified = (bool) preg_match('/\[\[\s*UNVERIFIED\s*\]\]/i', $content);
        $content = trim((string) preg_replace('/\[\[\s*UNVERIFIED\s*\]\]/i', '', $content));
        $relevance = $sources[0]['relevance'] ?? 0.0;

        $confidence = match (true) {
            $unverified                                                => (int) round(30 + 10 * $relevance),
            $analysis['intent'] === 'greeting'                         => 92,
            in_array($analysis['intent'], ['opening_hours', 'location'], true) => 88,
            $sources !== []                                            => (int) round(60 + 38 * $relevance),
            default                                                    => 50,
        };

        return [
            'reply'      => $content,
            'provider'   => $slot,
            'model'      => $output['model'],
            'confidence' => min(98, $confidence),
        ];
    }

    /**
     * Creates the configured provider for a slot ("primary" or "fallback").
     * @throws AiProviderException when the slot is not configured
     */
    public static function makeProvider(string $slot): AiProviderInterface
    {
        $url = trim((string) Settings::get("ai_{$slot}_url", ''));
        $key = trim((string) Settings::get("ai_{$slot}_key", ''));
        $model = trim((string) Settings::get("ai_{$slot}_model", ''));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isLocalServer = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($url === '' || $model === '' || !filter_var($url, FILTER_VALIDATE_URL) || ($key === '' && !$isLocalServer)) {
            throw AiProviderException::notConfigured($slot);
        }
        return new OpenAiCompatibleProvider(
            $url,
            $key,
            $model,
            (float) Settings::get('ai_temperature', 0.3),
            (int) Settings::get('ai_max_tokens', 500),
            (int) Settings::get('ai_timeout_seconds', 20)
        );
    }

    private static function attempt(string $slot, string $model, string $outcome, float $started, ?string $error = null): array
    {
        return [
            'slot'       => $slot,
            'model'      => mb_substr($model, 0, 100),
            'outcome'    => $outcome,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'error'      => $error ? mb_substr($error, 0, 500) : null,
        ];
    }
}
