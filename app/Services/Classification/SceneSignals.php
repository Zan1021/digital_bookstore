<?php

namespace App\Services\Classification;

/**
 * Deterministic classification signals derived from a book's extracted text (and,
 * where available, page-level structure). NO AI — these are the dimensions we can
 * measure reliably from data we already have, per the "deterministic-first" design.
 *
 * Input shape (book-agnostic): an array of pages, each:
 *   ['page_number' => int, 'text' => string]
 * Optionally each page may carry ['is_image_only' => bool] when known from the manifest.
 *
 * All methods are pure functions of the input; no DB, no side effects, so they are
 * trivially unit-testable and never depend on one specific book.
 */
class SceneSignals
{
    /** @var array<int,array{page_number:int,text:string,is_image_only?:bool}> */
    private array $pages;

    public function __construct(array $pages)
    {
        // Normalise + sort by page number; drop nulls.
        $this->pages = array_values(array_map(function ($p) {
            return [
                'page_number' => (int) ($p['page_number'] ?? 0),
                'text' => trim((string) ($p['text'] ?? '')),
                'is_image_only' => (bool) ($p['is_image_only'] ?? false),
            ];
        }, $pages));
        usort($this->pages, fn ($a, $b) => $a['page_number'] <=> $b['page_number']);
    }

    /** All signals as one array, ready to become classification suggestions. */
    public function all(): array
    {
        return [
            'page_count' => $this->pageCount(),
            'words_per_page' => $this->avgWordsPerPage(),
            'illustration_density' => $this->illustrationDensity(),
            'book_type' => $this->bookType(),
            'reading_level' => $this->readingLevel(),
            'age_hint' => $this->ageHint(),
        ];
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Average words per content page (pages with any text). */
    public function avgWordsPerPage(): float
    {
        $textPages = array_filter($this->pages, fn ($p) => $p['text'] !== '');
        if (empty($textPages)) {
            return 0.0;
        }
        $total = 0;
        foreach ($textPages as $p) {
            $total += $this->wordCount($p['text']);
        }
        return round($total / count($textPages), 1);
    }

    /**
     * Fraction of pages that are illustration-dominant (little/no text). Uses the
     * manifest flag when present, else a low-word-count heuristic.
     */
    public function illustrationDensity(): float
    {
        if ($this->pageCount() === 0) {
            return 0.0;
        }
        $imageLike = 0;
        foreach ($this->pages as $p) {
            $isImage = $p['is_image_only'] || $this->wordCount($p['text']) <= 12;
            if ($isImage) {
                $imageLike++;
            }
        }
        return round($imageLike / $this->pageCount(), 3);
    }

    /**
     * Book-type signal (confidence-scored). Structural, not string-matched:
     *   - high illustration density + low words/page -> picture_book
     *   - moderate words/page, short book -> early_reader
     *   - many words/page or many pages -> chapter_book / storybook
     */
    public function bookType(): array
    {
        $wpp = $this->avgWordsPerPage();
        $density = $this->illustrationDensity();
        $pages = $this->pageCount();

        if ($density >= 0.6 && $wpp <= 30) {
            return ['value' => 'picture_book', 'confidence' => 0.9];
        }
        if ($wpp <= 60 && $pages <= 32) {
            return ['value' => 'early_reader', 'confidence' => 0.75];
        }
        if ($wpp <= 150) {
            return ['value' => 'storybook', 'confidence' => 0.7];
        }
        return ['value' => 'chapter_book', 'confidence' => 0.65];
    }

    /**
     * Reading-level signal from average sentence length + average word length —
     * a simple, language-robust readability proxy (not a formula tuned to English).
     * Maps to the controlled reading_levels slugs.
     */
    public function readingLevel(): array
    {
        $allText = implode(' ', array_map(fn ($p) => $p['text'], $this->pages));
        $words = $this->words($allText);
        $wordCount = count($words);
        if ($wordCount === 0) {
            return ['value' => 'pre_reader', 'confidence' => 0.6];
        }

        $sentences = max(1, preg_match_all('/[.!?]+/', $allText));
        $avgSentenceLen = $wordCount / $sentences;
        $avgWordLen = array_sum(array_map('mb_strlen', $words)) / $wordCount;

        // Composite difficulty score. Thresholds chosen to be gentle and monotonic;
        // derived from typical kids-book ranges, not fitted to one book.
        $score = ($avgSentenceLen * 0.6) + ($avgWordLen * 1.4);

        [$slug, $conf] = match (true) {
            $score < 6 => ['pre_reader', 0.7],
            $score < 9 => ['beginner', 0.75],
            $score < 12 => ['developing', 0.75],
            $score < 16 => ['independent', 0.7],
            default => ['advanced', 0.65],
        };
        return ['value' => $slug, 'confidence' => $conf,
                'metrics' => ['avg_sentence_len' => round($avgSentenceLen, 1),
                              'avg_word_len' => round($avgWordLen, 2)]];
    }

    /**
     * Age hint (min/max) derived from book type + reading level. ALWAYS low
     * confidence and flagged requires_confirmation upstream — a suggestion only,
     * never auto-published (safeguarding).
     */
    public function ageHint(): array
    {
        $type = $this->bookType()['value'];
        $level = $this->readingLevel()['value'];

        $byType = match ($type) {
            'picture_book' => [3, 6],
            'early_reader' => [5, 8],
            'storybook' => [6, 10],
            'chapter_book' => [9, 12],
            default => [4, 9],
        };
        // Nudge upward for harder reading levels.
        if (in_array($level, ['independent', 'advanced'], true)) {
            $byType[0] += 2;
            $byType[1] += 3;
        }
        return ['min' => $byType[0], 'max' => $byType[1], 'confidence' => 0.5];
    }

    // ---- helpers ----

    private function wordCount(string $text): int
    {
        return count($this->words($text));
    }

    /** @return string[] */
    private function words(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        return preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
