<?php

namespace App\Ai\Contracts;

use App\Ai\TextResult;

/**
 * Text generation: translation, transcreation, metadata suggestions (task 7.1, design §18).
 * Every AI call goes through one of the provider interfaces (task 7.4), so the metered
 * decorator (task 7.2) can record cost, reuse cached results and enforce limits in one place.
 *
 * $options: model, temperature, json (bool: ask for a JSON answer), max_tokens,
 * operation (label for the ledger, e.g. 'translate').
 */
interface TranslationProvider
{
    /** @param list<array{role: string, content: string}> $messages */
    public function complete(array $messages, array $options = []): TextResult;
}
