<?php

namespace App\Jobs;

use App\Models\Book;
use App\Services\Classification\ClassificationAnalyzer;
use App\Services\Classification\DescriptionWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-classify + draft a store description for a Book on import (Req 4.1 / 5.1).
 * Runs deterministic signals + an LLM pass, persists suggestions and a suggested
 * description, and moves the book's classification_status through the state machine.
 * A failure routes to 'failed' (retriable) and NEVER blocks manual metadata entry.
 *
 * V8-safe: reads extracted text only; does not touch the render engine.
 */
class AnalyzeBookClassificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $bookId)
    {
    }

    public function handle(ClassificationAnalyzer $analyzer, DescriptionWriter $writer): void
    {
        $book = Book::find($this->bookId);
        if (!$book) {
            return;
        }

        $book->forceFill(['classification_status' => 'analysing'])->save();

        try {
            $analyzer->analyze($book);
            $writer->draft($book, $book->original_language ?: 'en');

            $book->forceFill(['classification_status' => 'suggested'])->save();
        } catch (\Throwable $e) {
            Log::error("Classification analysis failed for book #{$book->id}: " . $e->getMessage());
            $book->forceFill(['classification_status' => 'failed'])->save();
            throw $e; // let the queue retry (tries=3); manual entry remains available
        }
    }
}
