<?php

namespace App\Ai\Contracts;

use App\Ai\ImageResult;

/** Editing images: rebuilding artwork behind removed text (task 7.1). $options: model, size, operation. */
interface ImageEditProvider
{
    public function edit(string $imagePath, string $maskPath, string $prompt, array $options = []): ImageResult;
}
