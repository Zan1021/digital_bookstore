<?php

namespace Tests\Feature;

use App\Ai\Contracts\ImageEditProvider;
use App\Ai\ImageResult;
use App\Ai\Usage;
use App\Models\Book;
use App\Models\TranslatedPage;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The AI image model redraws artwork only behind text that is PAINTED INTO the picture
 * (found by the vision path). Ordinary text on the page (a real text layer, e.g. the story
 * paragraph under a picture) is removed and re-set without redrawing anything.
 *
 * Found on the first corpus baseline (2026-10-16, Kolulu's Home): with generative repair on,
 * all 12 story pages got an image edit for their normal story text — R37.53 and ~12 minutes,
 * every result rejected by the quality check.
 */
class GenerativeOnlyForPaintedTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_text_over_a_picture_never_calls_the_image_model(): void
    {
        Storage::fake(config('assets.disk'));
        config(['bookstore.illustration_text.enabled' => true, 'bookstore.illustration_text.generative' => true,
            'bookstore.illustration_text.verify' => false]);

        // A story-like page: a picture covering most of it and one line of real (selectable) text.
        $pdf = Storage::disk(config('assets.disk'))->path('books/translated/story_af.pdf');
        @mkdir(dirname($pdf), 0775, true);
        $make = new Process(['python', '-c', <<<'PY'
import sys, pymupdf
doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 200, 140), False)
pix.set_rect(pix.irect, (120, 180, 90))
page.insert_image(pymupdf.Rect(20, 20, 400, 400), pixmap=pix)
page.insert_text((40, 450), "Kolulu Taktaki woon in 'n wereld van verbeelding.", fontsize=12)
doc.save(sys.argv[1])
PY, $pdf]);
        $make->run();
        if (! $make->isSuccessful()) {
            $this->markTestSkipped('Python with PyMuPDF is needed: '.$make->getErrorOutput());
        }

        $book = Book::create(['title' => 'Story', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/story.pdf']);
        $edition = Translation::create(['book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'rendered_pdf_path' => 'books/translated/story_af.pdf']);
        $source = \App\Models\BookPage::create(['book_id' => $book->id, 'page_number' => 1,
            'extracted_text' => "Kolulu Taktaki lives in a world of imagination."]);
        TranslatedPage::create(['translation_id' => $edition->id, 'book_page_id' => $source->id, 'page_number' => 1,
            'translated_text' => "Kolulu Taktaki woon in 'n wêreld van verbeelding."]);

        $images = new class implements ImageEditProvider
        {
            public int $calls = 0;

            public function edit(string $imagePath, string $maskPath, string $prompt, array $options = []): ImageResult
            {
                $this->calls++;

                return new ImageResult(null, null, new Usage('fake', 'fake', 'art_repair', ['images' => 1], 0));
            }
        };
        $this->app->instance(ImageEditProvider::class, $images);

        $result = app(IllustrationTextService::class)->process($book, $edition);

        $this->assertSame(0, $images->calls, 'normal text must never be sent to the AI image model');
        $this->assertSame('native-text', $result['coverage'][1] ?? null,
            'the page was inspected and its text is ordinary text, not painted in (never "no-candidate")');
    }

    public function test_only_painted_in_text_may_use_the_image_model(): void
    {
        $svc = app(IllustrationTextService::class);
        $allowed = new \ReflectionMethod($svc, 'generativeAllowed');

        $this->assertTrue($allowed->invoke($svc, true, false), 'painted-in text (vision path), generative on');
        $this->assertFalse($allowed->invoke($svc, true, true), 'real text layer: nothing to redraw');
        $this->assertFalse($allowed->invoke($svc, false, false), 'generative switched off');
    }
}
