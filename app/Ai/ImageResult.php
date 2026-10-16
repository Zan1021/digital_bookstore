<?php

namespace App\Ai;

/** An edited image from an ImageEditProvider (task 7.1). */
final class ImageResult
{
    /** One of $bytes or $url is set: providers return either the image or a link to it. */
    public function __construct(
        public readonly ?string $bytes,
        public readonly ?string $url,
        public readonly Usage $usage,
    ) {}
}
