<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\BlockTranslation;
use App\Models\Book;
use App\Models\ResolvedLayout;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\SceneCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Task 9.2: compiling scenes/{book}/scene.json and {lang}.json from the database (design §5). */
class SceneCompilerTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    private Block $story;

    private Block $pageNumber;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
        $this->book = Book::create(['title' => "Kolulu's Home", 'page_count' => 16, 'pdf_path' => 'books/pdfs/k.pdf', 'original_language' => 'en']);
        $page = SourcePage::create(['book_id' => $this->book->id, 'number' => 5, 'width_pt' => 538.6, 'height_pt' => 751.2,
            'plate_pdf_path' => 'plates/p5.pdf', 'plate_screen_path' => 'plates/p5@2x.webp']);
        // Created out of order: the scene must list blocks by reading order.
        $this->pageNumber = Block::create(['book_id' => $this->book->id, 'page_id' => $page->id, 'kind' => 'label', 'role' => 'label',
            'treatment' => 'keep', 'bbox' => [362, 712, 372, 724], 'reading_order' => 2, 'source_text' => '3']);
        $this->story = Block::create(['book_id' => $this->book->id, 'page_id' => $page->id, 'kind' => 'paragraph', 'role' => 'body',
            'treatment' => 'translate_reflow', 'treatment_confidence' => 0.96, 'bbox' => [38, 400, 330, 640],
            'allowed_area' => [30, 392, 352, 690], 'reading_order' => 1, 'visual_size_factor' => 0.983,
            'style' => ['font' => 'body', 'size' => 17.5, 'line' => 1.25, 'color' => '#1d1d1b', 'align' => 'left', 'weight' => 400],
            'source_text' => 'Open the little front door and step inside.']);
    }

    private function sceneFile(): array
    {
        return json_decode(Storage::disk(config('assets.disk'))->get("scenes/{$this->book->id}/scene.json"), true);
    }

    public function test_the_scene_file_follows_the_design_format(): void
    {
        $result = app(SceneCompiler::class)->compile($this->book);

        $this->assertSame("scenes/{$this->book->id}/scene.json", $result->path);
        $this->assertSame(1, $result->version);
        $scene = $this->sceneFile();
        $this->assertSame(['book_id' => $this->book->id, 'version' => 1, 'format' => 1, 'units' => 'pt'],
            array_intersect_key($scene, array_flip(['book_id', 'version', 'format', 'units'])));

        $page = $scene['pages'][0];
        $this->assertSame([5, 538.6, 751.2], [$page['n'], $page['w'], $page['h']]);
        $this->assertSame(['pdf' => 'plates/p5.pdf', 'screen' => 'plates/p5@2x.webp'], $page['plate']);
        $this->assertSame([$this->story->id, $this->pageNumber->id], array_column($page['blocks'], 'id'), 'reading order');

        $story = $page['blocks'][0];
        $this->assertSame('translate_reflow', $story['treatment']);
        $this->assertSame([30, 392, 352, 690], $story['allowed']);
        $this->assertSame(0.983, $story['visual_size']);
        $this->assertSame(17.5, $story['style']['size']);
        $this->assertSame('Open the little front door and step inside.', $story['text']);

        $label = $page['blocks'][1];
        $this->assertSame(['id', 'kind', 'role', 'treatment', 'bbox', 'order', 'text'], array_keys($label),
            'absent fields are left out, as in design §5');

        $this->book->refresh();
        $this->assertSame([1, $result->path], [$this->book->scene_version, $this->book->scene_path]);
    }

    public function test_the_version_goes_up_only_when_the_content_changes(): void
    {
        $compiler = app(SceneCompiler::class);
        $this->assertSame(1, $compiler->compile($this->book)->version);

        $again = $compiler->compile($this->book->refresh());
        $this->assertSame([1, false], [$again->version, $again->changed]);

        $this->story->update(['treatment' => 'translate_fixed']);
        $bumped = $compiler->compile($this->book->refresh());
        $this->assertSame([2, true], [$bumped->version, $bumped->changed]);
        $this->assertSame(2, $this->sceneFile()['version']);
        $this->assertSame('translate_fixed', $this->sceneFile()['pages'][0]['blocks'][0]['treatment']);
    }

    public function test_a_stale_or_missing_scene_is_detected(): void
    {
        $compiler = app(SceneCompiler::class);
        $this->assertTrue($compiler->isStale($this->book), 'never compiled');

        $compiler->compile($this->book);
        $this->assertFalse($compiler->isStale($this->book->refresh()));

        $this->story->update(['source_text' => 'Open the small front door.']);
        $this->assertTrue($compiler->isStale($this->book->refresh()), 'database changed after compiling');

        $compiler->compile($this->book);
        Storage::disk(config('assets.disk'))->delete($this->book->refresh()->scene_path);
        $this->assertTrue($compiler->isStale($this->book), 'file missing');
        $this->assertFalse($compiler->compile($this->book)->changed, 'rewriting a missing file is not a content change');
        $this->assertTrue(Storage::disk(config('assets.disk'))->exists($this->book->scene_path));
    }

    public function test_the_language_file_carries_text_layout_and_staleness_per_block(): void
    {
        $af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
        $bt = BlockTranslation::create(['translation_id' => $af->id, 'block_id' => $this->story->id,
            'text' => 'Maak die klein voordeur oop en stap binne.', 'alt_short_text' => 'Maak die voordeur oop.', 'status' => 'draft']);
        ResolvedLayout::create(['block_translation_id' => $bt->id, 'font_family' => 'Playpen Sans', 'font_size' => 17.2,
            'line_height' => 1.25, 'box' => [38, 400, 330, 652], 'ladder_step' => 5, 'lines' => ['Maak die klein voordeur', 'oop en stap binne.'], 'fits' => true]);

        $compiler = app(SceneCompiler::class);
        $result = $compiler->compileLanguage($af);

        $this->assertSame("scenes/{$this->book->id}/af.json", $result->path);
        $this->assertSame(1, $this->book->refresh()->scene_version, 'the book scene was compiled first');
        $file = json_decode(Storage::disk(config('assets.disk'))->get($result->path), true);
        $this->assertSame(['book_id' => $this->book->id, 'language' => 'af', 'version' => 1, 'scene_version' => 1],
            array_intersect_key($file, array_flip(['book_id', 'language', 'version', 'scene_version'])));
        $entry = $file['blocks'][$this->story->id];
        $this->assertSame('Maak die voordeur oop.', $entry['short']);
        $this->assertSame(['font' => 'Playpen Sans', 'size' => 17.2, 'line' => 1.25, 'box' => [38, 400, 330, 652], 'step' => 5,
            'lines' => ['Maak die klein voordeur', 'oop en stap binne.'], 'fits' => true], $entry['layout']);
        $this->assertArrayNotHasKey('stale', $entry);
        $this->assertArrayNotHasKey($this->pageNumber->id, $file['blocks'], 'a kept block has no translation entry');

        $this->assertFalse($compiler->isLanguageStale($af->refresh()));
        $this->story->update(['source_text' => 'Open the small front door.']);
        $this->assertTrue($compiler->isLanguageStale($af->refresh()));

        $next = $compiler->compileLanguage($af);
        $file = json_decode(Storage::disk(config('assets.disk'))->get($next->path), true);
        $this->assertSame([2, 2, true], [$next->version, $file['scene_version'], $file['blocks'][$this->story->id]['stale']]);
    }

    public function test_an_edition_without_translations_compiles_to_an_empty_block_map(): void
    {
        $zu = Translation::create(['book_id' => $this->book->id, 'language_code' => 'zu', 'language_name' => 'isiZulu', 'status' => 'draft']);

        $result = app(SceneCompiler::class)->compileLanguage($zu);

        $this->assertSame('{}', json_encode(json_decode(Storage::disk(config('assets.disk'))->get($result->path))->blocks));
    }
}
