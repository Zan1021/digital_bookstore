<?php

namespace App\Services\Discovery;

use App\Models\Book;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;

/**
 * Store discovery query builder (Req 7). Composes V1 facet filters over published books
 * and provides faceted counts + search across titles, categories, tags (labels + aliases).
 *
 * V1 filters: language, age, reading_level, category, book_type, series, narrated, price
 * (free/paid). Only PUBLISHED books with at least one visible edition are returned.
 *
 * Public-only: never exposes admin/operational metadata. Rights visibility is applied by
 * the caller/controller via EditionRight; this builder handles catalogue filtering.
 */
class BookQuery
{
    private array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function base(): Builder
    {
        $q = Book::query()->where('status', 'published');

        if (!empty($this->filters['book_type'])) {
            $q->where('book_type', $this->filters['book_type']);
        }
        if (!empty($this->filters['series'])) {
            $q->where('series', $this->filters['series']);
        }
        if (isset($this->filters['age'])) {
            $age = (int) $this->filters['age'];
            $q->where('age_min', '<=', $age)->where('age_max', '>=', $age);
        }
        if (!empty($this->filters['category'])) {
            $slug = $this->filters['category'];
            $q->whereHas('categories', fn ($c) => $c->where('slug', $slug));
        }
        if (!empty($this->filters['tag'])) {
            $tagId = $this->resolveTagId($this->filters['tag']);
            if ($tagId) {
                $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId));
            }
        }
        // Edition-level facets: language, reading level, narrated, price.
        $editionFilters = array_filter([
            'language' => $this->filters['language'] ?? null,
            'reading_level' => $this->filters['reading_level'] ?? null,
            'narrated' => $this->filters['narrated'] ?? null,
            'price' => $this->filters['price'] ?? null, // 'free' | 'paid'
        ], fn ($v) => $v !== null && $v !== '');

        if (!empty($editionFilters)) {
            $q->whereHas('translations', function ($t) use ($editionFilters) {
                $t->where('publication_status', 'published');
                if (!empty($editionFilters['language'])) {
                    $t->where('language_code', $editionFilters['language']);
                }
                if (!empty($editionFilters['reading_level'])) {
                    $t->whereHas('readingLevel', fn ($r) => $r->where('slug', $editionFilters['reading_level']));
                }
                if (!empty($editionFilters['narrated'])) {
                    $t->whereHas('features', fn ($f) => $f->whereIn('slug', ['narrated', 'read_along']));
                }
                if (($editionFilters['price'] ?? null) === 'free') {
                    $t->where(fn ($w) => $w->whereNull('price')->orWhere('price', 0));
                } elseif (($editionFilters['price'] ?? null) === 'paid') {
                    $t->where('price', '>', 0);
                }
            });
        }

        return $q;
    }

    /**
     * Full-text-ish search across title, series, author, and category/tag labels+aliases,
     * resolving translated labels via canonical ids (Req 7.4/7.5).
     */
    public function search(string $term): Builder
    {
        $term = trim($term);
        $q = $this->base();
        if ($term === '') {
            return $q;
        }
        $like = '%' . $term . '%';

        // Resolve any category/tag whose label OR alias matches -> their ids.
        $tagIds = Tag::where('canonical_name', 'like', $like)
            ->orWhereHas('translationsRel', fn ($t) => $t->where('label', 'like', $like))
            ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like))
            ->pluck('id');
        $catIds = Category::where('slug', 'like', $like)
            ->orWhereRaw("json_extract(translations, '$.en') like ?", [$like])
            ->pluck('id');

        return $q->where(function ($w) use ($like, $tagIds, $catIds) {
            $w->where('title', 'like', $like)
              ->orWhere('series', 'like', $like)
              ->orWhere('author', 'like', $like)
              ->orWhere('illustrator', 'like', $like)
              ->orWhere('sku', 'like', $like);
            if ($tagIds->isNotEmpty()) {
                $w->orWhereHas('tags', fn ($t) => $t->whereIn('tags.id', $tagIds));
            }
            if ($catIds->isNotEmpty()) {
                $w->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $catIds));
            }
        });
    }

    /**
     * Faceted counts for a filter dimension over the CURRENT filter set. Zero-count
     * options are omitted (Req 7.2). Returns [value => count].
     */
    public function facetCounts(string $dimension): array
    {
        $base = $this->base();
        return match ($dimension) {
            'book_type' => $base->clone()->whereNotNull('book_type')
                ->selectRaw('book_type as v, count(*) as c')->groupBy('book_type')
                ->pluck('c', 'v')->toArray(),
            'series' => $base->clone()->whereNotNull('series')
                ->selectRaw('series as v, count(*) as c')->groupBy('series')
                ->pluck('c', 'v')->toArray(),
            'category' => Category::active()->get()
                ->mapWithKeys(fn ($c) => [$c->slug => (clone $base)->whereHas('categories',
                    fn ($q) => $q->where('slug', $c->slug))->count()])
                ->filter(fn ($n) => $n > 0)->toArray(),
            default => [],
        };
    }

    private function resolveTagId(string $slugOrId): ?int
    {
        if (is_numeric($slugOrId)) {
            return (int) $slugOrId;
        }
        return Tag::where('slug', $slugOrId)->value('id');
    }
}
