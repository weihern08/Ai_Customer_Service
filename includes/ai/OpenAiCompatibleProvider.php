<?php
declare(strict_types=1);

/**
 * Provider for any REST API that implements the OpenAI "chat completions" format.
 * Works with OpenAI, Google Gemini (OpenAI-compatible endpoint), Groq, OpenRouter,
 * DeepSeek, Mistral, a local Ollama server, and many others.
 */
final class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly float $temperature = 0.3,
        private readonly int $maxTokens = 500,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function name(): string
    {
        return $this->model;
    }

    public function chat(array $messages): array
    {
        if (!function_exists('curl_init')) {
            throw new AiProviderException('The PHP cURL extension is not enabled.');
        }
        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
        ], JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($body === false) {
            $reason = $curlErrno === CURLE_OPERATION_TIMEDOUT
                ? "Request timed out after {$this->timeoutSeconds} seconds"
                : 'Connection error: ' . $curlError;
            throw new AiProviderException($reason);
        }

        $data = json_decode((string) $body, true);
        if ($status >= 400) {
            $detail = is_array($data) ? (string) ($data['error']['message'] ?? $data['error'] ?? '') : '';
            throw new AiProviderException("HTTP $status from provider" . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : ''));
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new AiProviderException('The provider returned an empty or unexpected response.');
        }

        return [
            'content' => trim($content),
            'model'   => (string) ($data['model'] ?? $this->model),
        ];
    }
}
