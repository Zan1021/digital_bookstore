<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\BlockTranslation;
use App\Models\Book;
use App\Models\PageCheck;
use App\Models\ResolvedLayout;
use App\Models\SourcePage;
use App\Models\Translation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Task 9.1: the Scene Engine tables (design §4). */
class SceneTablesTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    private SourcePage $page;

    private Translation $af;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create(['title' => "Kolulu's Home", 'page_count' => 16, 'pdf_path' => 'books/pdfs/k.pdf', 'original_language' => 'en']);
        $this->page = SourcePage::create(['book_id' => $this->book->id, 'number' => 15, 'width_pt' => 509.72, 'height_pt' => 722.32,
            'trim_box' => [35, 35, 475, 687]]);
        $this->af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
    }

    private function block(array $attrs = []): Block
    {
        return Block::create($attrs + ['book_id' => $this->book->id, 'page_id' => $this->page->id, 'kind' => 'paragraph',
            'role' => 'body', 'bbox' => [40, 300, 400, 360], 'source_text' => 'Kolulu lives in a world of imagination.']);
    }

    public function test_a_word_table_page_saves_and_reads_back(): void
    {
        $table = $this->block(['kind' => 'table', 'role' => 'educational', 'treatment' => 'structure',
            'treatment_confidence' => 0.97, 'bbox' => [50, 50, 460, 680], 'source_text' => '', 'reading_order' => 1]);
        $heading = $this->block(['kind' => 'cell', 'role' => 'heading', 'parent_id' => $table->id, 'treatment' => 'translate_fixed',
            'source_text' => 'WORDS', 'structure' => ['row' => 0, 'col' => 0, 'colspan' => 3, 'header' => true], 'reading_order' => 2]);
        $words = $this->block(['kind' => 'cell', 'role' => 'educational', 'parent_id' => $table->id, 'treatment' => 'derive',
            'source_text' => "live\nworld\nimagination", 'structure' => ['row' => 1, 'col' => 0], 'reading_order' => 3,
            'style' => ['font' => 'Edu-Aid', 'size' => 12, 'align' => 'left'], 'visual_size_factor' => 0.983]);

        $this->assertSame(26, strlen($table->id), 'blocks have ULID ids');
        $this->assertSame([$table->id, $heading->id, $words->id], $this->page->blocks()->pluck('id')->all());
        $this->assertSame([$heading->id, $words->id], $table->children()->pluck('id')->all());
        $this->assertSame($table->id, $words->parent->id);
        $this->assertSame(['row' => 0, 'col' => 0, 'colspan' => 3, 'header' => true], $heading->fresh()->structure);
        $this->assertSame(0.983, $words->fresh()->visual_size_factor);
        $this->assertSame(3, $this->book->blocks()->count());
        $this->assertSame([15], $this->book->sourcePages()->pluck('number')->all());

        $bt = BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $heading->id, 'text' => 'WOORDE', 'status' => 'draft']);
        ResolvedLayout::create(['block_translation_id' => $bt->id, 'font_family' => 'Playpen Sans', 'font_size' => 11.8,
            'line_height' => 1.25, 'box' => [52, 52, 300, 80], 'ladder_step' => 1, 'fits' => true]);

        $this->assertSame('WOORDE', $this->af->blockTranslations()->first()->text);
        $this->assertSame(11.8, $bt->layout->font_size);
        $this->assertSame($heading->sourceHash(), $bt->source_hash, 'the source it was made from is stamped');
    }

    public function test_only_the_eight_treatments_and_known_kinds_and_roles_are_accepted(): void
    {
        foreach (Block::TREATMENTS as $t) {
            $this->assertSame($t, $this->block(['treatment' => $t])->treatment);
        }
        $this->assertNull($this->block(['treatment' => null])->treatment, 'unclassified is allowed');

        foreach ([['treatment' => 'summarise'], ['kind' => 'footnote'], ['role' => 'sidebar']] as $bad) {
            try {
                $this->block($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_translation_goes_stale_when_the_source_text_changes(): void
    {
        $block = $this->block();
        $bt = BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $block->id,
            'text' => "Kolulu woon in 'n wêreld van verbeelding.", 'status' => 'approved']);
        $this->assertFalse($bt->isStale());

        $block->update(['source_text' => 'Kolulu lives in a world of dreams.']);
        $this->assertTrue($bt->fresh()->isStale());
    }

    public function test_each_page_and_each_block_translation_exists_once(): void
    {
        $block = $this->block();
        BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $block->id, 'text' => 'a', 'status' => 'draft']);

        $this->expectException(QueryException::class);
        BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $block->id, 'text' => 'b', 'status' => 'draft']);
    }

    public function test_a_page_number_is_unique_per_book(): void
    {
        $this->expectException(QueryException::class);
        SourcePage::create(['book_id' => $this->book->id, 'number' => 15, 'width_pt' => 1, 'height_pt' => 1]);
    }

    public function test_deleting_a_book_removes_its_scene_and_deleting_a_table_keeps_its_cells(): void
    {
        $table = $this->block(['kind' => 'table', 'source_text' => '']);
        $cell = $this->block(['kind' => 'cell', 'parent_id' => $table->id]);
        $bt = BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $cell->id, 'text' => 'x', 'status' => 'draft']);
        PageCheck::create(['translation_id' => $this->af->id, 'page_id' => $this->page->id, 'results' => [['gate' => 'overflow', 'passed' => true]]]);

        $table->delete();
        $this->assertNull($cell->fresh()->parent_id, 'a cell outlives a removed table grouping');

        $this->book->delete();
        $this->assertSame([0, 0, 0, 0], [SourcePage::count(), Block::count(), BlockTranslation::whereKey($bt->id)->count(), PageCheck::count()]);
    }

    public function test_a_page_passes_only_when_every_gate_passed(): void
    {
        $check = fn (array $results, bool $claimed = true) => PageCheck::create(['translation_id' => $this->af->id,
            'page_id' => $this->page->id, 'results' => $results, 'passed' => $claimed])->passed;

        $this->assertTrue($check([['gate' => 'overflow', 'passed' => true], ['gate' => 'overlap', 'passed' => true]]));
        $this->assertFalse($check([['gate' => 'overflow', 'passed' => true], ['gate' => 'overlap', 'passed' => false, 'reason' => 'heading overprints cell']]));
        $this->assertFalse($check([]), 'no results is not a pass');
        $this->assertFalse($check([['gate' => 'overflow']]), 'a result without a verdict is not a pass');
    }

    public function test_the_ladder_step_must_be_one_to_seven(): void
    {
        $bt = BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $this->block()->id, 'text' => 'x', 'status' => 'draft']);

        $this->expectExceptionMessage('Fit ladder step must be 1–7');
        ResolvedLayout::create(['block_translation_id' => $bt->id, 'font_family' => 'Playpen Sans', 'font_size' => 12,
            'line_height' => 1.2, 'box' => [0, 0, 1, 1], 'ladder_step' => 9, 'fits' => false]);
    }
}
