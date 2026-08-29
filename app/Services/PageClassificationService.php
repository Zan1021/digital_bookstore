<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Classifies pages and regions within a book for appropriate translation handling.
 *
 * IMPORTANT: As of V8, the canonical page classification lives in the Python
 * scene graph (document_model.py → classify_page()). This PHP service now
 * serves as a FALLBACK for cases where the scene graph hasn't been generated yet.
 *
 * Preferred flow:
 *   1. Upload PDF → Python builds DocumentScene → page types stored in manifest
 *   2. PHP reads manifest.page_type (source of truth)
 *   3. This service is ONLY used when no manifest is available
 *
 * @deprecated Prefer reading page_type from the book's stored manifest JSON.
 *             This service will be removed once all books have manifests.
 *
 * Page types:
 * - cover: Front cover with title
 * - copyright: Publisher/metadata page
 * - story: Main narrative (illustration + prose)
 * - vocabulary: Word list page (WORDS, HIGH FREQUENCY, etc.)
 * - phonics: Phonics/language exercise page
 * - back_cover: Back cover with title list
 *
 * Region types within a page:
 * - prose: Normal story text / paragraphs
 * - heading: Section heading or title
 * - word_list: Single-column vocabulary list
 * - multi_column_word_list: Multi-column word layout
 * - high_frequency_words: Sight words for early readers
 * - phonics_exercise: Sound-spelling patterns and examples
 * - label: Text label in/near illustration
 * - dialogue: Character speech
 */
class PageClassificationService
{
    /**
     * Get page classification from stored manifest (preferred path).
     * Falls back to heuristic classification if no manifest exists.
     *
     * @param string|null $manifestJson The book's manifest JSON (from books.manifest_path)
     * @param int $pageNumber Page number to classify
     * @param string $text Extracted text (fallback only)
     * @param int $totalPages Total pages in book (fallback only)
     */
    public function classifyFromManifest(?string $manifestJson, int $pageNumber, string $text = '', int $totalPages = 0): array
    {
        // Preferred: use manifest data from scene graph
        if ($manifestJson) {
            $manifest = json_decode($manifestJson, true);
            if ($manifest && isset($manifest['pages'])) {
                foreach ($manifest['pages'] as $page) {
                    if (($page['page_number'] ?? 0) === $pageNumber) {
                        return [
                            'page_number' => $pageNumber,
                            'page_type' => $page['page_type'] ?? 'story',
                            'regions' => $page['regions'] ?? [],
                            'requires_educational_review' => in_array(
                                $page['page_type'] ?? '',
                                ['vocabulary', 'phonics']
                            ),
                            'source' => 'scene_graph',
                        ];
                    }
                }
            }
        }

        // Fallback: heuristic classification (legacy path)
        Log::debug("PageClassification: No manifest for page {$pageNumber}, using heuristic fallback");
        $result = $this->classifyPage($text, $pageNumber, $totalPages);
        $result['source'] = 'heuristic_fallback';
        return $result;
    }

    /**
     * Classify a page by its content (legacy heuristic path).
     *
     * @deprecated Use classifyFromManifest() instead.
     */
    public function classifyPage(string $text, int $pageNumber, int $totalPages): array
    {
        $classification = [
            'page_number' => $pageNumber,
            'page_type' => 'story', // default
            'regions' => [],
            'requires_educational_review' => false,
        ];

        // Cover detection (first page)
        if ($pageNumber === 1) {
            $classification['page_type'] = 'cover';
            $classification['regions'][] = [
                'type' => 'heading',
                'content' => $text,
            ];
            return $classification;
        }

        // Back cover (last page)
        if ($pageNumber === $totalPages) {
            $classification['page_type'] = 'back_cover';
            $classification['regions'][] = [
                'type' => 'prose',
                'content' => $text,
            ];
            return $classification;
        }

        // Copyright/metadata
        if ($this->isMetadata($text)) {
            $classification['page_type'] = 'copyright';
            $classification['regions'][] = [
                'type' => 'metadata',
                'content' => $text,
            ];
            return $classification;
        }

        // Vocabulary/educational page
        if ($this->isVocabularyPage($text)) {
            $classification['page_type'] = 'vocabulary';
            $classification['requires_educational_review'] = true;
            $classification['regions'] = $this->classifyVocabularyRegions($text);
            return $classification;
        }

        // Default: story page
        $classification['page_type'] = 'story';
        $classification['regions'][] = [
            'type' => 'prose',
            'content' => $text,
        ];

        return $classification;
    }

    /**
     * Detect vocabulary/educational pages.
     */
    private function isVocabularyPage(string $text): bool
    {
        $indicators = ['WORDS', 'HIGH FREQUENCY', 'PHONICS', 'WOORDE', 'FREKWENSIE', 'FONIES'];
        $matchCount = 0;

        foreach ($indicators as $indicator) {
            if (stripos($text, $indicator) !== false) {
                $matchCount++;
            }
        }

        // Also check for many single words (characteristic of word lists)
        $lines = explode("\n", trim($text));
        $singleWordLines = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && str_word_count($line) <= 2) {
                $singleWordLines++;
            }
        }

        return $matchCount >= 2 || $singleWordLines > 20;
    }

    /**
     * Detect metadata/copyright pages.
     */
    private function isMetadata(string $text): bool
    {
        $indicators = ['ISBN', 'Copyright', 'Published by', 'Printed by', '©', 'www.', '.net', '.com'];
        $matchCount = 0;

        foreach ($indicators as $indicator) {
            if (stripos($text, $indicator) !== false) {
                $matchCount++;
            }
        }

        return $matchCount >= 2;
    }

    /**
     * Classify regions within a vocabulary page.
     */
    private function classifyVocabularyRegions(string $text): array
    {
        $regions = [];
        $lines = explode("\n", trim($text));

        $currentRegion = null;
        $currentType = 'word_list';
        $currentHeading = '';
        $currentItems = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            // Detect section headers
            if ($this->isVocabHeader($trimmed)) {
                // Save previous region
                if (!empty($currentItems)) {
                    $regions[] = [
                        'type' => $currentType,
                        'heading' => $currentHeading,
                        'items' => $currentItems,
                        'requires_educational_review' => $currentType === 'phonics_exercise',
                    ];
                }

                // Determine new region type
                $currentHeading = $trimmed;
                $currentItems = [];

                if (stripos($trimmed, 'PHONIC') !== false || stripos($trimmed, 'FONIES') !== false) {
                    $currentType = 'phonics_exercise';
                } elseif (stripos($trimmed, 'FREQUENCY') !== false || stripos($trimmed, 'FREKWENSIE') !== false) {
                    $currentType = 'high_frequency_words';
                } else {
                    $currentType = 'multi_column_word_list';
                }
            } else {
                $currentItems[] = $trimmed;
            }
        }

        // Save final region
        if (!empty($currentItems)) {
            $regions[] = [
                'type' => $currentType,
                'heading' => $currentHeading,
                'items' => $currentItems,
                'requires_educational_review' => $currentType === 'phonics_exercise',
            ];
        }

        return $regions;
    }

    /**
     * Check if a line is a vocabulary page section header.
     */
    private function isVocabHeader(string $line): bool
    {
        $patterns = [
            '/^WORDS$/i',
            '/^WOORDE$/i',
            '/HIGH\s*FREQUENCY/i',
            '/HO[ËE]\s*FREKWENSIE/i',
            '/^PHONICS$/i',
            '/^FONIES$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }
}
