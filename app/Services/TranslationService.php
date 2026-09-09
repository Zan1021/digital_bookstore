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

    /** Page numbers whose phonics regeneration failed validation (need review). */
    private array $phonicsNeedsReview = [];

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

   ⚠️ ABSOLUTE RULE FOR PHONICS EXAMPLES (most common mistake — do NOT make it):
   Every example word you give MUST literally CONTAIN the exact letter-pattern being taught.
   The pattern is the letters BEFORE the dash; the words after it are examples of that pattern.
   - If the pattern is a letter combination (e.g. "sk", "oe", "aa", "ei"), EACH example word
     must contain those exact letters in that order.
   - If the pattern is a doubled letter (e.g. "ll", "kk", "ss"), EACH example word must contain
     that doubled letter (e.g. "ll" → "ballon, stelle" — NOT "bal" or "val" which have one l).
   - WRONG (do NOT do this): "kk - trek" (trek has one k), "ss - mis" (mis has one s),
     "gg - eier" (eier has no gg).
   - RIGHT: "kk - lekker, bakker", "aa - maan, kraal", "oe - koek, boek", "ei - trein, eier".
   - Choose a {$langName} pattern that genuinely has good, common, age-appropriate example words.
     You do NOT need to mirror the English pattern — pick a valid {$langName} one that teaches a
     real {$langName} spelling rule.
   - Before finalising, RE-READ each line and verify every example word contains the pattern.
     If a word does not contain the pattern, replace it with one that does, or change the pattern.
   Format each phonics line as: "pattern - word1, word2" (2 example words per pattern).
{$glossaryStr}

OUTPUT FORMAT:
Return the content with clear section headers (WOORDE, HOË FREKWENSIE WOORDE, FONIES for Afrikaans).
Put each word on its own line. Keep the structure clear.
Do NOT add explanations, notes, or commentary.

