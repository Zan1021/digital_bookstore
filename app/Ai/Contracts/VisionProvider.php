<?php

namespace App\Ai\Contracts;

use App\Ai\TextResult;

/** Reading images: visual QA, locating text in artwork (task 7.1). $options as TranslationProvider. */
interface VisionProvider
{
    /** @param list<string> $imagePaths local image files sent after the prompt, in order */
    public function analyze(string $system, string $prompt, array $imagePaths, array $options = []): TextResult;
}
