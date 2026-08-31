<?php

namespace App\Training\Support;

/**
 * Resultado de TrainingAccessGate::authorize(). Value object simple, mismo
 * estilo que App\Core\Memory\ContextFragment / App\Core\Messaging\ExecutionContext.
 *
 * $reason es una de: 'no_access', 'access_expired', 'safety_flagged' — o
 * null cuando $allowed es true.
 */
final class AccessGateResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
