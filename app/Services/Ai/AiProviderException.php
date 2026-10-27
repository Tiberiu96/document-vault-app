<?php

namespace App\Services\Ai;

class AiProviderException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly ?int $retryAfter = null)
    {
        // Fara corp HTTP, prompt, chei sau exceptie anterioara in loguri.
        parent::__construct('AI provider error: '.$reason);
    }
}
