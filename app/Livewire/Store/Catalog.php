<?php

namespace App\Livewire\Store;

use App\Models\Category;
use App\Models\ReadingLevel;
use App\Models\Translation;
use App\Services\Discovery\BookQuery;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer-facing store catalogue (categories/tags/filters brief, Req 7 + Homepage layout).
 *
 * This is the UI wiring for the already-built, tested discovery engine: it drives
 * App\Services\Discovery\BookQuery for filtering / faceting / search and renders the
 * V1 always-visible filters, active-filter chips, sorting and result grid.
 *
 * V1 filters (brief "Version 1 Public Filters"): Language, Age, Reading level, Book type,
 * Category, Narrated, Series, Free/paid. Only PUBLISHED books surface (BookQuery enforces).
 * No admin/operational metadata is exposed (brief: public vs admin separation).
 */
class Catalog extends Component
{
    use WithPagination;

    // Filters are URL-bound so results are shareable/bookmarkable (brief: discovery).
    #[Url(as: 'q')]
    public string $search = '';
    #[Url]
    public string $language = '';
    #[Url]
    public string $age = '';
    #[Url]
    public string $reading_level = '';
    #[Url]
    public string $book_type = '';
    #[Url]
    public string $category = '';
    #[Url]
    public string $narrated = '';
    #[Url]
    public string $series = '';
    #[Url]
    public string $price = ''; // 'free' | 'paid'
    #[Url]
    public string $sort = 'relevant';

    // ---- V2 "More Filters" (brief Version 2) — only data-backed dimensions ----
    #[Url]
    public string $education_phase = '';
    #[Url]
    public string $tag = '';       // theme/topic tag slug
    #[Url]
    public string $feature = '';   // any digital feature slug
    #[Url]
    public string $advisory = '';  // content advisory slug
    #[Url]
    public string $collection = ''; // collection slug (curated/rule-based)
    public bool $showMoreFilters = false;

    /** Age bands from the brief ("Recommended bands"). Value = representative age used by BookQuery. */
    public array $ageBands = [
        '0-3' => 2, '4-6' => 5, '7-9' => 8, '10-12' => 11, '13-15' => 14, '16+' => 16,
    ];

    public function updating($name): void
    {
        // Any filter change resets pagination to page 1.
        if ($name !== 'page') {
            $this->resetPage();
        }
    }

