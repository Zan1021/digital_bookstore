<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\Translation;
use App\Services\PdfTranslationService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Engine compare/review screen.
 *
 * unified-rendering-and-testing spec Req 1 (A2): this screen previously shelled out to
 * `python pdf_translate_v8.py replace` DIRECTLY, built translations from whole-page
 * `translated_text`, and showed "ready" purely because a PDF file existed — bypassing the
 * contract resolution, illustration pass, QA gate, fingerprint and readiness authority that
 * production uses. It now routes through the SAME PdfTranslationService::createTranslatedPdf
 * every other entry point uses, and derives its status from Translation::readiness() — not
 * from file existence.
 */
class EngineCompare extends Component
{
    public Book $book;
    public ?string $v8Status = null;
    public ?string $v8PdfUrl = null;
    public ?array $v8Report = null;
    public string $selectedLanguage = 'af';
    public bool $rendering = false;

    public function mount(Book $book)
    {
        $this->book = $book;
        $this->refreshStatus();
    }

    /**
     * Derive the display status from the edition's readiness — NEVER from mere file
     * existence (A2.3). A rendered PDF that has not passed its bound checks shows
     * "testing-pending", not "ready".
     */
    private function refreshStatus(): void
    {
        $translation = $this->currentTranslation();
        if (! $translation || ! $translation->rendered_pdf_path) {
            $this->v8Status = null;
            $this->v8PdfUrl = null;
            $this->v8Report = null;
            return;
        }

        // Version the viewer URL by the output hash so a stale PNG/PDF is never paired
        // with a newer candidate (A2.4). Falls back to updated_at when no hash yet.
        $version = $translation->output_sha256 ?: (string) $translation->updated_at?->timestamp;
        $this->v8PdfUrl = Storage::disk('public')->url($translation->rendered_pdf_path) . '?v=' . $version;
        $this->v8Report = $translation->decodeQaReport();

        if (in_array($translation->render_status, Translation::BLOCKING_RENDER_STATES, true)) {
            $this->v8Status = 'needs-review';
        } elseif ($translation->canBePublished()) {
            $this->v8Status = 'ready';
        } else {
            $this->v8Status = 'testing-pending';
        }
    }

    private function currentTranslation(): ?Translation
    {
        return Translation::where('book_id', $this->book->id)
            ->where('language_code', $this->selectedLanguage)
            ->first();
    }

    public function renderV8()
    {
        $this->rendering = true;
        $this->v8Status = 'rendering...';

        $translation = $this->currentTranslation();
        if (! $translation) {
            $this->v8Status = "failed: No {$this->selectedLanguage} translation found";
            $this->rendering = false;
            return;
        }

        try {
            // The SAME production path: resolves per-ID item_translations, runs the
            // illustration pass, the QA gate, computes the fingerprint + output hash, and
            // persists render_status/qa_report (A2.1/A2.2/A2.3). No direct Python here.
            $service = app(PdfTranslationService::class);
            $service->createTranslatedPdf($this->book, $translation);
            $this->refreshStatus();
        } catch (\Throwable $e) {
            $this->v8Status = 'failed: ' . $e->getMessage();
        }

        $this->rendering = false;
    }

    public function render()
    {
        return view('livewire.admin.engine-compare')->layout('layouts.admin');
    }
}
