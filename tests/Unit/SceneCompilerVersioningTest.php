<?php

namespace Tests\Unit;

use App\Models\Block;
use App\Models\BlockTranslation;
use App\Models\Book;
use App\Models\ResolvedLayout;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\SceneCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 9.3: compile, version bump and stale-hash detection, case by case. The rule under
 * test: a version goes up exactly when what the file would contain changes — never for an
 * unrelated edit, never twice for the same content.
 */
class SceneCompilerVersioningTest extends TestCase
{
    use RefreshDatabase;

    private SceneCompiler $compiler;

    private Book $book;

    private SourcePage $page;

    private Block $story;

    private Translation $af;

    private BlockTranslation $afStory;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
        $this->compiler = app(SceneCompiler::class);
        $this->book = Book::create(['title' => 'Kolulu', 'page_count' => 16, 'pdf_path' => 'books/pdfs/k.pdf', 'original_language' => 'en']);
        $this->page = SourcePage::create(['book_id' => $this->book->id, 'number' => 3, 'width_pt' => 509.72, 'height_pt' => 722.32]);
        $this->story = $this->block(['reading_order' => 1, 'style' => ['size' => 12.0, 'align' => 'left']]);
        $this->af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
        $this->afStory = BlockTranslation::create(['translation_id' => $this->af->id, 'block_id' => $this->story->id,
            'text' => "Kolulu woon in 'n wêreld van verbeelding.", 'status' => 'draft']);
    }

    private function block(array $attrs = []): Block
    {
        return Block::create($attrs + ['book_id' => $this->book->id, 'page_id' => $this->page->id, 'kind' => 'paragraph',
            'role' => 'body', 'treatment' => 'translate_reflow', 'bbox' => [40, 400, 330, 640],
            'source_text' => 'Kolulu Taktaki lives in a world of imagination.']);
    }

    private function sceneVersion(): int
    {
        return $this->compiler->compile($this->book->refresh())->version;
    }

    private function languageVersion(?Translation $edition = null): int
    {
        return $this->compiler->compileLanguage(($edition ?? $this->af)->refresh())->version;
    }

    /** Edits that change what scene.json would contain. */
    public static function sceneChanges(): array
    {
        return [
            'page size' => [fn (self $t) => $t->page->update(['width_pt' => 510])],
            'trim box' => [fn (self $t) => $t->page->update(['trim_box' => [35, 35, 475, 687]])],
            'plate added' => [fn (self $t) => $t->page->update(['plate_pdf_path' => 'plates/p3.pdf'])],
            'scanned page' => [fn (self $t) => $t->page->update(['has_live_text' => false])],
            'new page' => [fn (self $t) => SourcePage::create(['book_id' => $t->book->id, 'number' => 4, 'width_pt' => 509.72, 'height_pt' => 722.32])],
            'block text' => [fn (self $t) => $t->story->update(['source_text' => 'Kolulu lives in a dream world.'])],
            'block box' => [fn (self $t) => $t->story->update(['bbox' => [40, 410, 330, 650]])],
            'allowed area' => [fn (self $t) => $t->story->update(['allowed_area' => [30, 392, 352, 690]])],
            'style' => [fn (self $t) => $t->story->update(['style' => ['size' => 12.5, 'align' => 'left']])],
            'treatment' => [fn (self $t) => $t->story->update(['treatment' => 'translate_fixed'])],
            'treatment confirmed' => [fn (self $t) => $t->story->update(['treatment_confirmed' => true])],
            'confidence' => [fn (self $t) => $t->story->update(['treatment_confidence' => 0.91])],
            'visual size' => [fn (self $t) => $t->story->update(['visual_size_factor' => 0.983])],
            'structure' => [fn (self $t) => $t->story->update(['structure' => ['row' => 1, 'col' => 0]])],
            'role' => [fn (self $t) => $t->story->update(['role' => 'educational'])],
            'reading order' => [fn (self $t) => $t->story->update(['reading_order' => 7])],
            'new block' => [fn (self $t) => $t->block(['kind' => 'label', 'role' => 'label', 'treatment' => 'keep', 'source_text' => '1'])],
            'deleted block' => [fn (self $t) => $t->story->delete()],
        ];
    }

    #[DataProvider('sceneChanges')]
    public function test_a_scene_change_bumps_the_version_once(\Closure $edit): void
    {
        $this->assertSame(1, $this->sceneVersion());

        $edit($this);
        $this->assertTrue($this->compiler->isStale($this->book->refresh()), 'the edit is detected before compiling');
        $this->assertSame(2, $this->sceneVersion());
        $this->assertSame(2, $this->sceneVersion(), 'the same content never bumps twice');
        $this->assertFalse($this->compiler->isStale($this->book->refresh()));
    }

    public function test_edits_outside_the_scene_keep_the_version(): void
    {
        $this->assertSame(1, $this->sceneVersion());

        $this->book->update(['title' => 'Kolulu se Huis', 'status' => 'published']);   // store data, not the scene
        $this->afStory->update(['text' => 'Kolulu woon in sy eie wêreld.']);             // language layer, not the scene
        $this->story->touch();                                                            // timestamps only

        $this->assertFalse($this->compiler->isStale($this->book->refresh()));
        $this->assertSame(1, $this->sceneVersion());
    }

    public function test_compiling_is_deterministic_whatever_the_creation_order(): void
    {
        // Two blocks with the same reading order, created in either order, compile identically.
        $a = $this->block(['reading_order' => 5, 'source_text' => 'A']);
        $b = $this->block(['reading_order' => 5, 'source_text' => 'B']);
        $this->compiler->compile($this->book);
        $first = Storage::disk(config('assets.disk'))->get("scenes/{$this->book->id}/scene.json");
        $ids = array_column(json_decode($first, true)['pages'][0]['blocks'], 'id');
        $this->assertSame([$this->story->id, ...collect([$a->id, $b->id])->sort()->values()->all()], $ids, 'ties broken by id');

        Storage::disk(config('assets.disk'))->delete("scenes/{$this->book->id}/scene.json");
        $this->compiler->compile($this->book->refresh());
        $this->assertSame($first, Storage::disk(config('assets.disk'))->get("scenes/{$this->book->id}/scene.json"), 'byte-identical');
    }

    /** Edits that change what {lang}.json would contain. */
    public static function languageChanges(): array
    {
        return [
            'text' => [fn (self $t) => $t->afStory->update(['text' => 'Kolulu woon in sy eie wêreld.'])],
            'short alternative' => [fn (self $t) => $t->afStory->update(['alt_short_text' => 'Kolulu se wêreld.'])],
            'approved' => [fn (self $t) => $t->afStory->update(['status' => 'approved'])],
            'layout added' => [fn (self $t) => ResolvedLayout::create(['block_translation_id' => $t->afStory->id, 'font_family' => 'Playpen Sans',
                'font_size' => 11.8, 'line_height' => 1.25, 'box' => [40, 400, 330, 640], 'ladder_step' => 1, 'fits' => true])],
            'new block translation' => [fn (self $t) => BlockTranslation::create(['translation_id' => $t->af->id,
                'block_id' => $t->block(['source_text' => 'He has fun.'])->id, 'text' => 'Hy het pret.', 'status' => 'draft'])],
            'source changed (stale)' => [fn (self $t) => $t->story->update(['source_text' => 'Kolulu lives in a dream world.'])],
            'scene changed only' => [fn (self $t) => $t->page->update(['plate_pdf_path' => 'plates/p3.pdf'])],
        ];
    }

    #[DataProvider('languageChanges')]
    public function test_a_language_change_bumps_its_version_once(\Closure $edit): void
    {
        $this->assertSame(1, $this->languageVersion());

        $edit($this);
        $this->assertTrue($this->compiler->isLanguageStale($this->af->refresh()));
        $this->assertSame(2, $this->languageVersion());
        $this->assertSame(2, $this->languageVersion());
        $this->assertFalse($this->compiler->isLanguageStale($this->af->refresh()));
    }

    public function test_a_layout_change_bumps_the_language_version(): void
    {
        $layout = ResolvedLayout::create(['block_translation_id' => $this->afStory->id, 'font_family' => 'Playpen Sans',
            'font_size' => 11.8, 'line_height' => 1.25, 'box' => [40, 400, 330, 640], 'ladder_step' => 1, 'fits' => true]);
        $this->assertSame(1, $this->languageVersion());

        $layout->update(['font_size' => 11.2, 'ladder_step' => 4]);
        $this->assertSame(2, $this->languageVersion());
    }

    public function test_editions_are_independent(): void
    {
        $zu = Translation::create(['book_id' => $this->book->id, 'language_code' => 'zu', 'language_name' => 'isiZulu', 'status' => 'draft']);
        $this->assertSame(1, $this->languageVersion());
        $this->assertSame(1, $this->languageVersion($zu));

        BlockTranslation::create(['translation_id' => $zu->id, 'block_id' => $this->story->id,
            'text' => 'UKolulu uhlala ezweni lomcabango.', 'status' => 'draft']);

        $this->assertFalse($this->compiler->isLanguageStale($this->af->refresh()), 'a Zulu edit leaves Afrikaans alone');
        $this->assertSame(1, $this->languageVersion());
        $this->assertSame(2, $this->languageVersion($zu));
        $this->assertSame("scenes/{$this->book->id}/zu.json", $this->compiler->compileLanguage($zu)->path);
    }

    public function test_text_survives_exactly(): void
    {
        $this->afStory->update(['text' => "Hoë frekwensie — 'n wêreld vol ê, ë, ô en ŉ."]);
        $result = $this->compiler->compileLanguage($this->af->refresh());

        $raw = Storage::disk(config('assets.disk'))->get($result->path);
        $this->assertStringContainsString("Hoë frekwensie — 'n wêreld vol ê, ë, ô en ŉ.", $raw, 'stored as readable UTF-8, not \u escapes');
        $this->assertSame("Hoë frekwensie — 'n wêreld vol ê, ë, ô en ŉ.", json_decode($raw, true)['blocks'][$this->story->id]['text']);
    }

    public function test_versions_never_go_down(): void
    {
        $seen = [];
        foreach ([[40, 400, 330, 640], [41, 400, 330, 640], [40, 400, 330, 640]] as $box) {
            $this->story->update(['bbox' => $box]);
            $seen[] = $this->sceneVersion();
        }

        $this->assertSame([1, 2, 3], $seen, 'returning to earlier content is a new version, not the old one');
    }
}
