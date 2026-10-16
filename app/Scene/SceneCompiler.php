<?php

namespace App\Scene;

use App\Assets\AssetStorage;
use App\Models\Block;
use App\Models\BlockTranslation;
use App\Models\Book;
use App\Models\SourcePage;
use App\Models\Translation;

/**
 * Compiles the scene files from the database (task 9.2, design §4–5):
 *   scenes/{book}/scene.json   pages, plates and blocks (the book, language-free)
 *   scenes/{book}/{lang}.json  per block id: translated text, short alternative, status, layout
 *
 * The database stays the source of truth; the files are what workers and the reader manifest
 * read. Each file carries a version that goes up only when its compiled content changes,
 * detected by a SHA-256 of the content (books.scene_hash / translations.scene_hash), so
 * compiling again without changes is free and keeps the version.
 */
class SceneCompiler
{
    public const FORMAT = 1;

    public function compile(Book $book): SceneResult
    {
        $payload = $this->scenePayload($book);
        $hash = self::hash($payload);
        $path = "scenes/{$book->id}/scene.json";
        $changed = $hash !== $book->scene_hash;
        $version = $changed ? $book->scene_version + 1 : $book->scene_version;

        if ($changed || ! AssetStorage::disk()->exists($path)) {
            AssetStorage::disk()->put($path, self::encode(['book_id' => $book->id, 'version' => $version] + $payload));
        }
        if ($changed || $book->scene_path !== $path) {
            $book->forceFill(['scene_path' => $path, 'scene_version' => $version, 'scene_hash' => $hash])->save();
        }

        return new SceneResult($path, $version, $changed);
    }

    /** Compiles the edition's language layer, compiling the book scene first if it is out of date. */
    public function compileLanguage(Translation $edition): SceneResult
    {
        $book = $edition->book;
        if ($this->isStale($book)) {
            $this->compile($book);
            $book->refresh();
        }

        $payload = $this->languagePayload($edition, $book);
        $hash = self::hash($payload);
        $path = "scenes/{$book->id}/{$edition->language_code}.json";
        $changed = $hash !== $edition->scene_hash;
        $version = $changed ? $edition->scene_version + 1 : $edition->scene_version;

        if ($changed || ! AssetStorage::disk()->exists($path)) {
            AssetStorage::disk()->put($path, self::encode(['book_id' => $book->id, 'language' => $edition->language_code,
                'version' => $version] + $payload));
        }
        if ($changed) {
            $edition->forceFill(['scene_version' => $version, 'scene_hash' => $hash])->save();
        }

        return new SceneResult($path, $version, $changed);
    }

    /** The database has changed since the scene file was compiled (or it was never compiled). */
    public function isStale(Book $book): bool
    {
        return $book->scene_hash === null
            || self::hash($this->scenePayload($book)) !== $book->scene_hash
            || ! AssetStorage::disk()->exists((string) $book->scene_path);
    }

    public function isLanguageStale(Translation $edition): bool
    {
        return $this->isStale($edition->book)
            || $edition->scene_hash === null
            || self::hash($this->languagePayload($edition, $edition->book)) !== $edition->scene_hash;
    }

    /** The book scene without its version (design §5). */
    public function scenePayload(Book $book): array
    {
        $pages = $book->sourcePages()->with(['blocks' => fn ($q) => $q->orderBy('reading_order')->orderBy('id')])->get();

        return [
            'format' => self::FORMAT,
            'units' => 'pt',
            'pages' => $pages->map(fn (SourcePage $p) => $this->pagePayload($p))->values()->all(),
        ];
    }

    /** One page of the scene: its size, plates and blocks in reading order (also a stage input, task 9.4). */
    public function pagePayload(SourcePage $p): array
    {
        if (! $p->relationLoaded('blocks')) {
            $p->load(['blocks' => fn ($q) => $q->orderBy('reading_order')->orderBy('id')]);
        }

        return self::compact([
                'n' => (int) $p->number,
                'w' => (float) $p->width_pt,
                'h' => (float) $p->height_pt,
                'trim' => $p->trim_box,
                'live_text' => (bool) $p->has_live_text,
                'plate' => self::compact(['pdf' => $p->plate_pdf_path, 'screen' => $p->plate_screen_path]) ?: null,
                'blocks' => $p->blocks->map(fn (Block $b) => self::compact([
                    'id' => $b->id,
                    'parent' => $b->parent_id,
                    'kind' => $b->kind,
                    'role' => $b->role,
                    'treatment' => $b->treatment,
                    'confidence' => $b->treatment_confidence,
                    'confirmed' => $b->treatment_confirmed ?: null,
                    'bbox' => $b->bbox,
                    'allowed' => $b->allowed_area,
                    'order' => (int) $b->reading_order,
                    'style' => $b->style,
                    'visual_size' => $b->visual_size_factor,
                    'structure' => $b->structure,
                    'text' => $b->source_text,
                ]))->values()->all(),
            ]);
    }

    /** The edition's language layer without its version (design §5): keyed by block id. */
    public function languagePayload(Translation $edition, ?Book $book = null): array
    {
        $book ??= $edition->book;
        $rows = $edition->blockTranslations()->with(['block', 'layout'])->orderBy('block_id')->get();

        $blocks = [];
        foreach ($rows as $bt) {
            /** @var BlockTranslation $bt */
            $layout = $bt->layout;
            $blocks[$bt->block_id] = self::compact([
                'text' => $bt->text,
                'short' => $bt->alt_short_text,
                'status' => $bt->status,
                'stale' => $bt->isStale() ?: null,
                'layout' => $layout ? self::compact([
                    'font' => $layout->font_family,
                    'size' => $layout->font_size,
                    'line' => $layout->line_height,
                    'spacing' => $layout->letter_spacing ?: null,
                    'box' => $layout->box,
                    'step' => $layout->ladder_step,
                    'lines' => $layout->lines,
                    'fits' => $layout->fits,
                ]) : null,
            ]);
        }

        return ['format' => self::FORMAT, 'scene_version' => (int) $book->scene_version, 'blocks' => (object) $blocks];
    }

    private static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }

    /** Drop null entries so the files stay small and readable (design §5 omits absent fields). */
    private static function compact(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== null);
    }
}
