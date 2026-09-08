<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Translation;
use App\Models\TranslatedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-style coverage for the edition approval + narration gate
 * (spec: gated-translation-narration-flow, Req 4 & 5).
 */
class TranslationApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private function bookWithEdition(string $lang, int $pages = 3, string $renderStatus = Translation::STATE_READY_FOR_REVIEW): Translation
    {
        $book = Book::create([
            'title' => "Book {$lang}",
            'original_language' => 'en',
            'page_count' => $pages,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);

        $translation = Translation::create([
            'book_id' => $book->id,
            'language_code' => $lang,
            'language_name' => strtoupper($lang),
            'status' => 'draft',
            'render_status' => $renderStatus,
            'qa_report' => ['publishable' => true],
        ]);

        foreach (range(1, $pages) as $n) {
            $page = BookPage::create([
                'book_id' => $book->id,
                'page_number' => $n,
                'extracted_text' => "EN {$n}",
            ]);
            TranslatedPage::create([
                'translation_id' => $translation->id,
                'book_page_id' => $page->id,
                'page_number' => $n,
                'translated_text' => "T {$n}",
            ]);
        }

        return $translation->fresh();
    }

    public function test_progress_starts_at_zero_of_total(): void
    {
        $t = $this->bookWithEdition('af', 3);
        $this->assertSame([0, 3], $t->pageApprovalProgress());
        $this->assertFalse($t->allPagesApproved());
    }

    public function test_progress_counts_only_approved_pages(): void
    {
        $t = $this->bookWithEdition('af', 3);
        $t->setPageApproval(1, true);
        $t->setPageApproval(2, true);
        $this->assertSame([2, 3], $t->fresh()->pageApprovalProgress());
        $this->assertFalse($t->fresh()->allPagesApproved());
    }

    public function test_all_pages_approved_when_every_page_approved(): void
    {
        $t = $this->bookWithEdition('af', 3);
        foreach (range(1, 3) as $n) {
            $t->setPageApproval($n, true);
        }
        $this->assertTrue($t->fresh()->allPagesApproved());
    }

    public function test_edition_with_no_pages_is_not_approvable(): void
    {
        $book = Book::create([
            'title' => 'Empty', 'original_language' => 'en', 'page_count' => 0,
            'status' => 'ready', 'pdf_path' => 'x.pdf',
        ]);
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);
        $this->assertFalse($t->allPagesApproved());
        $this->assertFalse($t->markApproved());
    }

    public function test_mark_approved_requires_all_pages_and_clear_qa(): void
    {
        $t = $this->bookWithEdition('af', 2);
        $t->setPageApproval(1, true);
        // Not all approved yet.
        $this->assertFalse($t->fresh()->markApproved());

        $t->fresh()->setPageApproval(2, true);
        $ok = $t->fresh()->markApproved();
        $this->assertTrue($ok);

        $t = $t->fresh();
        $this->assertSame(Translation::STATE_APPROVED, $t->render_status);
        $this->assertNotNull($t->approved_at);
    }

    public function test_mark_approved_blocked_by_layout_review(): void
    {
        $t = $this->bookWithEdition('af', 1, Translation::STATE_NEEDS_LAYOUT_REVIEW);
        $t->setPageApproval(1, true);
        // Even with pages approved, a blocking render state must refuse approval.
        $this->assertFalse($t->fresh()->markApproved());
    }

    public function test_source_language_is_always_approved_for_narration(): void
    {
        $t = $this->bookWithEdition('en', 3, Translation::STATE_TRANSLATING);
        $this->assertTrue($t->isSourceLanguage());
        $this->assertTrue($t->isApprovedForNarration());
    }

    public function test_translated_edition_needs_approval_for_narration(): void
    {
        $t = $this->bookWithEdition('af', 2);
        $this->assertFalse($t->isApprovedForNarration());

        foreach (range(1, 2) as $n) {
            $t->fresh()->setPageApproval($n, true);
        }
        $t->fresh()->markApproved();
        $this->assertTrue($t->fresh()->isApprovedForNarration());
    }
}
