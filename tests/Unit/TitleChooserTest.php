<?php

namespace Tests\Unit;

use App\Intake\TitleChooser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Task 10.3 (Req 2.7): clean draft titles from the PDF title, the cover and the file name. */
class TitleChooserTest extends TestCase
{
    private const U = "\u{FFFD}"; // a character the font did not map

    private static function cover(array $lines): array
    {
        return array_map(fn ($l) => ['text' => $l[0], 'size' => $l[1]], $lines);
    }

    /** The real clues of the corpus books (2026-10-16): no PDF titles except Abby; ® unmapped on every Kolulu cover. */
    public static function corpus(): array
    {
        $publisher = ['MTHOMBOTHI STUDIOS', 16.8];

        return [
            "Kolulu's Home (unmapped apostrophe)" => ['', [['Kolulu'.self::U.'s Home', 49.0], $publisher], "Kolulu Engl Series 3 - 1 - Kolulu's Home.pdf", "Kolulu's Home", 'cover+file_name'],
            'A Fun Place' => ['', [['A Fun Place', 49.0], $publisher], 'Kolulu Engl Series 3 - 2 - A Fun Place.pdf', 'A Fun Place', 'cover+file_name'],
            'An Amazing World' => ['', [['An Amazing World', 41.1], $publisher], 'Kolulu Engl Series 3 - 3 - An Amazing World.pdf', 'An Amazing World', 'cover+file_name'],
            'I am Kolulu Taktaki (artwork finishes the title)' => ['', [['I am', 72.0], $publisher], 'Kolulu S2 BK1 Engl - I am Kolulu Taktaki.pdf', 'I am Kolulu Taktaki', 'cover+file_name'],
            'Play with me' => ['', [['Play with me', 54.0], $publisher], 'Kolulu S2 BK2 Engl - Play with me.pdf', 'Play with me', 'cover+file_name'],
            'Colours' => ['', [['Colours', 61.0], $publisher], 'Kolulu S2 BK5 Engl - Colours.pdf', 'Colours', 'cover+file_name'],
            'Math Fun With Abby (three cover lines, PDF title)' => ['Math Fun With Abby ', [['MATH FUN', 41.8], ['WITH', 41.8], ['ABBY', 41.8], ['Created by: Sidra Ayyaz', 20.0]],
                'Math Fun With Abby .pdf', 'Math Fun With Abby', 'pdf_title'],
        ];
    }

    #[DataProvider('corpus')]
    public function test_the_corpus_books_get_their_real_titles(string $meta, array $cover, string $file, string $title, string $source): void
    {
        $this->assertSame(['title' => $title, 'source' => $source], (new TitleChooser)->choose($meta, self::cover($cover), $file));
    }

    public function test_an_all_caps_cover_without_other_clues_becomes_title_case(): void
    {
        $this->assertSame(['title' => 'The Lost Kite', 'source' => 'cover'],
            (new TitleChooser)->choose('', self::cover([['THE LOST KITE', 40], ['by A. Writer', 12]]), 'scan0042.pdf'));
    }

    public function test_the_cover_wins_over_a_pdf_title_that_disagrees(): void
    {
        $this->assertSame(['title' => 'The Lost Kite', 'source' => 'cover'],
            (new TitleChooser)->choose('Final draft v3', self::cover([['The Lost Kite', 40]]), 'kite.pdf'));
    }

    public function test_authoring_leftovers_are_not_titles(): void
    {
        $choose = fn (string $meta) => (new TitleChooser)->choose($meta, [], 'Series 2 - 04 - The Lost Kite.pdf');

        foreach (['Untitled-1.indd', 'Microsoft Word - kite.docx', 'Document1', '   ', '12'] as $leftover) {
            $this->assertSame(['title' => 'The Lost Kite', 'source' => 'file_name'], $choose($leftover), "'{$leftover}' is not a title");
        }
        $this->assertSame(['title' => 'The Lost Kite', 'source' => 'pdf_title'], $choose('The Lost Kite'));
    }

    public function test_an_unreadable_cover_with_no_file_name_match_falls_back(): void
    {
        $chooser = new TitleChooser;
        $unreadable = self::cover([[self::U.self::U.'e L'.self::U.'st K'.self::U.'te', 40]]);

        $this->assertSame(['title' => 'Kite Story', 'source' => 'pdf_title'], $chooser->choose('Kite Story', $unreadable, 'k.pdf'));
        $this->assertSame(['title' => 'Book Seven', 'source' => 'file_name'], $chooser->choose('', $unreadable, 'BK7 - Book Seven.pdf'));
    }

    public function test_symbol_only_cover_lines_are_ignored_and_nothing_at_all_still_gives_a_title(): void
    {
        $chooser = new TitleChooser;
        // A large unmapped ® above a smaller real title: the ® line has no letters.
        $this->assertSame('Wild Numbers', $chooser->choose('', self::cover([[self::U, 49.1], ['Wild Numbers', 48.0]]), 'x.pdf')['title']);
        $this->assertSame(['title' => 'Untitled book', 'source' => 'file_name'], $chooser->choose('', [], '123.pdf'));
    }

    public function test_curly_apostrophes_and_stray_spaces_are_tidied(): void
    {
        $this->assertSame("Kolulu's Home", (new TitleChooser)->choose("  Kolulu\u{2019}s   Home ", self::cover([["KOLULU\u{2019}S HOME", 40]]), 'k.pdf')['title']);
    }
}
