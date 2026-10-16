<?php

namespace App\Ai;

/** A text answer from a TranslationProvider or VisionProvider (task 7.1). */
final class TextResult
{
    public function __construct(
        public readonly string $text,
        public readonly Usage $usage,
    ) {}

    /** The text decoded as JSON (for calls that asked for a JSON answer); [] if not JSON. */
    public function json(): array
    {
        $decoded = json_decode($this->text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
