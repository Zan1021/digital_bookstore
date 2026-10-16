<?php

namespace App\Ai\Contracts;

use App\Ai\SpeechResult;

/** Speech: narration (task 7.1). $options: model, operation. */
interface TtsProvider
{
    /** @return list<array{voice_id: string, name: string, category: string, labels: array, preview_url: ?string}> */
    public function voices(): array;

    /** @param array<string, mixed> $settings provider voice settings (stability, style, ...) */
    public function speak(string $text, string $voiceId, array $settings = [], array $options = []): SpeechResult;
}
