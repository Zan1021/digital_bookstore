<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\BlockTranslation;
use App\Models\Book;
use App\Models\PageStageState;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\PageStages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Task 9.4 (Req 3.6): a stage re-runs only the pages whose inputs changed. */
class PageStagesTest extends TestCase
{
    use RefreshDatabase;

    private PageStages $stages;

    private Book $book;

    private Translation $af;

    /** @var array<int, Block> page number => its story block */
    private array $blocks = [];

    /** @var list<string> "stage:page" per piece of work done */
    private array $done = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stages = app(PageStages::class);
        $this->book = Book::create(['title' => 'Kolulu', 'page_count' => 3, 'pdf_path' => 'k.pdf', 'original_language' => 'en']);
        $this->af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
        foreach ([1, 2, 3] as $n) {
            $page = SourcePage::create(['book_id' => $this->book->id, 'number' => $n, 'width_pt' => 509.72, 'height_pt' => 722.32]);
            $this->blocks[$n] = Block::create(['book_id' => $this->book->id, 'page_id' => $page->id, 'kind' => 'paragraph', 'role' => 'body',
                'treatment' => 'translate_reflow', 'bbox' => [40, 400, 330, 640], 'source_text' => "Story text {$n}."]);
            BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $this->blocks[$n]->id, 'text' => "Storieteks {$n}.", 'status' => 'draft']);
        }
    }

    private function pages()
    {
        return $this->book->sourcePages()->get();
    }

    /** A layout run whose inputs are the page's blocks and the edition's texts (as the real stage will be). */
    private function layout(?Translation $edition = null, bool $force = false, ?int $failOn = null)
    {
        $edition ??= $this->af;

        return $this->stages->run('layout', $this->pages(), $edition,
            inputs: fn ($page) => [$this->stages->pageBlocks($page), $this->stages->pageTexts($page, $edition)],
            work: function ($page) use ($failOn) {
                if ($page->number === $failOn) {
                    throw new \RuntimeException("layout failed on page {$page->number}");
                }
                $this->done[] = "layout:{$page->number}";
            },
            force: $force);
    }

    public function test_unchanged_pages_are_skipped(): void
    {
        $first = $this->layout();
        $second = $this->layout();

        $this->assertSame([[1, 2, 3], []], [$first->ran, $first->skipped]);
        $this->assertSame([[], [1, 2, 3]], [$second->ran, $second->skipped]);
        $this->assertSame(['stage' => 'layout', 'ran' => 0, 'skipped' => 3, 'pages' => []], $second->toMeta());
    }

    public function test_editing_one_translation_relays_out_only_that_page(): void
    {
        $this->layout();
        BlockTranslation::where('block_id', $this->blocks[2]->id)->first()->update(['text' => 'Ander storieteks.']);

        $this->assertSame([2], $this->layout()->ran);
    }

    public function test_editing_one_source_block_reruns_only_that_page(): void
    {
        $this->layout();
        $this->blocks[3]->update(['bbox' => [40, 410, 330, 650]]);

        $run = $this->layout();
        $this->assertSame([[3], [1, 2]], [$run->ran, $run->skipped]);
    }

    public function test_raising_a_stage_version_reruns_every_page(): void
    {
        $this->layout();
        config(['scene.stages.layout.version' => 2]);

        $this->assertSame([1, 2, 3], $this->layout()->ran);
    }

    public function test_a_failed_page_runs_again_next_time(): void
    {
        try {
            $this->layout(failOn: 2);
            $this->fail('the failure must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('layout failed on page 2', $e->getMessage());
        }
        $this->assertSame(['layout:1'], $this->done);

        $retry = $this->layout();
        $this->assertSame([[2, 3], [1]], [$retry->ran, $retry->skipped]);
    }

    public function test_force_and_invalidate_redo_work(): void
    {
        $this->layout();
        $this->assertSame([1, 2, 3], $this->layout(force: true)->ran);

        $page2 = $this->pages()->firstWhere('number', 2);
        $this->assertSame(1, $this->stages->invalidate($page2, 'layout', $this->af));
        $this->assertSame([2], $this->layout()->ran);
    }

    public function test_editions_and_stages_keep_separate_records(): void
    {
        $zu = Translation::create(['book_id' => $this->book->id, 'language_code' => 'zu', 'language_name' => 'isiZulu', 'status' => 'draft']);
        $this->layout();
        $this->assertSame([1, 2, 3], $this->layout($zu)->ran, 'Zulu has its own records');

        $extract = $this->stages->run('extract', $this->pages(), null,
            inputs: fn ($page) => [$page->number], work: fn () => null);
        $this->assertSame([1, 2, 3], $extract->ran, 'a book stage does not reuse a language stage record');

        BlockTranslation::create(['translation_id' => $zu->id, 'block_id' => $this->blocks[1]->id, 'text' => 'Indaba 1.', 'status' => 'draft']);
        $this->assertSame([], $this->layout()->ran, 'a Zulu edit leaves Afrikaans alone');
        $this->assertSame([1], $this->layout($zu)->ran);
        $this->assertSame(9, PageStageState::count(), "3 af layout + 3 zu layout + 3 extract");
    }

    public function test_stage_scope_is_checked(): void
    {
        $noop = fn () => [];
        foreach ([
            fn () => $this->stages->run('extract', [], $this->af, $noop, $noop),
            fn () => $this->stages->run('layout', [], null, $noop, $noop),
            fn () => $this->stages->run('typeset', [], null, $noop, $noop),
        ] as $wrong) {
            try {
                $wrong();
                $this->fail('a scope mix-up was accepted');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_key_order_does_not_change_the_inputs_hash(): void
    {
        $this->assertSame(
            $this->stages->inputsHash('layout', ['font' => 'Playpen Sans', 'size' => 12.0, 'nested' => ['b' => 1, 'a' => 2]]),
            $this->stages->inputsHash('layout', ['nested' => ['a' => 2, 'b' => 1], 'size' => 12.0, 'font' => 'Playpen Sans']),
        );
        $this->assertNotSame($this->stages->inputsHash('layout', ['x' => 1]), $this->stages->inputsHash('gates', ['x' => 1]),
            'the same inputs belong to different stages');
    }

    public function test_records_go_with_their_edition_and_page(): void
    {
        $this->layout();
        $this->stages->run('plates', $this->pages(), null, inputs: fn ($p) => [$p->number], work: fn () => null);
        $this->assertSame(6, PageStageState::count());

        $this->af->delete();
        $this->assertSame(3, PageStageState::count(), 'the edition took its layout records with it');

        $this->pages()->first()->delete();
        $this->assertSame(2, PageStageState::count());
    }
}
