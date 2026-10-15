<?php

namespace App\Services;

use App\Assets\AssetStorage;
use App\Models\Book;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Store cover images (task 3.2): page 1 of the private source PDF rendered to a JPEG on
 * the PUBLIC disk (covers/{book}.jpg). The cover is the only book file the store may
 * expose; the PDF itself stays private.
 */
class CoverService
{
    /** Timed as intake.cover (task 5.3). */
    public function generate(Book $book): bool
    {
        return \App\Support\StageTimer::run('intake.cover', fn () => $this->generateUntimed($book), $book->id);
    }

    private function generateUntimed(Book $book): bool
    {
        if (! $book->pdf_path || ! AssetStorage::disk()->exists($book->pdf_path)) {
            return false;
        }

        $relative = "covers/{$book->id}.jpg";
        $trim = $book->getCropBoxFractions(); // same trim as the reader: no crop marks/bleed
        $process = new Process([
            'python', base_path('scripts/render_cover.py'),
            AssetStorage::disk()->path($book->pdf_path),
            Storage::disk('public')->path($relative),
            '--trim', implode(',', [$trim['left'], $trim['top'], $trim['right'], $trim['bottom']]),
        ]);
        $process->setTimeout(60);
        $process->run();

        $result = json_decode(trim($process->getOutput()), true);
        if (! $process->isSuccessful() || ! ($result['ok'] ?? false)) {
            Log::warning('Cover generation failed', [
                'book_id' => $book->id,
                'error' => $result['error'] ?? trim($process->getErrorOutput()),
            ]);

            return false;
        }

        // Quietly: saving the cover must not re-trigger the crop-changed hook on Book.
        $book->forceFill(['cover_image' => $relative])->saveQuietly();

        return true;
    }
}
