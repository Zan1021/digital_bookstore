<div>
    {{-- Curated & rule-based collections (brief Req 9) --}}
    @if($collections->count())
        <div class="flex flex-wrap items-center gap-2 mb-6">
            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide mr-1">Collections</span>
            @foreach($collections as $col)
                <button wire:click="$set('collection', '{{ $collection === $col->slug ? '' : $col->slug }}')"
                        class="text-xs rounded-full px-3 py-1 border transition
                        {{ $collection === $col->slug ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300' }}">
                    {{ $col->label() }}
                </button>
            @endforeach
        </div>
    @endif

    {{-- Search row --}}
    <div class="mb-6">
        <div class="relative max-w-2xl">
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Search by title, series, author, or topic…"
                   class="w-full border border-gray-200 rounded-full pl-12 pr-4 py-3 text-sm focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none">
            <svg class="w-5 h-5 text-gray-400 absolute left-4 top-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>
    </div>

    {{-- Active filter chips --}}
    @if(count($activeChips))
        <div class="flex flex-wrap items-center gap-2 mb-6">
            @foreach($activeChips as $key => $label)
                <button wire:click="clearFilter('{{ $key }}')"
                        class="inline-flex items-center gap-1 bg-brand-50 text-brand-700 border border-brand-200 rounded-full px-3 py-1 text-xs font-medium hover:bg-brand-100 transition">
                    {{ $label }} <span class="text-brand-400">&times;</span>
                </button>
            @endforeach
            <button wire:click="clearAll" class="text-xs text-gray-500 hover:text-gray-700 underline ml-1">Clear all filters</button>
        </div>
    @endif

    <div class="flex flex-col lg:flex-row gap-8">
        {{-- Filter sidebar (V1 always-visible filters) --}}
        <aside class="lg:w-64 flex-shrink-0 space-y-6">
            {{-- Language --}}
            @if(count($languages))
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Language</h3>
                <select wire:model.live="language" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                    <option value="">All languages</option>
                    @foreach($languages as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Age --}}
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Age</h3>
                <select wire:model.live="age" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                    <option value="">All ages</option>
                    @foreach(array_keys($ageBands) as $band)
                        <option value="{{ $band }}">{{ $band }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Reading level --}}
            @if(count($readingLevels))
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Reading level</h3>
                <select wire:model.live="reading_level" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                    <option value="">Any level</option>
                    @foreach($readingLevels as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Book type (faceted) --}}
            @if(count($facets['book_type']))
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Book type</h3>
                <div class="space-y-1">
                    @foreach($facets['book_type'] as $type => $count)
                        <label class="flex items-center justify-between text-sm cursor-pointer hover:text-brand-600">
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:model.live="book_type" value="{{ $type }}" class="text-brand-500">
                                {{ ucwords(str_replace('_', ' ', $type)) }}
                            </span>
                            <span class="text-gray-400 text-xs">{{ $count }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Category (faceted) --}}
            @if(count($facets['category']))
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Category</h3>
                <div class="space-y-1">
                    @foreach($categories as $cat)
                        @if(isset($facets['category'][$cat->slug]))
                        <label class="flex items-center justify-between text-sm cursor-pointer hover:text-brand-600">
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:model.live="category" value="{{ $cat->slug }}" class="text-brand-500">
                                {{ $cat->label() }}
                            </span>
                            <span class="text-gray-400 text-xs">{{ $facets['category'][$cat->slug] }}</span>
                        </label>
                        @endif
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Series (faceted) --}}
            @if(count($facets['series']))
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Series</h3>
                <select wire:model.live="series" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                    <option value="">All series</option>
                    @foreach($facets['series'] as $s => $count)
                        <option value="{{ $s }}">{{ $s }} ({{ $count }})</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Narrated + Price --}}
            <div class="space-y-2">
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Features & price</h3>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" wire:model.live="narrated" value="1" class="rounded text-brand-500">
                    Narrated / read-along
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="radio" wire:model.live="price" value="free" class="text-brand-500"> Free
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="radio" wire:model.live="price" value="paid" class="text-brand-500"> Paid
                </label>
            </div>

            {{-- More Filters (brief V2) — toggle, data-backed dimensions only --}}
            <div class="border-t border-gray-100 pt-4">
                <button wire:click="toggleMoreFilters"
                        class="flex items-center justify-between w-full text-xs font-semibold text-gray-500 uppercase tracking-wide">
                    More filters
                    <span>{{ $showMoreFilters ? '−' : '+' }}</span>
                </button>

                @if($showMoreFilters)
                    <div class="mt-4 space-y-5">
                        {{-- Education phase --}}
                        @if(count($educationPhases))
                        <div>
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">School phase</h3>
                            <select wire:model.live="education_phase" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                                <option value="">Any phase</option>
                                @foreach($educationPhases as $phase)
                                    <option value="{{ $phase }}">{{ ucwords(str_replace('_', ' ', $phase)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        {{-- Theme / topic tags --}}
                        @if($themeTags->count())
                        <div>
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Theme / topic</h3>
                            <select wire:model.live="tag" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                                <option value="">Any theme</option>
                                @foreach($themeTags as $t)
                                    <option value="{{ $t->slug }}">{{ $t->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        {{-- Digital features --}}
                        @if($features->count())
                        <div>
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Feature</h3>
                            <select wire:model.live="feature" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                                <option value="">Any feature</option>
                                @foreach($features as $f)
                                    <option value="{{ $f->slug }}">{{ ucwords(str_replace('_', ' ', $f->slug)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        {{-- Content guidance --}}
                        @if($advisories->count())
                        <div>
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Content guidance</h3>
                            <select wire:model.live="advisory" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                                <option value="">Any</option>
                                @foreach($advisories as $a)
                                    <option value="{{ $a->slug }}">{{ ucwords(str_replace('_', ' ', $a->slug)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                    </div>
                @endif
            </div>
        </aside>

        {{-- Results --}}
        <div class="flex-1">
            <div class="flex items-center justify-between mb-4">
                <p class="text-sm text-gray-500">{{ $books->total() }} book{{ $books->total() === 1 ? '' : 's' }}</p>
                <select wire:model.live="sort" class="text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                    <option value="relevant">Most relevant</option>
                    <option value="newest">Newest</option>
                    <option value="title_az">Title A–Z</option>
                    <option value="age_young">Age: youngest first</option>
                </select>
            </div>

            @if($books->count())
                <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                    @foreach($books as $book)
                        <a href="{{ route('store.book', $book) }}" class="group block">
                            <div class="aspect-[3/4] bg-gray-100 rounded-xl overflow-hidden mb-3 border border-gray-100 group-hover:shadow-lg transition">
                                <canvas class="pdf-cover w-full h-full object-cover"
                                        data-pdf="{{ asset('storage/' . $book->pdf_path) }}"></canvas>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800 leading-snug group-hover:text-brand-600">{{ $book->title }}</h3>
                            <p class="text-xs text-gray-400 mt-0.5">
                                @if($book->series){{ $book->series }} &middot; @endif{{ $book->translations->count() }} language{{ $book->translations->count() === 1 ? '' : 's' }}
                            </p>
                        </a>
                    @endforeach
                </div>

                <div class="mt-8">{{ $books->links() }}</div>
            @else
                <div class="text-center py-20 text-gray-400">
                    <p class="text-lg">No books match these filters.</p>
                    <button wire:click="clearAll" class="mt-3 text-brand-500 hover:text-brand-700 text-sm underline">Clear all filters</button>
                </div>
            @endif
        </div>
    </div>
</div>
