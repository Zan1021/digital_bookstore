<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\NarrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Narration gate (spec Req 2, 5). The source language narrates freely; a translated
 * edition is refused until APPROVED. The refusal happens BEFORE any API call, so it
 * is safe to assert against a real NarrationService.
 */
class NarrationGateTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create([
            'title' => 'Narr Book', 'original_language' => 'en', 'page_count' => 2,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    private function approvedEdition(Book $book, string $lang): Translation
    {
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => $lang, 'language_name' => strtoupper($lang),
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => ['publishable' => true],
        ]);
        foreach (range(1, 2) as $n) {
            $page = BookPage::create(['book_id' => $book->id, 'page_number' => $n, 'extracted_text' => "EN {$n}"]);
            TranslatedPage::create([
                'translation_id' => $t->id, 'book_page_id' => $page->id,
                'page_number' => $n, 'translated_text' => "T {$n}",
            ]);
            $t->fresh()->setPageApproval($n, true);
        }
        $t->fresh()->markApproved();
        return $t->fresh();
    }

    public function test_translated_edition_narration_refused_before_approval(): void
    {
        $book = $this->book();
        Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);

        $service = app(NarrationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not approved for narration');
        $service->narrate($book, 'af', 'voice-1', 'Voice One');
    }

    public function test_approved_edition_passes_the_gate(): void
    {
        $book = $this->book();
        $edition = $this->approvedEdition($book, 'af');

        // The gate itself must pass now (we assert the model-level gate; the actual
        // audio generation is an external API and is covered by the service separately).
        $this->assertTrue($edition->isApprovedForNarration());
    }

    public function test_source_language_is_never_gated(): void
    {
        $book = $this->book();
        // No translation exists at all — English must still be narratable.
        $en = Translation::make(['language_code' => 'en']);
        $this->assertTrue($en->isApprovedForNarration());
    }
}
