<?php
declare(strict_types=1);

/**
 * Contract for any external AI provider. Add a new provider by implementing this interface
 * and registering it in AiEngine::makeProvider().
 */
interface AiProviderInterface
{
    /**
     * @param array<array{role:string,content:string}> $messages Chat messages (system/user/assistant)
     * @return array{content:string, model:string}
     * @throws AiProviderException when the provider is unreachable, times out or returns an error
     */
    public function chat(array $messages): array;

    public function name(): string;
}
