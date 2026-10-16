<?php

namespace App\Scene;

/** What a compile produced: where the file is, its version, and whether the content changed. */
final class SceneResult
{
    public function __construct(
        public readonly string $path,
        public readonly int $version,
        public readonly bool $changed,
    ) {}
}
