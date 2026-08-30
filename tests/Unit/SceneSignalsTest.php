<?php

namespace Tests\Unit;

use App\Services\Classification\SceneSignals;
use PHPUnit\Framework\TestCase;

class SceneSignalsTest extends TestCase
{
    /** A picture book: many low-text/illustration pages. */
    public function test_picture_book_detected_from_high_illustration_density(): void
    {
        $pages = [];
        for ($i = 1; $i <= 12; $i++) {
            $pages[] = ['page_number' => $i, 'text' => 'The cat ran.']; // ~3 words
        }
        $s = new SceneSignals($pages);
        $this->assertSame('picture_book', $s->bookType()['value']);
        $this->assertGreaterThanOrEqual(0.6, $s->illustrationDensity());
    }

    /** An early reader: short sentences, moderate text, short book. */
    public function test_early_reader_detected(): void
    {
        $line = 'Kolulu likes to play outside with her friends every single sunny day.'; // ~12 words
        $pages = [];
        for ($i = 1; $i <= 16; $i++) {
            $pages[] = ['page_number' => $i, 'text' => "$line $line $line"]; // ~36 words/page
        }
        $s = new SceneSignals($pages);
        $this->assertSame('early_reader', $s->bookType()['value']);
    }

    /** Reading level rises with sentence + word length. */
    public function test_reading_level_monotonic_with_difficulty(): void
    {
        $easy = new SceneSignals([
            ['page_number' => 1, 'text' => 'The dog ran. The cat sat. We go.'],
        ]);
        $hard = new SceneSignals([
            ['page_number' => 1, 'text' => 'Consequently, the extraordinary expedition '
                . 'encountered numerous unforeseen complications throughout its '
                . 'considerable transcontinental journey.'],
        ]);
        $rank = ['pre_reader' => 1, 'beginner' => 2, 'developing' => 3,
                 'independent' => 4, 'advanced' => 5];
        $easyRank = $rank[$easy->readingLevel()['value']];
        $hardRank = $rank[$hard->readingLevel()['value']];
        $this->assertGreaterThan($easyRank, $hardRank);
    }

    /** Age hint is always low confidence (safeguarding: never auto-published). */
    public function test_age_hint_is_low_confidence(): void
    {
        $s = new SceneSignals([['page_number' => 1, 'text' => 'A short tale.']]);
        $hint = $s->ageHint();
        $this->assertLessThanOrEqual(0.6, $hint['confidence']);
        $this->assertLessThanOrEqual($hint['max'], $hint['min']);
    }

    /** Empty input degrades gracefully, no divide-by-zero. */
    public function test_empty_input_is_safe(): void
    {
        $s = new SceneSignals([]);
        $this->assertSame(0, $s->pageCount());
        $this->assertSame(0.0, $s->avgWordsPerPage());
        $this->assertSame(0.0, $s->illustrationDensity());
        $this->assertSame('pre_reader', $s->readingLevel()['value']);
    }

    /** Book-agnostic: signals depend on structure, not any specific title/text. */
    public function test_book_agnostic_same_structure_same_signal(): void
    {
        $mk = fn (string $w) => array_map(
            fn ($i) => ['page_number' => $i, 'text' => str_repeat("$w ", 3)],
            range(1, 12)
        );
        $a = new SceneSignals($mk('cat'));
        $b = new SceneSignals($mk('dog'));
        $this->assertSame($a->bookType()['value'], $b->bookType()['value']);
    }
}
