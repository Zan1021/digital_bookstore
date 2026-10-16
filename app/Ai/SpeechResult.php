<?php

namespace App\Ai;

/** Spoken audio from a TtsProvider (task 7.1). */
final class SpeechResult
{
    /** @param array<string, mixed>|null $alignment character timings when the provider gives them */
    public function __construct(
        public readonly string $audio,
        public readonly ?array $alignment,
        public readonly Usage $usage,
    ) {}
}
