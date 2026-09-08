<?php

namespace App\Jobs;

use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Services\PdfTranslationService;
use App\Services\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Queued translation of a single edition (spec: gated-translation-narration-flow Req 3).
 *
 * Flow: TRANSLATING → (translate text) → RENDERING → (render + gate) →
 *       READY_FOR_REVIEW | NEEDS_LAYOUT_REVIEW (fail-closed).
 *
 * Idempotent: re-running for the same edition replaces its translated pages, rendered
 * PDF, and comparison renders rather than duplicating them (the underlying services use
 * updateOrCreate + overwrite the rendered file; we clear stale render artifacts first).
 *
 * Progress is mirrored onto a ProcessingJob row (type 'translation') for the UI to poll.
 */
class TranslateEditionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 20;
    public int $timeout = 900; // 15 min — translation + render is API-bound

    public function __construct(public int $translationId)
    {
    }

    public function handle(TranslationService $translator, PdfTranslationService $renderer): void
    {
        $edition = Translation::with('book')->find($this->translationId);
        if (! $edition || ! $edition->book) {
            return;
        }
        $book = $edition->book;

        $job = ProcessingJob::updateOrCreate(
            ['book_id' => $book->id, 'type' => 'translation'],
            ['status' => 'processing', 'progress' => 0, 'started_at' => now(),
             'error_message' => null, 'completed_at' => null,
             'details' => ['language' => $edition->language_code]],
        );

        try {
            // Fresh-start this edition's render artifacts so a re-run cannot leave stale
            // pages/PDFs behind (idempotency). Translated-page rows are replaced via
            // updateOrCreate inside the translator.
            $this->clearRenderArtifacts($edition);

            $edition->forceFill(['render_status' => Translation::STATE_TRANSLATING])->save();
            $job->update(['progress' => 10]);

            // 1. Translate text via the STRUCTURE-AWARE manifest path. This builds the
            //    per-id item_translations store (fix B) that the contract renderer needs
            //    for vocabulary/table pages — the flat translate() path leaves those
            //    unstructured and the cells collapse. Falls back to flat internally only
            //    when no scene-graph manifest exists.
            $translator->translateWithManifest($book, $edition->language_code);
            $job->update(['progress' => 60]);

            // 2. Render + hard-constraint gate. createTranslatedPdf persists
            //    render_status (READY_FOR_REVIEW / NEEDS_LAYOUT_REVIEW) and qa_report,
            //    including the Fix C English-leak guard. It RETURNS the output path,
            //    which the caller must persist onto the edition.
            $edition->forceFill(['render_status' => Translation::STATE_RENDERING])->save();
            $outputPath = $renderer->createTranslatedPdf($book, $edition->fresh());
            $job->update(['progress' => 95]);

            $edition->refresh();
            $edition->forceFill([
                'rendered_pdf_path' => $outputPath,
                'status' => $edition->isPublishable() ? 'rendered' : 'needs_review',
            ])->save();
            $job->update([
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now(),
                'details' => [
                    'language' => $edition->language_code,
                    'render_status' => $edition->render_status,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("TranslateEditionJob failed for edition #{$edition->id}: " . $e->getMessage());
            // Route to a language-review state so a failed edition is visible and NOT
            // silently publishable; keep it retryable.
            $edition->forceFill([
                'render_status' => Translation::STATE_NEEDS_LANGUAGE_REVIEW,
                'qa_report' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            ])->save();
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e; // let the queue retry (tries=2)
        }
    }

    /**
     * Remove this edition's prior rendered PDF and comparison renders so a re-run is
     * a clean regeneration, not an accumulation.
     */
    private function clearRenderArtifacts(Translation $edition): void
    {
        $disk = Storage::disk('public');
        $pdfs = array_filter([
            $edition->rendered_pdf_path,
            "books/translated/{$edition->book_id}_{$edition->language_code}.pdf",
        ]);
        foreach ($pdfs as $pdf) {
            if ($disk->exists($pdf)) {
                $disk->delete($pdf);
            }
        }
        $disk->deleteDirectory("books/comparison/{$edition->book_id}_{$edition->language_code}");
    }
}
