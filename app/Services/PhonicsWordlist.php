<?php

namespace App\Services;

/**
 * Per-language real-word validity gate for phonics example words.
 *
 * The phonics substring check (every example word must contain its taught
 * pattern) catches patternless words like "klipfisk" under "ft", but it CANNOT
 * tell a real word from an invented one that happens to contain the substring
 * (e.g. "priek" under "iek", "kraft" under "ft"). This class closes that gap:
 * it loads a plain-text wordlist asset for a language and answers whether a word
 * is a known real word of that language.
 *
 * DESIGN — advisory, fail-safe, book/language-agnostic:
 *   - Assets live in storage/app/private/phonics_wordlists/<lang>.txt, one
 *     lowercase word per line (built by scripts/build_phonics_wordlist.py).
 *   - If NO wordlist exists for a language, {@see hasWordlist()} returns false and
 *     callers skip the real-word check entirely (substring-only, as before). No
 *     language is penalised for lacking an asset.
 *   - Lists are loaded once per language and cached in-process.
 *   - Matching is accent-aware (NFC) and case-insensitive.
 *
 * This is a VALIDITY signal, not an authority: callers use a miss to FLAG a page
 * for human review, never to hard-reject text, so imperfect list coverage degrades
 * gracefully instead of blanking pages.
 */
class PhonicsWordlist
{
    /** @var array<string,array<string,true>> lang code => set of words (word => true) */
    private static array $cache = [];

    /** Absolute directory holding the <lang>.txt assets. Overridable for tests. */
    private static ?string $dirOverride = null;

    /**
     * Point the loader at a custom asset directory (used by tests). Pass null to
     * restore the default storage path. Clears the in-process cache.
     */
    public static function useDirectory(?string $dir): void
    {
        self::$dirOverride = $dir;
        self::$cache = [];
    }

    private static function directory(): string
    {
        return self::$dirOverride
            ?? storage_path('app/private/phonics_wordlists');
    }

    private static function pathFor(string $lang): string
    {
        $lang = strtolower(trim($lang));
        // Guard against path traversal — language codes are short alnum tokens.
        $lang = preg_replace('/[^a-z]/', '', $lang);
        return rtrim(self::directory(), "/\\") . DIRECTORY_SEPARATOR . $lang . '.txt';
    }

    /** True when a wordlist asset exists for the language (non-empty). */
    public static function hasWordlist(string $lang): bool
    {
        return !empty(self::load($lang));
    }

    /**
     * Load (and cache) the word set for a language. Returns an empty set when no
     * asset exists, which callers interpret as "no gate available for this language".
     *
     * @return array<string,true>
     */
    private static function load(string $lang): array
    {
        $key = preg_replace('/[^a-z]/', '', strtolower(trim($lang)));
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $path = self::pathFor($key);
        $set = [];
        if ($key !== '' && is_file($path) && is_readable($path)) {
            $fh = @fopen($path, 'r');
            if ($fh !== false) {
                while (($line = fgets($fh)) !== false) {
                    $w = self::normalize($line);
                    if ($w !== '') {
                        $set[$w] = true;
                    }
                }
                fclose($fh);
            }
        }
        self::$cache[$key] = $set;
        return $set;
    }

    /** Normalise a word for comparison: trim, strip non-letters, NFC, lowercase. */
    private static function normalize(string $word): string
    {
        $w = trim($word);
        if ($w === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($w, \Normalizer::FORM_C);
            if (is_string($n)) {
                $w = $n;
            }
        }
        // Strip anything that is not a letter (keeps accented letters via \p{L}).
        $w = preg_replace('/[^\p{L}]/u', '', $w);
        return mb_strtolower($w);
    }

    /**
     * Is $word a known real word in $lang?
     *
     * Returns true when there is no wordlist for the language (gate unavailable →
     * do not penalise) OR the normalised word is present in the list. Returns false
     * only when a list EXISTS and the word is genuinely absent.
     */
    public static function isRealWord(string $word, string $lang): bool
    {
        $set = self::load($lang);
        if (empty($set)) {
            return true; // no gate for this language — treat as valid
        }
        $w = self::normalize($word);
        if ($w === '') {
            return true; // nothing to check
        }
        return isset($set[$w]);
    }
}