    /** Current active filters as an assoc array for BookQuery (empty values dropped). */
    private function activeFilters(): array
    {
        return array_filter([
            'language' => $this->language,
            'age' => $this->age !== '' ? ($this->ageBands[$this->age] ?? null) : null,
            'reading_level' => $this->reading_level,
            'book_type' => $this->book_type,
            'category' => $this->category,
            'narrated' => $this->narrated,
            'series' => $this->series,
            'price' => $this->price,
            'education_phase' => $this->education_phase,
            'tag' => $this->tag,
            'feature' => $this->feature,
            'advisory' => $this->advisory,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function toggleMoreFilters(): void
    {
        $this->showMoreFilters = !$this->showMoreFilters;
    }

    /** Remove a single active filter (chip "×"). */
    public function clearFilter(string $key): void
    {
        if (property_exists($this, $key)) {
            $this->{$key} = '';
            $this->resetPage();
        }
    }

    public function clearAll(): void
    {
        $this->reset([
            'search', 'language', 'age', 'reading_level', 'book_type',
            'category', 'narrated', 'series', 'price',
            'education_phase', 'tag', 'feature', 'advisory', 'collection',
        ]);
        $this->resetPage();
    }

    public function render()
    {
        $query = new BookQuery($this->activeFilters());

        // Search composes ON TOP of the active filters (BookQuery::search calls base()).
        $builder = trim($this->search) !== ''
            ? $query->search($this->search)
            : $query->base();

        $builder->with(['translations', 'narrations']);

        // Collection scope (brief Req 9): when a collection is selected, restrict the
        // result set to the books it resolves to (manual pins or rule-based via BookQuery),
        // then STILL apply the active filters on top so filters compose with collections.
        if ($this->collection !== '') {
            $col = \App\Models\Collection::where('slug', $this->collection)->first();
            if ($col) {
                $ids = app(\App\Services\Discovery\CollectionResolver::class)
                    ->books($col)->pluck('id')->all();
                $builder->whereIn('books.id', $ids ?: [0]);
            }
        }

        // Sorting (brief "Sorting"). Only non-sensitive, public orderings.
        match ($this->sort) {
            'newest' => $builder->latest(),
            'title_az' => $builder->orderBy('title'),
            'age_young' => $builder->orderBy('age_min'),
            default => $builder->latest(), // 'relevant' — recency as a safe default proxy
        };

        $books = $builder->paginate(12);

        // Facet counts over the CURRENT filter set (zero-count options hidden by engine).
        $facets = [
            'book_type' => $query->facetCounts('book_type'),
            'category' => $query->facetCounts('category'),
            'series' => $query->facetCounts('series'),
        ];

        // Filter option sources (book-agnostic — derived from data, not hardcoded).
        $languages = Translation::where('publication_status', 'published')
            ->select('language_code', 'language_name')->distinct()
            ->orderBy('language_name')->get()
            ->pluck('language_name', 'language_code')->toArray();

        $readingLevels = ReadingLevel::orderBy('rank')->get()
            ->mapWithKeys(fn ($r) => [$r->slug => $r->label()])->toArray();
        $categories = Category::active()->orderBy('sort_order')->get();

        // V2 "More Filters" option sources — all data-backed (no invented dimensions).
        $educationPhases = Translation::where('publication_status', 'published')
            ->whereNotNull('education_phase')->distinct()
            ->orderBy('education_phase')->pluck('education_phase')->all();
        $themeTags = \App\Models\Tag::where('status', 'active')
            ->whereIn('group', ['themes_and_values', 'topics'])
            ->orderBy('canonical_name')->get();
        $features = \App\Models\Feature::orderBy('slug')->get();
        $advisories = \App\Models\ContentAdvisory::orderBy('slug')->get();
        $collections = \App\Models\Collection::orderBy('sort_order')->get();

        return view('livewire.store.catalog', [
            'books' => $books,
            'facets' => $facets,
            'languages' => $languages,
            'readingLevels' => $readingLevels,
            'categories' => $categories,
            'educationPhases' => $educationPhases,
            'themeTags' => $themeTags,
            'features' => $features,
            'advisories' => $advisories,
            'collections' => $collections,
            'activeChips' => $this->activeChips(),
        ])->layout('components.store-layout', ['title' => 'Browse Books']);
    }

    /** Human-readable active-filter chips for display + removal. */
    private function activeChips(): array
    {
        $chips = [];
        if ($this->language !== '') $chips['language'] = $this->language;
        if ($this->age !== '') $chips['age'] = 'Age ' . $this->age;
        if ($this->reading_level !== '') $chips['reading_level'] = ucfirst($this->reading_level);
        if ($this->book_type !== '') $chips['book_type'] = ucwords(str_replace('_', ' ', $this->book_type));
        if ($this->category !== '') $chips['category'] = ucwords(str_replace('-', ' ', $this->category));
        if ($this->narrated !== '') $chips['narrated'] = 'Narrated';
        if ($this->series !== '') $chips['series'] = $this->series;
        if ($this->price !== '') $chips['price'] = ucfirst($this->price);
        if ($this->education_phase !== '') $chips['education_phase'] = ucwords(str_replace('_', ' ', $this->education_phase));
        if ($this->tag !== '') $chips['tag'] = ucwords(str_replace('-', ' ', $this->tag));
        if ($this->feature !== '') $chips['feature'] = ucwords(str_replace('_', ' ', $this->feature));
        if ($this->advisory !== '') $chips['advisory'] = ucwords(str_replace('_', ' ', $this->advisory));
        return $chips;
    }
}
