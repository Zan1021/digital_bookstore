<?php

namespace App\Intake;

use Illuminate\Support\Str;

/**
 * Chooses a clean draft title for an uploaded book (task 10.3, Req 2.7) from three clues:
 * the PDF's own title, the cover's text and the file name. Book-agnostic (R1): no title,
 * publisher or series is known to the code.
 *
 *  1. The cover title is the largest text on the cover (lines of that size joined, top to bottom).
 *  2. When the PDF's own title says the same, it wins (its capitals are usually right).
 *  3. Else a part of the file name that starts with the cover title wins: it repairs characters
 *     the font left unmapped ("Kolulu\u{FFFD}s Home") and completes a title the artwork finishes
 *     ("I am" → "I am Kolulu Taktaki").
 *  4. Else the cover title itself, when it is fully readable (ALL CAPS become Title Case).
 *  5. Else a meaningful PDF title, else the last meaningful part of the file name.
 */
class TitleChooser
{
    private const UNKNOWN = "\u{FFFD}";

    /**
     * @param  list<array{text: string, size: float}>  $coverLines  top to bottom
     * @return array{title: string, source: string}  source: pdf_title | cover+file_name | cover | file_name
     */
    public function choose(?string $pdfTitle, array $coverLines, string $fileName): array
    {
        $segments = self::fileNameSegments($fileName);
        $meta = self::meaningfulPdfTitle($pdfTitle, $fileName) ? self::tidy($pdfTitle) : null;
        $cover = self::coverTitle($coverLines);

        if ($cover !== null) {
            if ($meta !== null && self::key($meta) === self::key($cover)) {
                return ['title' => $meta, 'source' => 'pdf_title'];
            }
            if ($segment = self::segmentStartingWith($segments, $cover)) {
                return ['title' => $segment, 'source' => 'cover+file_name'];
            }
            if (! str_contains($cover, self::UNKNOWN)) {
                return ['title' => self::caseFix($cover), 'source' => 'cover'];
            }
        }
        if ($meta !== null) {
            return ['title' => $meta, 'source' => 'pdf_title'];
        }

        // fileNameSegments already drops parts without letters, so a name like "123.pdf" leaves none.
        return ['title' => end($segments) ?: 'Untitled book', 'source' => 'file_name'];
    }

    /** The largest text on the cover; lines within 10% of that size are joined in order. */
    private static function coverTitle(array $lines): ?string
    {
        $lines = array_values(array_filter($lines, fn ($l) => preg_match('/\p{L}/u', (string) ($l['text'] ?? ''))));
        if ($lines === []) {
            return null;
        }
        $max = max(array_map(fn ($l) => (float) $l['size'], $lines));
        $title = self::tidy(implode(' ', array_map(fn ($l) => $l['text'],
            array_filter($lines, fn ($l) => (float) $l['size'] >= 0.9 * $max))));

        return mb_strlen(preg_replace('/[^\p{L}]/u', '', $title)) >= 2 ? $title : null;
    }

    /** Parts of the file name split on " - " / "_", meaningful ones only, in order. */
    private static function fileNameSegments(string $fileName): array
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $parts = preg_split('/\s+[-\x{2013}\x{2014}]\s+|_+/u', $stem) ?: [];

        return array_values(array_filter(array_map(self::tidy(...), $parts),
            fn ($p) => mb_strlen(preg_replace('/[^\p{L}]/u', '', $p)) >= 2));
    }

    /** The shortest file-name part whose letters start with the cover title's (unknown characters match anything or nothing). */
    private static function segmentStartingWith(array $segments, string $cover): ?string
    {
        $pattern = '/^'.implode('.?', array_map(fn ($part) => preg_quote($part, '/'), explode(self::UNKNOWN, self::key($cover, keepUnknown: true)))).'/u';
        $matches = array_filter($segments, fn ($s) => preg_match($pattern, self::key($s)) === 1);
        usort($matches, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));

        return $matches[0] ?? null;
    }

    private static function meaningfulPdfTitle(?string $title, string $fileName): bool
    {
        $title = self::tidy((string) $title);
        if (mb_strlen(preg_replace('/[^\p{L}]/u', '', $title)) < 2) {
            return false;
        }
        // Authoring-tool leftovers, not titles.
        if (preg_match('/\.(pdf|indd|idml|docx?|pages|ai|qxd|psd|pub)$/i', $title) || preg_match('/^(untitled|microsoft word|document\d*)\b/i', $title)) {
            return false;
        }

        return self::key($title) !== self::key(pathinfo($fileName, PATHINFO_FILENAME)) || self::fileNameSegments($fileName) === [self::tidy($title)];
    }

    /** Letters and digits only, lower case: how titles are compared. */
    private static function key(string $text, bool $keepUnknown = false): string
    {
        $text = mb_strtolower($text);

        return $keepUnknown ? preg_replace('/[^\p{L}\p{N}\x{FFFD}]/u', '', $text) : preg_replace('/[^\p{L}\p{N}]/u', '', $text);
    }

    private static function tidy(?string $text): string
    {
        $text = str_replace(["\u{2018}", "\u{2019}"], "'", (string) $text);

        return trim(preg_replace('/\s+/u', ' ', $text), " \t\n\r\0\x0B-_.,:;");
    }

    /** ALL CAPS cover text reads better as Title Case; anything else keeps its casing. */
    private static function caseFix(string $title): string
    {
        return preg_match('/\p{Ll}/u', $title) ? $title : Str::title(mb_strtolower($title));
    }
}