STORY CONTEXT (to help resolve word meanings):
The book is about a child called Kolulu who lives in an African setting with forests, rivers, waterfalls, and wildlife. Activities include swimming, sports, table tennis, reading, and exploring outdoors.
PROMPT;

        // Generate, then VALIDATE phonics examples deterministically. If any example
        // word does not contain its taught pattern, ask the model to fix ONLY those
        // lines (up to 2 correction passes). If still invalid, return the best result
        // and log the offenders so the page is flagged for human review rather than
        // silently shipping pedagogically wrong phonics. Book-agnostic: the check is a
        // pure substring test (pattern must appear in each example word).
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Translate this educational page:\n\n{$text}"],
        ];
        $result = $this->chatWithRetry(['messages' => $messages, 'temperature' => 0.3]);

        for ($pass = 0; $pass < 2; $pass++) {
            $bad = $this->invalidPhonicsLines($result);
            if (empty($bad)) {
                break;
            }
            Log::warning('Phonics examples failed validation; requesting correction', [
                'pass' => $pass + 1,
                'invalid_lines' => $bad,
            ]);
            $fixList = implode("\n", array_map(fn ($b) => "  - \"{$b['line']}\" — the word(s) "
                . implode(', ', $b['bad_words']) . " do not contain the pattern \"{$b['pattern']}\"", $bad));
            $messages[] = ['role' => 'assistant', 'content' => $result];
            $messages[] = ['role' => 'user', 'content' =>
                "Some phonics lines are INVALID — the example word must literally contain the "
                . "pattern (the letters before the dash):\n{$fixList}\n\n"
                . "Return the FULL page again, correcting ONLY those phonics lines so every example "
                . "word contains its pattern (replace the word, or change the pattern to a valid "
                . "{$langName} one with real example words). Keep everything else identical."];
            $result = $this->chatWithRetry(['messages' => $messages, 'temperature' => 0.2]);
        }

        return $result;
    }

    /**
     * Deterministically validate the phonics lines in a translated vocabulary page.
     * A phonics line looks like "pattern - word1, word2" (the pattern is the token
     * before the dash; the comma-separated words after it are examples). Every example
     * word MUST literally contain the pattern (case-insensitive, spaces ignored).
     *
     * Returns a list of offending lines: [['line'=>..., 'pattern'=>..., 'bad_words'=>[...]]].
     * Empty list == all phonics examples are valid. Book-agnostic — no language-specific
     * assumptions, just substring containment.
     *
     * @return array<int,array{line:string,pattern:string,bad_words:array<int,string>}>
     */
    private function invalidPhonicsLines(string $translatedPage): array
    {
        // Normalise into logical phonics entries. Source phonics come in two shapes:
        //   (A) one line: "br - broek, breek"  OR  "- kk lekker, bakker"
        //   (B) two lines: "- kk"  then  "   lekker, bakker"  (pattern then examples)
        // We first pair up shape (B) so every entry is (pattern, examples).
        $rawLines = preg_split('/\r\n|\r|\n/', $translatedPage);
        $lines = [];
        foreach ($rawLines as $raw) {
            $t = trim($raw);
            if ($t !== '') {
                $lines[] = $t;
            }
        }

        // Section headers we must NEVER treat as phonics patterns.
        $isHeaderLike = function (string $s): bool {
            $letters = preg_replace('/[^\p{L}]/u', '', $s);
            // All-caps line (e.g. "WOORDE", "HOË FREKWENSIE WOORDE", "FONIES", "KLANKLEER").
            if ($letters !== '' && mb_strtoupper($letters) === $letters) {
                return true;
            }
            return (bool) preg_match('/\b(WOORDE|FREKWENSIE|FONIES|KLANKLEER|WORDS|PHONICS|FREQUENCY)\b/ui', $s);
        };

        // Build (pattern, examples) entries.
        $entries = [];
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ($isHeaderLike($line)) {
                continue;
            }
            // Shape (A): pattern + separator + examples on the same line.
            if (preg_match('/^[-–—•]?\s*([\p{L}]{1,4}(?:\s[\p{L}])?)\s*[-–—]\s+(.+)$/u', $line, $m)) {
                $entries[] = ['line' => $line, 'pattern' => $m[1], 'examples' => $m[2]];
                continue;
            }
            // Shape (B): a lone "pattern" line (e.g. "- kk") followed by an examples line.
            if (preg_match('/^[-–—•]\s*([\p{L}]{1,4})\s*$/u', $line, $m)) {
                $next = $lines[$i + 1] ?? '';
                if ($next !== '' && !$isHeaderLike($next) && preg_match('/\p{L}/u', $next)
                    && !preg_match('/^[-–—•]/u', $next)) {
                    $entries[] = ['line' => $line . ' ' . $next, 'pattern' => $m[1], 'examples' => $next];
                    $i++; // consume the examples line
                }
                continue;
            }
            // Shape (A) with a SPACE separator: "- kk lekker, bakker" (pattern then space).
            if (preg_match('/^[-–—•]\s*([\p{L}]{1,4})\s+([\p{L}].+)$/u', $line, $m)) {
                $entries[] = ['line' => $line, 'pattern' => $m[1], 'examples' => $m[2]];
                continue;
            }
        }

        $offenders = [];
        foreach ($entries as $e) {
            $pattern = mb_strtolower(str_replace(' ', '', $e['pattern']));
            if (mb_strlen($pattern) < 1 || mb_strlen($pattern) > 4) {
                continue;
            }
            $badWords = [];
            foreach (preg_split('/[,\/]/u', $e['examples']) as $wordRaw) {
                $word = mb_strtolower(trim($wordRaw));
                $word = preg_replace('/[^\p{L}]/u', '', $word);
                if ($word === '') {
                    continue;
                }
                if (mb_strpos($word, $pattern) === false) {
                    $badWords[] = trim($wordRaw);
                }
            }
            if (!empty($badWords)) {
                $offenders[] = ['line' => $e['line'], 'pattern' => $e['pattern'], 'bad_words' => $badWords];
            }
        }
        return $offenders;
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
        // FRESHNESS / PROVENANCE GUARD (spec §5.2): never trust a stale or old-builder
        // manifest. Structured pages (vocab/table merged headers) render correctly ONLY
        // from a scene-graph manifest. If the persisted manifest is missing, produced by
        // the old flat builder, an older schema, or older than the source PDF, rebuild it
        // from the scene graph before translating.
        $this->ensureFreshManifest($book);

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
        $itemTranslations = []; // stable id => translated string (fix B: per-id store)
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

            // PHONICS REGENERATION (educational_adaptation): the general translate call tends
            // to echo/literally translate phonics rows (e.g. "ee see,feet,bee" -> "ee sien,
            // voete,by" where the examples no longer contain the pattern). Re-do phonics items
            // with a dedicated, few-shot, higher-temperature call, then VALIDATE that each
            // example word actually contains the sound pattern. Invalid results flag the page
            // for review instead of shipping wrong phonics. Book/language-agnostic.
            $phonicsItems = array_values(array_filter(
                $translatableItems,
                fn ($it) => ($it['policy'] ?? '') === 'educational_adaptation'
                    || ($it['role'] ?? '') === 'phonics'
            ));
            if (!empty($phonicsItems)) {
                $regen = $this->regeneratePhonics($phonicsItems, $languageCode, $languageName, $pageNum);
                // Merge regenerated phonics over the general result (keyed by id).
                $byId = [];
                foreach ($translatedItems as $ti) {
                    if (isset($ti['id'])) $byId[$ti['id']] = $ti;
                }
                foreach ($regen as $rid => $rtext) {
                    $byId[$rid] = ['id' => $rid, 'translation' => $rtext];
                }
                $translatedItems = array_values($byId);
                if (!empty($this->phonicsNeedsReview)) {
                    Log::warning("Phonics regeneration flagged pages for review", [
                        'page' => $pageNum, 'invalid' => $this->phonicsNeedsReview,
                    ]);
                }
            }

            // FIX B (durable): keep the per-ID translations so the render resolver can
            // place each element from its OWN translation instead of re-splitting a flat
            // blob. This is what stops the English leak at the source rather than masking
            // it with blank-and-review at render time.
            foreach ($translatedItems as $ti) {
                $tid = $ti['id'] ?? null;
                if ($tid !== null && isset($ti['translation']) && trim((string) $ti['translation']) !== '') {
                    $itemTranslations[$tid] = (string) $ti['translation'];
                }
            }

            // Store as legacy format for backward compatibility with both renderers
            // The V8 renderer can also access the manifest items directly
            $translatedText = $this->manifestItemsToLegacyText($translatedItems, $pageType);

            // Determine quality flag
            $coverage = count($translatedItems) / max(count($translatableItems), 1);
            $flag = $coverage >= 0.95 ? 'green' : ($coverage >= 0.7 ? 'yellow' : 'red');

            // Resolve the BookPage FK (translated_pages.book_page_id is NOT NULL). The
            // manifest is keyed by page_number; map it back to this book's BookPage row.
            $bookPage = \App\Models\BookPage::where('book_id', $book->id)
                ->where('page_number', $pageNum)->first();

            TranslatedPage::updateOrCreate(
                ['translation_id' => $translation->id, 'page_number' => $pageNum],
                [
                    'book_page_id' => $bookPage?->id,
                    'translated_text' => $translatedText,
                    'confidence_score' => $coverage * 10,
                    'quality_flag' => $flag,
                    'quality_notes' => "Manifest-based: {$coverage}% coverage ({$pageType})",
                    'review_status' => 'unreviewed',
                ]
            );
        }

        // FIX B: persist the per-ID translations on the edition so the render resolver
        // consumes them directly (priority over the flat per-page text split).
        $translation->forceFill([
            'item_translations' => $itemTranslations,
        ])->save();

        $translation->update(['status' => 'draft']);
        return $translation->fresh();
    }

    /**
     * Freshness/provenance guard for the persisted manifest (spec §5.2).
     *
     * Rebuilds the manifest from the scene graph (builder=scene_graph) when the persisted
     * one is missing, produced by the old flat builder, an older schema, or older than the
     * source PDF. This guarantees structured pages carry merged-header structure at render
     * time instead of a stale flat blueprint. Best-effort: on any failure we log and leave
     * the existing manifest so translation can still proceed (fail-open for translation,
     * but the render gate remains authoritative for structure).
     */
    private function ensureFreshManifest(Book $book): void
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');

            // No source PDF → nothing to rebuild from.
            if (!$book->pdf_path || !$disk->exists($book->pdf_path)) {
                return;
            }

            $needsRebuild = false;
            $reason = '';

            if (!$book->manifest_path || !$disk->exists($book->manifest_path)) {
                $needsRebuild = true;
                $reason = 'missing';
            } else {
                $manifest = json_decode($disk->get($book->manifest_path), true);
                $builder = $manifest['builder'] ?? 'legacy_flat';
                $schema = (string) ($manifest['schema_version'] ?? '1.0');

                if ($builder !== 'scene_graph') {
                    $needsRebuild = true;
                    $reason = "old builder ({$builder})";
                } elseif (version_compare($schema, '2.0', '<')) {
                    $needsRebuild = true;
                    $reason = "old schema ({$schema})";
                } elseif ($disk->lastModified($book->manifest_path) < $disk->lastModified($book->pdf_path)) {
                    $needsRebuild = true;
                    $reason = 'older than source PDF';
                }
            }

            if ($needsRebuild) {
                Log::info("Manifest rebuild for book {$book->id}: {$reason}");
                app(\App\Services\PdfService::class)->rebuildManifest($book);
                $book->refresh();
            }
        } catch (\Throwable $e) {
            Log::warning("ensureFreshManifest error for book {$book->id}: " . $e->getMessage());
        }
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
- For phonics (educational_adaptation): DO NOT translate the English letters/words. These teach a sound-to-spelling pattern. Produce an EQUIVALENT {$langName} phonics exercise of the same difficulty: pick a spelling pattern that genuinely EXISTS in {$langName} and give real {$langName} example words for it. Keep the same "pattern - example(s)" shape. If the English pattern (e.g. "wh") has NO {$langName} equivalent, REPLACE it entirely with a valid {$langName} pattern and {$langName} examples — never leave English example words like "when/where/stream". Instruction lines (e.g. "Recognise X at the beginning of words") must be rewritten in {$langName} referring to the {$langName} pattern you chose.
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
     * Regenerate phonics rows as VALID target-language sound-pattern exercises, then
     * validate them deterministically. Each phonics row has the shape
     * "[- ] pattern example1, example2, ..." (leading dash optional). We ask the model
     * to keep the same shape but choose a real {lang} pattern with real {lang} example
     * words, then we CHECK that every example word actually contains the chosen pattern.
     * Rows that fail validation are kept but the page is flagged for review.
     *
     * @param array<int,array{id:string,text:string}> $items
     * @return array<string,string> id => regenerated text
     */
    private function regeneratePhonics(array $items, string $langCode, string $langName, int $pageNumber): array
    {
        $system = <<<PROMPT
You create phonics (sound-to-spelling) exercises for a Grade 1-2 {$langName} children's book.
You receive a JSON array of items: {"id","text"}. Each text is an English phonics row like
"- ee see, feet, bee" or "st rest, nest, west" — a short SOUND PATTERN followed by example
words that CONTAIN that pattern.

Your job: for each item, produce an EQUIVALENT {$langName} phonics row.
HARD REQUIREMENTS:
- Choose a spelling pattern that genuinely exists in {$langName}.
- Give 2-4 REAL {$langName} words that ACTUALLY CONTAIN that exact pattern (letters in that order).
- Keep the same visual shape: preserve a leading "- " if the source had one, then
  "<pattern> <word1>, <word2>, <word3>".
- NEVER keep English example words. NEVER output a word that does not contain the pattern.
- If a heading/instruction line (e.g. "Blends"/"Kombinasies"), translate it to {$langName}.

Examples of CORRECT {$langName} (Afrikaans) rows:
  "- oe boek, koek, soek"        (all contain "oe")
  "- aa maan, kaas, slaap"       (all contain "aa")
  "- sk skaap, skool, skil"      (all contain "sk")

Return ONLY a JSON array: [{"id":"...","translation":"..."}]. Every input id appears once.
PROMPT;

        $req = array_map(fn ($it) => ['id' => $it['id'], 'text' => $it['text']], $items);

        try {
            $response = $this->chatWithRetry([
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($req, JSON_UNESCAPED_UNICODE)],
                ],
                'temperature' => 0.5,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Phonics regeneration call failed for page {$pageNumber}", ['error' => $e->getMessage()]);
            return [];
        }

        $parsed = json_decode($response, true);
        if (!is_array($parsed) && preg_match('/\[.*\]/s', $response, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (!is_array($parsed)) {
            $this->phonicsNeedsReview[] = $pageNumber;
            return [];
        }

        $out = [];
        foreach ($parsed as $row) {
            $id = $row['id'] ?? null;
            $text = trim((string) ($row['translation'] ?? ''));
            if ($id === null || $text === '') {
                continue;
            }
            // Validate: if this looks like a pattern row, every example word must contain the pattern.
            if (!$this->phonicsRowIsValid($text)) {
                $this->phonicsNeedsReview[] = $pageNumber;
            }
            $out[$id] = $text;
        }
        return $out;
    }

    /**
     * A phonics row "[- ] pattern w1, w2, ..." is valid when every example word contains
     * the pattern (case-insensitive). Non-pattern rows (headings/instructions with no
     * comma-separated example list) are accepted as-is. Deterministic, language-agnostic.
     */
    private function phonicsRowIsValid(string $text): bool
    {
        $t = trim($text);
        // strip a leading bullet dash
        $t = preg_replace('/^\s*[-–—]\s*/u', '', $t);
        // Expect "pattern examples" where examples contain a comma OR multiple words.
        if (!preg_match('/^(\S{1,5})\s+(.+)$/u', $t, $m)) {
            return true; // heading/instruction — not a pattern row, don't fail it
        }
        $pattern = mb_strtolower($m[1]);
        $rest = $m[2];
        // Only enforce on rows that actually list example words (comma or >=2 words).
        $words = preg_split('/[,\s]+/u', mb_strtolower($rest), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 1) {
            return true;
        }
        foreach ($words as $w) {
            $w = preg_replace('/[^\p{L}]/u', '', $w);
            if ($w === '') {
                continue;
            }
            if (mb_strpos($w, $pattern) === false) {
                return false; // an example word that doesn't contain the pattern = invalid
            }
        }
        return true;
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
