<?php

namespace App\Ai;

/**
 * What one AI call consumed (task 7.1, design §18): who answered, which model, for what
 * operation, the provider's own units (tokens, images, characters) and how long it took.
 * The metered decorator (task 7.2) prices it into ai_ledger.
 */
final class Usage
{
    /** @param array<string, int> $units e.g. prompt_tokens, completion_tokens, characters */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $operation,
        public readonly array $units,
        public readonly int $durationMs,
    ) {}
}
