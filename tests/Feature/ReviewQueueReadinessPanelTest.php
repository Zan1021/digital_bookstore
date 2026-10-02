<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReviewQueue;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\Qa\QaReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * unified-rendering-and-testing D3 — the review workspace surfaces the AUTOMATED readiness
 * summary (per-check statuses + coverage + readiness) distinct from the human approvals.
 */
class ReviewQueueReadinessPanelTest extends TestCase
{
    use RefreshDatabase;

    private function editionBook(array $overrides): Translation
    {
        $book = Book::create([
            'title' => 'Panel Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $t = Translation::create(array_merge([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ], $overrides, ['book_id' => $book->id]));
        $page = BookPage::create(['book_id' => $book->id, 'page_number' => 1, 'extracted_text' => 'EN']);
        TranslatedPage::create([
            'translation_id' => $t->id, 'book_page_id' => $page->id,
            'page_number' => 1, 'translated_text' => 'AF',
        ]);
        return $t->fresh();
    }

    public function test_panel_reports_ready_for_bound_passing_candidate(): void
    {
        $book = $this->editionBook([
            'qa_report' => (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ])->book;

        Livewire::test(ReviewQueue::class, ['book' => $book, 'language' => 'af'])
            ->assertViewHas('readinessReport', fn ($r) => $r['ready'] === true && ($r['checks']['structure'] ?? null) === 'passed');
    }

    public function test_panel_reports_not_ready_without_identity(): void
    {
        $book = $this->editionBook([
            'qa_report' => (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => null,
            'output_sha256' => null,
        ])->book;

        Livewire::test(ReviewQueue::class, ['book' => $book, 'language' => 'af'])
            ->assertViewHas('readinessReport', fn ($r) => $r['ready'] === false);
    }
}
