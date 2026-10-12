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
            // Tested candidate bound to its render identity (unified-rendering-and-testing Req 2).
            'qa_report' => (new \App\Services\Qa\QaReport())
                ->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
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

    /**
     * Page-range rule (Req: no narration on the FIRST TWO or LAST TWO pages). With a
     * 6-page book and the defaults (start=3, end=page_count-2=4), only pages 3-4 are
     * narrated; 1,2 (front matter) and 5,6 (back matter) are silent. ElevenLabs is faked
     * so no real API call happens.
     */
    public function test_narration_excludes_first_two_and_last_two_pages(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/text-to-speech/*' => \Illuminate\Support\Facades\Http::response([
                'audio_base64' => base64_encode('FAKEAUDIO'),
                'alignment' => ['characters' => [], 'character_start_times_seconds' => []],
            ], 200),
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');

        $book = Book::create([
            'title' => '6-pager', 'original_language' => 'en', 'page_count' => 6,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => (new \App\Services\Qa\QaReport())
                ->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ]);
        foreach (range(1, 6) as $n) {
            $page = BookPage::create(['book_id' => $book->id, 'page_number' => $n, 'extracted_text' => "EN {$n}"]);
            TranslatedPage::create([
                'translation_id' => $t->id, 'book_page_id' => $page->id,
                'page_number' => $n, 'translated_text' => "Teks bladsy {$n}",
            ]);
            $t->fresh()->setPageApproval($n, true);
        }
        $t->fresh()->markApproved();

        $narration = app(NarrationService::class)
            ->narrate($book->fresh(), 'af', 'voice-1', 'Voice One');

        $keys = array_map('intval', array_keys($narration->page_audio_paths ?? []));
        sort($keys);
        $this->assertSame([3, 4], $keys, 'Only story pages 3-4 should be narrated.');
        $this->assertArrayNotHasKey(1, $narration->page_audio_paths); // cover
        $this->assertArrayNotHasKey(2, $narration->page_audio_paths); // copyright
        $this->assertArrayNotHasKey(5, $narration->page_audio_paths); // back matter
        $this->assertArrayNotHasKey(6, $narration->page_audio_paths); // back cover
    }
}
