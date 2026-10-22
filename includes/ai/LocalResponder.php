<?php
declare(strict_types=1);

/**
 * Last line of the fallback chain: answers without any external AI,
 * using only verified data (settings + FAQ + knowledge base).
 */
final class LocalResponder
{
    public static function respond(string $message, array $analysis, array $sources, string $language): array
    {
        $t = static fn (string $key, array $replace = []) => Lang::get($key, $replace, $language);
        $intent = $analysis['intent'];

        if ($intent === 'human_support') {
            return ['reply' => $t('ai.local.human'), 'confidence' => 80, 'model' => 'rules'];
        }

        if ($intent === 'greeting' && !$sources) {
            $isThanks = (bool) preg_match('/thank|thanks|terima kasih|谢谢|感谢/iu', $message);
            return [
                'reply'      => $t($isThanks ? 'ai.local.thanks' : 'ai.local.greeting', ['name' => Settings::get('ai_assistant_name', 'Nova')]),
                'confidence' => 90,
                'model'      => 'rules',
            ];
        }

        if ($intent === 'opening_hours') {
            return [
                'reply'      => $t('ai.local.hours', ['hours' => Settings::get('business_hours')]),
                'confidence' => 90,
                'model'      => 'settings',
            ];
        }

        if ($intent === 'location') {
            return [
                'reply'      => $t('ai.local.location', [
                    'address' => Settings::get('company_address'),
                    'phone'   => Settings::get('support_phone'),
                    'email'   => Settings::get('support_email'),
                ]),
                'confidence' => 88,
                'model'      => 'settings',
            ];
        }

        if ($sources) {
            $top = $sources[0];
            $content = $top['type'] === 'faq' ? $top['content'] : str_limit_keep_lines($top['content'], 700);
            $reply = $t('ai.local.found') . "\n\n**" . $top['title'] . "**\n" . trim($content);
            if (count($sources) > 1) {
                $reply .= "\n\n" . $t('ai.local.related') . "\n";
                foreach (array_slice($sources, 1) as $source) {
                    $reply .= '- ' . $source['title'] . "\n";
                }
            }
            if ($language !== 'en') {
                $reply .= "\n\n" . $t('ai.local.english_note');
            }
            return [
                'reply'      => rtrim($reply),
                'confidence' => (int) min(88, round(35 + 55 * $top['relevance'])),
                'model'      => 'knowledge-base',
            ];
        }

        return [
            'reply'      => $t('ai.local.unknown', ['email' => Settings::get('support_email'), 'phone' => Settings::get('support_phone')]),
            'confidence' => 15,
            'model'      => 'knowledge-base',
        ];
    }
}
