<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\TranslatedPage;
use App\Models\Translation;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fix C — English-leak guard for resolveItemTranslations().
 *
 * BUG (2026-08-30): on a page that HAS translated text, spans with no matching
 * segment fell back to their English source_text — so multi-span pages (back cover
 * p16, WOORDE p15) silently shipped English titles/words. The fix renders those
 * spans BLANK and records their ids (getUnresolvedSpanIds()) so the edition is
 * routed to NEEDS_LAYOUT_REVIEW. The source_text fallback survives ONLY for
 * genuinely untranslated pages (no translated_text at all).
 *
 * DB-backed because the resolver reads the translation's pages + layout_overrides.
 */
class ResolveItemTranslationsLeakGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(array $pageText, array $overrides = [], array $itemTranslations = []): Translation
    {
        $book = Book::create([
            'title' => 'Kolulu A Fun Place',
            'pdf_path' => 'books/test.pdf',
        ]);
        $translation = Translation::create([
            'book_id' => $book->id,
            'language_code' => 'af',
            'language_name' => 'Afrikaans',
            'layout_overrides' => $overrides,
            'item_translations' => $itemTranslations,
        ]);
        foreach ($pageText as $pageNumber => $text) {
            $bookPage = BookPage::create([
                'book_id' => $book->id,
                'page_number' => $pageNumber,
            ]);
            TranslatedPage::create([
                'translation_id' => $translation->id,
                'book_page_id' => $bookPage->id,
                'page_number' => $pageNumber,
                'translated_text' => $text,
            ]);
        }
        return $translation->fresh();
    }

    /** Build contract items for a single page with $n spans. */
    private function spans(int $page, int $n, string $sourcePrefix = 'English'): array
    {
        $items = [];
        for ($i = 0; $i < $n; $i++) {
            $items[] = [
                'id' => "p{$page}_s{$i}",
                'page_number' => $page,
                'reading_order' => $i,
                'source_text' => "{$sourcePrefix} {$i}",
            ];
        }
        return $items;
    }

    /**
     * THE LEAK: back cover with 7 spans but only 2 lines of Afrikaans. Spans 0-1 get
     * Afrikaans; spans 2-6 must render BLANK (not English source_text) and be reported
     * as unresolved.
     */
    public function test_translated_page_missing_segments_render_blank_not_english(): void
    {
        $svc = new PdfTranslationService();
        $translation = $this->makeTranslation([16 => "Afrikaans reël een\nAfrikaans reël twee"]);
        $items = $this->spans(16, 7);

        $map = $svc->resolveItemTranslations($items, $translation);

        $this->assertSame('Afrikaans reël een', $map['p16_s0']);
        $this->assertSame('Afrikaans reël twee', $map['p16_s1']);
        foreach (['p16_s2', 'p16_s3', 'p16_s4', 'p16_s5', 'p16_s6'] as $id) {
            $this->assertSame('', $map[$id], "$id must be blank, not English");
        }
        foreach ($map as $value) {
            $this->assertStringNotContainsString('English', $value);
        }
        $this->assertSame(
            ['p16_s2', 'p16_s3', 'p16_s4', 'p16_s5', 'p16_s6'],
            $svc->getUnresolvedSpanIds()
        );
    }

    /**
     * A GENUINELY untranslated page (no translated_text at all) still renders the
     * source in place — that is not a leak, and must NOT be reported as unresolved.
     */
    public function test_untranslated_page_keeps_source_and_is_not_flagged(): void
    {
        $svc = new PdfTranslationService();
        $translation = $this->makeTranslation([]);
        $items = $this->spans(5, 3, 'SourceWord');

        $map = $svc->resolveItemTranslations($items, $translation);

        $this->assertSame('SourceWord 0', $map['p5_s0']);
        $this->assertSame('SourceWord 1', $map['p5_s1']);
        $this->assertSame('SourceWord 2', $map['p5_s2']);
        $this->assertSame([], $svc->getUnresolvedSpanIds());
    }

    /** A per-region override still wins and never counts as unresolved. */
    public function test_override_wins_and_is_not_flagged(): void
    {
        $svc = new PdfTranslationService();
        $translation = $this->makeTranslation(
            [16 => "reël een"],
            ['p16_s1' => ['translation' => 'Handmatige Titel']]
        );
        $items = $this->spans(16, 2);

        $map = $svc->resolveItemTranslations($items, $translation);

        $this->assertSame('reël een', $map['p16_s0']);
        $this->assertSame('Handmatige Titel', $map['p16_s1']);
        $this->assertSame([], $svc->getUnresolvedSpanIds());
    }

    /** Clean 1:1 page (line count == span count) resolves fully, nothing unresolved. */
    public function test_clean_one_to_one_page_has_no_unresolved(): void
    {
        $svc = new PdfTranslationService();
        $translation = $this->makeTranslation([2 => "een\ntwee\ndrie"]);
        $items = $this->spans(2, 3);

        $map = $svc->resolveItemTranslations($items, $translation);

        $this->assertSame(['een', 'twee', 'drie'], array_values($map));
        $this->assertSame([], $svc->getUnresolvedSpanIds());
    }

    // -----------------------------------------------------------------------
    // Fix B — durable per-element (item_translations) resolution.
    // -----------------------------------------------------------------------

    /**
     * THE DURABLE FIX: the same 7-span back cover that previously leaked English now
     * has per-id machine translations. Every span resolves to its OWN Afrikaans text,
     * with NO page-text segments needed and NOTHING left unresolved.
     */
    public function test_item_translations_resolve_every_span_with_no_leak_or_review(): void
    {
        $svc = new PdfTranslationService();
        $perId = [];
        for ($i = 0; $i < 7; $i++) {
            $perId["p16_s{$i}"] = "AF titel {$i}";
        }
        // Page text is intentionally short (would have triggered fix C blanks) — but
        // the per-id store must win, so it never comes to that.
        $translation = $this->makeTranslation(
            [16 => "AF titel 0"],
            [],
            $perId
        );
        $items = $this->spans(16, 7);

        $map = $svc->resolveItemTranslations($items, $translation);

        for ($i = 0; $i < 7; $i++) {
            $this->assertSame("AF titel {$i}", $map["p16_s{$i}"]);
        }
        foreach ($map as $value) {
            $this->assertStringNotContainsString('English', $value);
        }
        // The whole point of B: no blanks, no review needed.
        $this->assertSame([], $svc->getUnresolvedSpanIds());
    }

    /** A human layout override still beats the machine per-id translation. */
    public function test_human_override_beats_item_translation(): void
    {
        $svc = new PdfTranslationService();
        $translation = $this->makeTranslation(
            [16 => "iets"],
            ['p16_s0' => ['translation' => 'Mens se Titel']],
            ['p16_s0' => 'Masjien se Titel', 'p16_s1' => 'Masjien Twee']
        );
        $items = $this->spans(16, 2);

        $map = $svc->resolveItemTranslations($items, $translation);

        $this->assertSame('Mens se Titel', $map['p16_s0']);   // override wins
        $this->assertSame('Masjien Twee', $map['p16_s1']);    // item_translation
        $this->assertSame([], $svc->getUnresolvedSpanIds());
    }

    /**
     * A partial per-id store: ids present resolve from it; a missing id on a page WITH
     * text still falls through to the fix-C blank+review (never leaks English).
     */
    public function test_missing_item_translation_falls_through_to_review_not_english(): void
    {
        $svc = new PdfTranslationService();
        $translation = $this->makeTranslation(
            [16 => "AF een"],            // one line of page text
            [],
            ['p16_s0' => 'AF een']       // only the first span has a per-id translation
        );
        $items = $this->spans(16, 3);

        $map = $svc->resolveItemTranslations($items, $translation);

        $this->assertSame('AF een', $map['p16_s0']);   // from item_translations
        // s1 gets the page-text segment attempt; with a single line and 3 spans the
        // splitter leaves s1/s2 empty -> fix C blank + review, NEVER English.
        $this->assertSame('', $map['p16_s2']);
        $this->assertStringNotContainsString('English', $map['p16_s1']);
        $this->assertStringNotContainsString('English', $map['p16_s2']);
        $this->assertNotEmpty($svc->getUnresolvedSpanIds());
    }
}
