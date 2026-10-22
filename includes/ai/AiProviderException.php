<?php
declare(strict_types=1);

final class AiProviderException extends RuntimeException
{
    /** True when the provider was skipped because it is not configured (not counted as a failure). */
    public bool $notConfigured = false;

    public static function notConfigured(string $slot): self
    {
        $e = new self("The $slot AI provider is not configured.");
        $e->notConfigured = true;
        return $e;
    }
}
