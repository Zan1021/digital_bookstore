<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Models\TranslationMemory;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class TranslationService
{
    public const SUPPORTED_LANGUAGES = [
        'af' => 'Afrikaans',
        'zu' => 'isiZulu',
        'xh' => 'isiXhosa',
        'st' => 'Sesotho',
        'nso' => 'Sepedi',
        'tn' => 'Setswana',
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'it' => 'Italian',
        'sw' => 'Swahili',
        'ar' => 'Arabic',
        'zh' => 'Chinese (Simplified)',
    ];

    /**
     * Locked terms that should NEVER be translated (character names, places, brands).
     * Publishers can extend this per-book in the future.
     */
    private array $glossary = [];

    /** Primary and fallback models for API resilience */
    private const PRIMARY_MODEL = 'gpt-4o';
    private const FALLBACK_MODEL = 'gpt-4o-mini';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_SECONDS = 2;

    /**
     * Make an OpenAI chat completion with automatic retry and model fallback.
     * Handles rate limits, traffic overload, and transient errors gracefully.
     */
    private function chatWithRetry(array $params): string
    {
        $models = [self::PRIMARY_MODEL, self::FALLBACK_MODEL];
        $lastError = null;

        foreach ($models as $model) {
            $params['model'] = $model;

            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $response = OpenAI::chat()->create($params);
                    return trim($response->choices[0]->message->content);
                } catch (\Exception $e) {
                    $lastError = $e;
                    $message = $e->getMessage();

                    // Rate limit or overload — retry with backoff
                    if (str_contains($message, 'high volume') ||
                        str_contains($message, '429') ||
                        str_contains($message, 'Rate limit') ||
                        str_contains($message, 'overloaded')) {

                        Log::warning("OpenAI API overloaded (model: {$model}, attempt {$attempt})", [
                            'error' => $message,
                        ]);

                        if ($attempt < self::MAX_RETRIES) {
                            sleep(self::RETRY_DELAY_SECONDS * $attempt); // exponential-ish backoff
                            continue;
                        }
                        // Exhausted retries for this model — try fallback
                        break;
                    }

                    // Other errors (auth, invalid request) — don't retry, throw immediately
                    Log::error("OpenAI API error (model: {$model})", ['error' => $message]);
                    throw $e;
                }
            }

            Log::info("Falling back from {$model} to next model due to overload.");
        }

        // All models and retries exhausted
        throw new \RuntimeException(
            "Translation failed: OpenAI API unavailable after retries. Last error: " . ($lastError?->getMessage() ?? 'Unknown'),
        );
    }

    /**
     * Translate a book using the 3-step transcreation pipeline:
     * 1. Creative translation (not literal)
     * 2. Back-translation for verification
     * 3. Quality scoring and auto-refinement
     */
    public function translate(Book $book, string $languageCode): Translation
    {
        $languageName = self::SUPPORTED_LANGUAGES[$languageCode]
            ?? throw new \InvalidArgumentException("Unsupported language: {$languageCode}");

        $translation = Translation::updateOrCreate(
            ['book_id' => $book->id, 'language_code' => $languageCode],
            ['language_name' => $languageName, 'status' => 'processing']
        );

        // Build glossary from book (character names, etc.)
        $this->glossary = $this->buildGlossary($book);

        // Persist glossary to translation memory for cross-session consistency
        $this->persistGlossaryToMemory($book, $languageCode);

        // Get all pages with text
        $pages = $book->pages()->whereNotNull('extracted_text')
            ->where('extracted_text', '!=', '')
            ->orderBy('page_number')
            ->get();

        // Build full story context for consistency
        $fullStoryText = $pages->pluck('extracted_text')->filter()->implode("\n\n---PAGE BREAK---\n\n");

        foreach ($pages as $page) {
            $originalText = trim($page->extracted_text);
            if (empty($originalText)) {
                continue;
            }

            // Skip copyright/metadata pages (very small text, lots of entries)
            if ($this->isMetadataPage($originalText)) {
                // Still translate but with simpler approach
                $translatedText = $this->translateMetadata($originalText, $languageCode, $languageName);
                TranslatedPage::updateOrCreate(
                    ['translation_id' => $translation->id, 'page_number' => $page->page_number],
                    [
                        'book_page_id' => $page->id,
                        'translated_text' => $translatedText,
                        'confidence_score' => 9.0,
                        'quality_flag' => 'green',
                        'quality_notes' => 'Metadata page — simple translation applied.',
                    ]
                );
                continue;
            }

            // Vocabulary/educational pages need a different translation approach
            if ($this->isVocabularyPage($originalText)) {
                $translatedText = $this->translateVocabularyPage($originalText, $languageCode, $languageName, $fullStoryText);
                TranslatedPage::updateOrCreate(
                    ['translation_id' => $translation->id, 'page_number' => $page->page_number],
                    [
                        'book_page_id' => $page->id,
                        'translated_text' => $translatedText,
                        'confidence_score' => 7.5,
                        'quality_flag' => 'yellow',
                        'quality_notes' => 'Educational page — requires specialist review for phonics adaptation.',
                    ]
                );
                continue;
            }

            // === STEP 1: Creative Translation (Transcreation) ===
            $translatedText = $this->transcreate(
                $originalText,
                $languageCode,
                $languageName,
                $fullStoryText,
                $page->page_number,
                $book->id
            );

            // === STEP 2: Back-Translation ===
            $backTranslation = $this->backTranslate($translatedText, $languageCode, $languageName);

            // === STEP 3: Quality Score + Auto-Refine ===
            $quality = $this->scoreQuality($originalText, $translatedText, $backTranslation, $languageName);

            // If quality is low, auto-refine
            if ($quality['score'] < 7.0) {
                $refinedResult = $this->refine(
                    $originalText,
                    $translatedText,
                    $backTranslation,
                    $quality,
                    $languageCode,
                    $languageName
                );
                $translatedText = $refinedResult['translation'];
                // Re-score after refinement
                $backTranslation = $this->backTranslate($translatedText, $languageCode, $languageName);
                $quality = $this->scoreQuality($originalText, $translatedText, $backTranslation, $languageName);
            }

            // Determine quality flag
            $flag = match (true) {
                $quality['score'] >= 8.0 => 'green',
                $quality['score'] >= 6.0 => 'yellow',
                default => 'red',
            };

            TranslatedPage::updateOrCreate(
                ['translation_id' => $translation->id, 'page_number' => $page->page_number],
                [
                    'book_page_id' => $page->id,
                    'translated_text' => $translatedText,
                    'back_translation' => $backTranslation,
                    'confidence_score' => $quality['score'],
                    'quality_flag' => $flag,
                    'quality_notes' => $quality['notes'],
                    'review_status' => 'unreviewed',
                ]
            );
        }

        $translation->update(['status' => 'draft']);

        return $translation->fresh();
    }

    /**
     * Step 1: Creative Translation (Transcreation)
     * Not a word-for-word translation — a creative retelling in the target language.
     */
    private function transcreate(
        string $text,
        string $langCode,
        string $langName,
        string $fullStory,
        int $pageNumber,
        int $bookId = 0
    ): string {
        $glossaryStr = '';
        if (!empty($this->glossary)) {
            $glossaryStr = "\n\nGLOSSARY — Keep these names/terms exactly as-is, never translate them:\n";
            foreach ($this->glossary as $term) {
                $glossaryStr .= "- {$term}\n";
            }
        }

        // Include translation memory for consistency
        $tmContext = '';
        if ($bookId > 0) {
            $tmContext = TranslationMemory::asPromptContext($bookId, $langCode);
            if (!empty($tmContext)) {
                $tmContext = "\n\n" . $tmContext;
            }
        }

        // Extract surrounding context (2 pages before and after for continuity)
        $contextSnippet = $this->extractSurroundingContext($fullStory, $pageNumber);

        $systemPrompt = <<<PROMPT
You are a professional literary translator translating a children's book from English into South African {$langName}.

Your goal is to produce a natural, engaging translation that feels as though it was originally written in {$langName}.

BOOK INFORMATION:
- Genre: Children's illustrated story book
- Intended readers: South African children aged 5-8 (Foundation Phase, Grade 1-2)
- Narrative style: Simple, warm, engaging
- Tone: Playful, educational, encouraging
- Reading level: Early reader — short sentences, simple vocabulary

TRANSLATION REQUIREMENTS:
1. Produce natural South African {$langName} — NOT word-for-word translation
2. Write as if telling the story to a child — warm, engaging, rhythmic
3. Use natural {$langName} sentence structure (it may differ from English word order)
4. Adapt idioms and expressions into natural {$langName} equivalents
5. Keep sentences SHORT and age-appropriate for Foundation Phase readers
6. Preserve the author's meaning, tone, and emotional intent exactly
7. Do NOT add, remove, summarise, or explain content
8. Return the text as a flowing paragraph — the layout engine handles line breaks
9. Do NOT include page numbers, line numbers, or formatting instructions
{$glossaryStr}
CONTEXT FROM SURROUNDING PAGES:
{$contextSnippet}
{$tmContext}
IMPORTANT: Return ONLY the translated text as a natural flowing paragraph. No explanations, no notes, no page numbers.
PROMPT;

        $response = $this->chatWithRetry([
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Translate page {$pageNumber}:\n\n{$text}"],
            ],
            'temperature' => 0.8, // Creative but controlled
        ]);

        return $response;
    }

    /**
     * Extract surrounding page context for translation continuity.
     */
    private function extractSurroundingContext(string $fullStory, int $pageNumber): string
    {
        $pages = explode("---PAGE BREAK---", $fullStory);
        $context = '';

        // Get 2 pages before for context
        $startIdx = max(0, $pageNumber - 3);  // -3 because page numbers are 1-indexed and pages[0] is page 1
        $endIdx = min(count($pages) - 1, $pageNumber + 1);

        for ($i = $startIdx; $i < $pageNumber - 1 && $i < count($pages); $i++) {
            $pageText = trim($pages[$i] ?? '');
            if (!empty($pageText)) {
                $context .= "[Previous page]: " . mb_substr($pageText, 0, 200) . "\n";
            }
        }

        if (empty($context)) {
            $context = "(This is near the beginning of the book)";
        }

        return $context;
    }

    /**
     * Step 2: Back-Translation
     * Translate the result back to English to verify meaning was preserved.
     */
    private function backTranslate(string $translatedText, string $langCode, string $langName): string
    {
        return $this->chatWithRetry([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a professional translator. Translate the following {$langName} text back into English. "
                        . "Produce a literal, faithful translation — do not improve or embellish. "
                        . "This is for quality verification purposes. Return only the English text.",
                ],
                ['role' => 'user', 'content' => $translatedText],
            ],
            'temperature' => 0.2,
        ]);
    }

    /**
     * Step 3a: Quality Scoring
     * Compare original → back-translation to detect semantic drift.
     */
    private function scoreQuality(
        string $original,
        string $translated,
        string $backTranslation,
        string $langName
    ): array {
        $content = $this->chatWithRetry([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<PROMPT
You are a translation quality assessor for children's books. Compare the original English text to its back-translation (which went English → {$langName} → English).

Score the translation on these criteria (each 1-10):
1. MEANING: Does the back-translation preserve the same meaning/events?
2. COMPLETENESS: Are all ideas present? Nothing added or removed?
3. NATURALNESS: Does the {$langName} text read naturally (not like a robot)?
4. AGE-APPROPRIATENESS: Is the vocabulary suitable for ages 5-8?
5. LENGTH: Is the translated text roughly the same length as the original?

Return JSON only:
{
  "meaning": 8,
  "completeness": 9,
  "naturalness": 7,
  "age_appropriate": 9,
  "length_match": 8,
  "overall": 8.2,
  "issues": ["brief description of any issues found"]
}
PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => "ORIGINAL ENGLISH:\n{$original}\n\nBACK-TRANSLATION:\n{$backTranslation}\n\n{$langName} TEXT:\n{$translated}",
                ],
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
        ]);

        $result = json_decode($content, true);

        if (!$result || !isset($result['overall'])) {
            return ['score' => 7.0, 'notes' => 'Quality assessment failed to parse'];
        }

        $notes = '';
        if (!empty($result['issues'])) {
            $notes = implode('; ', $result['issues']);
        }

        return [
            'score' => (float) $result['overall'],
            'notes' => $notes,
            'details' => $result,
        ];
    }

    /**
     * Step 3b: Auto-Refine
     * If quality score is below threshold, attempt to fix issues automatically.
     */
    private function refine(
        string $original,
        string $translated,
        string $backTranslation,
        array $quality,
        string $langCode,
        string $langName
    ): array {
        $issues = $quality['notes'] ?? 'Meaning drift detected';

        $response = $this->chatWithRetry([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<PROMPT
You are a senior {$langName} editor specializing in children's literature. A translation has quality issues that need fixing.

Your task: Revise the {$langName} translation to fix the identified issues while keeping it natural and age-appropriate.

RULES:
1. Fix the specific issues identified
2. Keep the text natural and flowing in {$langName}
3. Keep it suitable for children ages 5-8
4. Return ONLY the revised {$langName} text — no explanations
PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => <<<TEXT
ORIGINAL ENGLISH:
{$original}

CURRENT {$langName} TRANSLATION:
{$translated}

BACK-TRANSLATION (showing what it currently means):
{$backTranslation}

ISSUES TO FIX:
{$issues}

Please provide the revised {$langName} text:
TEXT,
                ],
            ],
            'temperature' => 0.7,
        ]);

        return [
            'translation' => $response,
            'refined' => true,
        ];
    }

    /**
     * Build a glossary of terms that should never be translated.
     * Extracts character names, place names, etc. from the book.
     */
    private function buildGlossary(Book $book): array
    {
        $glossary = [];

        // Always preserve the book title characters/places
        // Extract proper nouns from the first few pages
        $sampleText = $book->pages()
            ->whereNotNull('extracted_text')
            ->orderBy('page_number')
            ->limit(5)
            ->pluck('extracted_text')
            ->implode(' ');

        if (empty($sampleText)) {
            return $glossary;
        }

        $content = $this->chatWithRetry([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract all proper nouns (character names, place names, brand names) from this children\'s book text. '
                        . 'Return them as a JSON array of strings. Only include names that should NOT be translated. '
                        . 'Example: {"names": ["Kolulu", "Mthombothi Studios"]}',
                ],
                ['role' => 'user', 'content' => $sampleText],
            ],
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ]);

        $result = json_decode($content, true);

        if (is_array($result)) {
            // Handle both {names: [...]} and [...] formats
            $candidates = $result['names'] ?? $result['proper_nouns'] ?? $result['terms'] ?? $result;
            if (is_array($candidates)) {
                // Flatten and filter: only keep strings
                foreach ($candidates as $item) {
                    if (is_string($item) && !empty(trim($item))) {
                        $glossary[] = trim($item);
                    }
                }
            }
        }

        Log::info("Built glossary for book #{$book->id}", ['terms' => $glossary]);

        return $glossary;
    }

    /**
     * Persist glossary terms to translation memory as locked entries.
     * Called once at the start of translation to ensure consistency.
     */
    private function persistGlossaryToMemory(Book $book, string $languageCode): void
    {
        foreach ($this->glossary as $term) {
            TranslationMemory::updateOrCreate(
                [
                    'book_id' => $book->id,
                    'language_code' => $languageCode,
                    'source_term' => $term,
                ],
                [
                    'translated_term' => $term, // Keep as-is
                    'category' => 'proper_noun',
                    'locked' => true,
                    'context' => 'Auto-detected proper noun — do not translate',
                    'confidence' => 10.0,
                ]
            );
        }
    }

    /**
     * Detect if a page is metadata (copyright, publisher info, etc.)
     */
    private function isMetadataPage(string $text): bool
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
     * Simple translation for metadata pages (copyright, etc.)
     */
    private function translateMetadata(string $text, string $langCode, string $langName): string
    {
        return $this->chatWithRetry([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "Translate this publisher/copyright page to {$langName}. "
                        . "Keep names, URLs, ISBNs, and dates exactly as-is. "
                        . "Only translate general phrases like 'Published by', 'Printed by', etc. "
                        . "Return only the translated text.",
                ],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0.2,
        ]);
    }

    /**
     * Detect if a page is a vocabulary/word list/educational page.
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
     * Translate a vocabulary/educational page with structure-aware approach.
     * Uses the brief's educational prompt for proper handling of:
     * - Word lists (one-to-one translation)
     * - High frequency words (educational adaptation)
     * - Phonics (Afrikaans phonics rules, NOT literal translation)
     */
    private function translateVocabularyPage(
        string $text,
        string $langCode,
        string $langName,
        string $fullStory
    ): string {
        $glossaryStr = '';
        if (!empty($this->glossary)) {
            $glossaryStr = "\nGLOSSARY (keep these unchanged): " . implode(', ', $this->glossary);
        }

        $systemPrompt = <<<PROMPT
This page is from an educational children's book (ages 5-8, Foundation Phase). You must analyse each section by its educational function before translating.

The page contains three types of content:

1. WORD LIST (headed "WORDS" or similar):
   - Translate every word individually into natural South African {$langName}
   - These are vocabulary words from the story — use story context to resolve ambiguity
   - Return exactly one translated word for every source word
   - Preserve the original order
   - Use age-appropriate {$langName} vocabulary

2. HIGH FREQUENCY WORDS:
   - These are common sight words for early readers
   - Translate each word according to its meaning in the story context
   - Keep them as simple, common {$langName} words appropriate for Grade 1-2

3. PHONICS / LANGUAGE EXERCISES:
   - DO NOT translate English phonics patterns literally
   - English patterns like "oa", "ai", "ay", "wh" do NOT exist in {$langName}
   - Create equivalent {$langName} phonics exercises that teach valid {$langName} spelling rules
   - Use natural {$langName} example words appropriate for the age group
   - Preserve the educational objective and approximate difficulty level
   - Example: English "oa - float" → {$langName} equivalent sound-spelling pattern with examples
{$glossaryStr}

OUTPUT FORMAT:
Return the content with clear section headers (WOORDE, HOË FREKWENSIE WOORDE, FONIES for Afrikaans).
Put each word on its own line. Keep the structure clear.
Do NOT add explanations, notes, or commentary.

STORY CONTEXT (to help resolve word meanings):
The book is about a child called Kolulu who lives in an African setting with forests, rivers, waterfalls, and wildlife. Activities include swimming, sports, table tennis, reading, and exploring outdoors.
PROMPT;

        return $this->chatWithRetry([
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Translate this educational page:\n\n{$text}"],
            ],
            'temperature' => 0.3, // Low temperature for educational accuracy
        ]);
    }

    /**
     * V8 MANIFEST-BASED TRANSLATION
     * ==============================
     * Translate a book using its page manifest (stable content IDs).
     * Instead of sending flat page text, sends structured items with IDs
     * and expects translations keyed to those same IDs.
     * 
     * This ensures 1:1 coverage and proper mapping for rendering.
     * Falls back to the legacy translate() method if no manifest exists.
     */
    public function translateWithManifest(Book $book, string $languageCode): Translation
    {
        // Check if manifest exists
        if (!$book->manifest_path || !\Illuminate\Support\Facades\Storage::disk('public')->exists($book->manifest_path)) {
            Log::info("No manifest for book {$book->id}, falling back to legacy translation.");
            return $this->translate($book, $languageCode);
        }

        $languageName = self::SUPPORTED_LANGUAGES[$languageCode]
            ?? throw new \InvalidArgumentException("Unsupported language: {$languageCode}");

        $translation = Translation::updateOrCreate(
            ['book_id' => $book->id, 'language_code' => $languageCode],
            ['language_name' => $languageName, 'status' => 'processing']
        );

        // Load manifest
        $manifestJson = \Illuminate\Support\Facades\Storage::disk('public')->get($book->manifest_path);
        $manifest = json_decode($manifestJson, true);

        if (!$manifest || empty($manifest['pages'])) {
            Log::warning("Invalid manifest for book {$book->id}, falling back to legacy.");
            return $this->translate($book, $languageCode);
        }

        // Build glossary
        $this->glossary = $this->buildGlossary($book);
        $this->persistGlossaryToMemory($book, $languageCode);

        // Process each page using its manifest
        foreach ($manifest['pages'] as $pageManifest) {
            $pageNum = $pageManifest['page_number'];
            $pageType = $pageManifest['page_type'] ?? 'unknown';
            $regions = $pageManifest['regions'] ?? [];

            // Collect items to translate for this page
            $translatableItems = [];
            foreach ($regions as $region) {
                $policy = $region['translation_policy'] ?? 'preserve';
                if ($policy === 'preserve') continue;

                foreach ($region['items'] ?? [] as $item) {
                    $translatableItems[] = [
                        'id' => $item['id'],
                        'text' => $item['text'],
                        'role' => $region['semantic_role'] ?? 'unknown',
                        'policy' => $policy,
                    ];
                }
            }

            if (empty($translatableItems)) continue;

            // Build page-level structured translation request
            $translatedItems = $this->translateManifestPage(
                $translatableItems, $pageType, $languageCode, $languageName, $pageNum
            );

            // Store as legacy format for backward compatibility with both renderers
            // The V8 renderer can also access the manifest items directly
            $translatedText = $this->manifestItemsToLegacyText($translatedItems, $pageType);

            // Determine quality flag
            $coverage = count($translatedItems) / max(count($translatableItems), 1);
            $flag = $coverage >= 0.95 ? 'green' : ($coverage >= 0.7 ? 'yellow' : 'red');

            TranslatedPage::updateOrCreate(
                ['translation_id' => $translation->id, 'page_number' => $pageNum],
                [
                    'translated_text' => $translatedText,
                    'confidence_score' => $coverage * 10,
                    'quality_flag' => $flag,
                    'quality_notes' => "Manifest-based: {$coverage}% coverage ({$pageType})",
                    'review_status' => 'unreviewed',
                ]
            );
        }

        $translation->update(['status' => 'draft']);
        return $translation->fresh();
    }

    /**
     * Translate a page's manifest items using structured GPT request.
     * Sends items with IDs, expects translations keyed to those IDs.
     */
    private function translateManifestPage(
        array $items,
        string $pageType,
        string $langCode,
        string $langName,
        int $pageNumber
    ): array {
        $glossaryStr = '';
        if (!empty($this->glossary)) {
            $glossaryStr = "\nGLOSSARY (keep unchanged): " . implode(', ', $this->glossary);
        }

        $systemPrompt = <<<PROMPT
You are translating a children's book page into South African {$langName} (ages 5-8).

You will receive a JSON array of items, each with an "id" and "text" field.
Return ONLY a JSON array with objects: {"id": "...", "translation": "..."}

RULES:
- Every source ID must appear exactly once in your response.
- Do NOT add, remove, or reorder items.
- Return ONLY valid JSON. Start with [ and end with ].
- For word_list items: translate to a single word equivalent.
- For story_prose: translate the full text naturally as a flowing paragraph.
- For book_subtitle: translate the title naturally.
- For phonics (educational_adaptation): create equivalent {$langName} phonics at same difficulty.
- For high_frequency_words: translate to the {$langName} equivalent.
- For table headers (translate_headers): translate the header text.
{$glossaryStr}

Page type: {$pageType}, Page number: {$pageNumber}
PROMPT;

        // Build the items JSON for the request
        $requestItems = array_map(fn($item) => [
            'id' => $item['id'],
            'text' => $item['text'],
            'role' => $item['role'],
        ], $items);

        $response = $this->chatWithRetry([
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode($requestItems, JSON_UNESCAPED_UNICODE)],
            ],
            'temperature' => 0.3,
        ]);

        // Parse response
        $parsed = json_decode($response, true);
        if (!is_array($parsed)) {
            // Try to extract JSON from response
            if (preg_match('/\[.*\]/s', $response, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
        }

        if (!is_array($parsed)) {
            Log::warning("Failed to parse manifest translation response for page {$pageNumber}");
            return [];
        }

        return $parsed;
    }

    /**
     * Convert manifest translation items back to legacy flat text format.
     * This allows both V7 and V8 renderers to use the same stored translations.
     */
    private function manifestItemsToLegacyText(array $items, string $pageType): string
    {
        $lines = [];
        foreach ($items as $item) {
            if (isset($item['translation']) && !empty($item['translation'])) {
                $lines[] = $item['translation'];
            }
        }

        if ($pageType === 'story') {
            // Story pages: join into one paragraph
            return implode(' ', $lines);
        }

        // All other page types: one item per line
        return implode("\n", $lines);
    }
}
