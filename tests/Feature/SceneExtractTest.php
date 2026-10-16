<?php

namespace Tests\Feature;

use App\Intake\IntakePreflight;
use App\Models\Block;
use App\Models\BlockTranslation;
use App\Models\Book;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\BlockStore;
use App\Scene\PageBatch;
use App\Scene\Stages\ExtractStage;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Task 11.1: text blocks per page, saved with IDs that survive re-extraction. */
class SceneExtractTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    private SourcePage $page;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
        $this->book = Book::create(['title' => 'Kite', 'page_count' => 1, 'pdf_path' => 'books/pdfs/kite.pdf', 'original_language' => 'en']);
        $this->page = SourcePage::create(['book_id' => $this->book->id, 'number' => 1, 'width_pt' => 420, 'height_pt' => 595]);
    }

    /** A real one-page PDF with a title, a publisher line and a three-line paragraph. */
    private function writePdf(string $paragraphFirstLine = 'Once upon a time'): void
    {
        $path = Storage::disk(config('assets.disk'))->path('books/pdfs/kite.pdf');
        @mkdir(dirname($path), 0775, true);
        $make = new Process(['python', '-c', <<<'PY'
import sys, pymupdf
doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
page.insert_text((60, 120), "The Lost Kite", fontsize=36, fontname="hebo", color=(0.4, 0.2, 0.6))
page.insert_text((60, 140), "LITTLE PRESS", fontsize=9, fontname="helv")
for i, line in enumerate([sys.argv[2], "a kite flew away", "over the hills."]):
    page.insert_text((60, 300 + i * 20), line, fontsize=14, fontname="helv")
doc.save(sys.argv[1])
PY, $path, $paragraphFirstLine]);
        $make->run();
        if (! $make->isSuccessful()) {
            $this->markTestSkipped('Python with PyMuPDF is needed: '.$make->getErrorOutput());
        }
    }

    private function extract(): void
    {
        app(ExtractStage::class)->handle($this->page->fresh(), null);
    }

    public function test_a_page_becomes_blocks_with_style_and_line_breaks(): void
    {
        $this->writePdf();
        $this->extract();

        $blocks = $this->page->blocks()->get();
        $this->assertSame(['The Lost Kite', 'LITTLE PRESS', "Once upon a time\na kite flew away\nover the hills."], $blocks->pluck('source_text')->all());
        $this->assertSame([0, 1, 2], $blocks->pluck('reading_order')->all());
        $title = $blocks[0];
        $this->assertSame(26, strlen($title->id), 'ULID');
        $this->assertSame(['paragraph', 'body', null], [$title->kind, $title->role, $title->treatment], 'provisional until tasks 11.2–14');
        $this->assertSame([36, 700, '#663399'], [$title->style['size'], $title->style['weight'], $title->style['color']]);
        $this->assertCount(3, $blocks[2]->style['lines'], 'line boxes kept');
        $this->assertEqualsWithDelta(60, $blocks[2]->bbox[0], 0.5);
    }

    public function test_extracting_again_keeps_every_id(): void
    {
        $this->writePdf();
        $this->extract();
        $before = $this->page->blocks()->pluck('id')->all();

        $this->extract();

        $this->assertSame($before, $this->page->blocks()->pluck('id')->all());
    }

    public function test_a_corrected_source_keeps_the_block_and_its_translation(): void
    {
        $this->writePdf();
        $this->extract();
        $para = $this->page->blocks()->where('source_text', 'like', 'Once upon%')->sole();
        $af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
        $bt = BlockTranslation::create(['translation_id' => $af->id, 'block_id' => $para->id, 'text' => "Eendag, lank gelede\n…", 'status' => 'approved']);
        $para->update(['treatment' => 'translate_reflow', 'treatment_confirmed' => true]);

        $this->writePdf('Long, long ago');   // the publisher fixed the first line
        $this->extract();

        $kept = Block::find($para->id);
        $this->assertNotNull($kept, 'same place, corrected text: same block');
        $this->assertStringStartsWith('Long, long ago', $kept->source_text);
        $this->assertSame(['translate_reflow', true], [$kept->treatment, $kept->treatment_confirmed], 'decisions about the block are kept');
        $this->assertTrue($bt->fresh()->isStale(), 'its translation survives and is marked out of date');
    }

    /** Extracted-block arrays for BlockStore, as scene_extract.py returns them. */
    private static function extracted(string $text, array $bbox): array
    {
        return ['text' => $text, 'bbox' => $bbox, 'lines' => [['text' => $text, 'bbox' => $bbox]],
            'style' => ['font' => 'Edu-Aid', 'size' => 12.0, 'color' => '#231f20', 'weight' => 400, 'italic' => false, 'rotation' => 0, 'align' => 'left', 'line_height' => null],
            'unmapped' => 0];
    }

    public function test_ids_follow_moved_blocks_and_new_or_removed_blocks_are_handled(): void
    {
        $store = app(BlockStore::class);
        $store->replacePage($this->page, [self::extracted('Title', [50, 50, 300, 90]), self::extracted('Page 3', [380, 560, 395, 575]),
            self::extracted('Old caption', [50, 400, 200, 420])]);
        $ids = $this->page->blocks()->pluck('id', 'source_text');

        $stats = $store->replacePage($this->page, [
            self::extracted('Page 3', [20, 560, 35, 575]),          // moved to the other corner
            self::extracted('Title', [50, 50, 300, 90]),            // unchanged (now listed second)
            self::extracted('A new speech bubble', [250, 300, 380, 340]),
        ]);

        $this->assertSame(['kept' => 2, 'added' => 1, 'removed' => 1], $stats);
        $now = $this->page->blocks()->pluck('id', 'source_text');
        $this->assertSame($ids['Title'], $now['Title']);
        $this->assertSame($ids['Page 3'], $now['Page 3']);
        $this->assertNotContains($ids['Old caption'], $now->all());
        $this->assertSame([0, 1, 2], $this->page->blocks()->pluck('reading_order')->all());
    }

    public function test_lists_are_stored_with_their_items_and_keep_their_ids(): void
    {
        $item = fn (string $text, float $y) => self::extracted($text, [80, $y, 300, $y + 20]) + ['kind' => 'list_item', 'parent' => 1, 'structure' => ['marker' => strtok($text, ' ').' -']];
        $page = [
            self::extracted('Titles in the series:', [60, 40, 400, 60]) + ['kind' => 'paragraph', 'parent' => null, 'structure' => null],
            self::extracted("1 - First\n2 - Second", [80, 80, 300, 124]) + ['kind' => 'list', 'parent' => null, 'structure' => null],
            $item('1 - First', 80), $item('2 - Second', 104),
        ];
        $store = app(BlockStore::class);
        $store->replacePage($this->page, $page);

        $blocks = $this->page->blocks()->get();
        $this->assertSame(['paragraph', 'list', 'list_item', 'list_item'], $blocks->pluck('kind')->all());
        $list = $blocks[1];
        $this->assertSame([$list->id, $list->id], [$blocks[2]->parent_id, $blocks[3]->parent_id]);
        $this->assertSame(['marker' => '2 -'], $blocks[3]->structure);
        $this->assertSame([$blocks[2]->id, $blocks[3]->id], $list->children()->pluck('id')->all());

        $store->replacePage($this->page, $page);
        $this->assertSame($blocks->pluck('id')->all(), $this->page->blocks()->pluck('id')->all(), 'ids survive a re-run');
        $this->assertSame($list->id, Block::find($blocks[3]->id)->parent_id);
    }

    public function test_a_kind_chosen_by_a_later_stage_is_kept(): void
    {
        $store = app(BlockStore::class);
        $store->replacePage($this->page, [self::extracted('WORDS', [74, 96, 146, 123]) + ['kind' => 'paragraph', 'parent' => null, 'structure' => null]]);
        $bubble = $this->page->blocks()->sole();
        $bubble->update(['kind' => 'bubble', 'structure' => ['tail' => 'left']]); // as classification (task 14) will

        $store->replacePage($this->page, [self::extracted('WORDS', [74, 96, 146, 123]) + ['kind' => 'paragraph', 'parent' => null, 'structure' => ['column' => 0, 'columns' => 3]]]);

        $kept = $bubble->fresh();
        $this->assertSame(['bubble', ['tail' => 'left']], [$kept->kind, $kept->structure]);
    }

    public function test_a_ruled_table_in_a_real_pdf_becomes_a_table_with_cells(): void
    {
        // Task 11.3: a word table whose "WORDS" heading spans two columns.
        $path = Storage::disk(config('assets.disk'))->path('books/pdfs/kite.pdf');
        @mkdir(dirname($path), 0775, true);
        $make = new Process(['python', '-c', <<<'PY'
import sys, pymupdf
doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
xs, ys = [40, 140, 240, 340], [60, 90, 300]
for y in ys:
    page.draw_line((xs[0], y), (xs[-1], y))
for k, x in enumerate(xs):
    page.draw_line((x, ys[1] if k == 1 else ys[0]), (x, ys[-1]))
font = pymupdf.Font("helv"); tw = pymupdf.TextWriter(page.rect)
for x, y, lines in [(110, 80, ["WORDS"]), (250, 80, ["SOUNDS"]), (50, 110, ["cat", "dog", "sun", "hat"]),
                    (150, 110, ["run", "fun", "bun", "nut"]), (250, 110, ["- sh", "- ch"])]:
    for i, line in enumerate(lines):
        tw.append((x, y + i * 15.6), line, font=font, fontsize=12)
tw.write_text(page)
doc.save(sys.argv[1])
PY, $path]);
        $make->run();
        if (! $make->isSuccessful()) {
            $this->markTestSkipped('Python with PyMuPDF is needed: '.$make->getErrorOutput());
        }

        $this->extract();

        $blocks = $this->page->blocks()->get();
        $this->assertSame(['table', 'cell', 'cell', 'cell', 'cell', 'cell'], $blocks->pluck('kind')->all());
        $table = $blocks[0];
        $this->assertSame([2, 3, true], [$table->structure['rows'], $table->structure['cols'], $table->structure['ruled']]);
        $this->assertSame(array_fill(0, 5, $table->id), $blocks->skip(1)->pluck('parent_id')->values()->all());
        $words = $blocks[1];
        $this->assertSame('WORDS', $words->source_text);
        $this->assertSame(['row' => 0, 'col' => 0, 'rowspan' => 1, 'colspan' => 2, 'header' => true], $words->structure);
        $this->assertSame([40, 60, 240, 90], array_map('intval', $words->allowed_area));
        $this->assertSame("- sh\n- ch", $blocks[5]->source_text, 'dash rows in a cell stay in the cell');

        $this->extract();
        $this->assertSame($blocks->pluck('id')->all(), $this->page->blocks()->pluck('id')->all(), 'ids survive a re-run');
        $this->assertSame($table->id, Block::find($blocks[5]->id)->parent_id);
    }

    public function test_a_numbered_list_in_a_real_pdf_becomes_a_list(): void
    {
        $path = Storage::disk(config('assets.disk'))->path('books/pdfs/kite.pdf');
        @mkdir(dirname($path), 0775, true);
        $make = new Process(['python', '-c', <<<'PY'
import sys, pymupdf
doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
page.insert_text((60, 60), "Titles in the series:", fontsize=16, fontname="helv")
font = pymupdf.Font("helv"); tw = pymupdf.TextWriter(page.rect)
for i, t in enumerate(["1 - The Lost Kite", "2 - A Windy Day", "3 - Home Again"]):
    tw.append((80, 100 + i * 20), t, font=font, fontsize=14)
tw.write_text(page)
doc.save(sys.argv[1])
PY, $path]);
        $make->run();
        if (! $make->isSuccessful()) {
            $this->markTestSkipped('Python with PyMuPDF is needed: '.$make->getErrorOutput());
        }

        $this->extract();

        $blocks = $this->page->blocks()->get();
        $this->assertSame(['paragraph', 'list', 'list_item', 'list_item', 'list_item'], $blocks->pluck('kind')->all());
        $this->assertSame(['1 - The Lost Kite', '2 - A Windy Day', '3 - Home Again'], $blocks->where('kind', 'list_item')->pluck('source_text')->values()->all());
        $this->assertSame(3, $blocks[1]->children()->count());
    }

    public function test_an_unreadable_page_fails_instead_of_saving_nothing(): void
    {
        Storage::disk(config('assets.disk'))->put('books/pdfs/kite.pdf', 'not a pdf at all');

        $this->expectExceptionMessage('Text extraction failed for page 1');
        $this->extract();
    }

    public function test_the_stage_depends_on_the_source_file_and_page(): void
    {
        $this->book->update(['source_checksum' => str_repeat('a', 64)]);

        $this->assertSame(['source' => str_repeat('a', 64), 'page' => 1], app(ExtractStage::class)->inputs($this->page->fresh(), null));
        $this->assertSame(ExtractStage::class, config('scene.stages.extract.handler'));
    }

    public function test_existing_books_can_be_extracted_by_command(): void
    {
        Bus::fake();
        $old = Book::create(['title' => 'Old book', 'page_count' => 2, 'pdf_path' => 'books/pdfs/old.pdf', 'original_language' => 'en']);
        Storage::disk(config('assets.disk'))->put('books/pdfs/old.pdf', '%PDF-1.7 old');
        $this->app->instance(IntakePreflight::class, new class extends IntakePreflight
        {
            public function run(string $absolutePath): array
            {
                $page = fn ($n) => ['number' => $n, 'width_pt' => 500, 'height_pt' => 700, 'trim_box' => [0, 0, 500, 700],
                    'trim_source' => 'whole_page', 'trim_review' => false, 'has_live_text' => true, 'kind' => 'live_text', 'text_chars' => 30];

                return ['ok' => true, 'rejections' => [], 'warnings' => [], 'page_count' => 2, 'pages' => [$page(1), $page(2)], 'crop' => null];
            }
        });

        $this->artisan('scene:extract', ['book' => [$old->id]])->expectsOutputToContain('2 pages queued')->assertSuccessful();

        $this->assertSame(2, $old->sourcePages()->count(), 'source pages made first');
        Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 2 && $b->jobs->first()->stage === 'extract');
    }

    public function test_a_finished_intake_queues_extraction(): void
    {
        $this->app->instance(PageBatch::class, $spy = new class extends PageBatch
        {
            public array $calls = [];

            public function dispatch(Book $book, string $stage, ?Translation $edition = null, ?iterable $pages = null): \Illuminate\Bus\Batch
            {
                $this->calls[] = [$book->id, $stage];

                return parent::dispatch($book, $stage, $edition, []); // record only: no page jobs in this test
            }
        });
        $this->app->instance(IntakePreflight::class, new class extends IntakePreflight
        {
            public function run(string $absolutePath): array
            {
                return ['ok' => true, 'rejections' => [], 'warnings' => [], 'page_count' => 1, 'crop' => null, 'meta' => [], 'cover_lines' => [],
                    'pages' => [['number' => 1, 'width_pt' => 420, 'height_pt' => 595, 'trim_box' => [0, 0, 420, 595], 'trim_source' => 'whole_page',
                        'trim_review' => false, 'has_live_text' => true, 'kind' => 'live_text', 'text_chars' => 30]]];
            }
        });
        $this->app->instance(\App\Services\PdfService::class, new class extends \App\Services\PdfService
        {
            public function processBook(Book $book): Book
            {
                $book->update(['page_count' => 1, 'status' => 'ready']);

                return $book;
            }

            public function detectCropMarks(string $pdfPath): ?array
            {
                return null;
            }
        });

        dispatch_sync(new \App\Jobs\IntakeBookJob($this->book->id));

        $row = \App\Jobs\IntakeBookJob::row($this->book->id);
        $this->assertSame('completed', $row->status, (string) $row->error_message);
        $this->assertSame([[$this->book->id, "extract"]], $spy->calls);
    }
}
