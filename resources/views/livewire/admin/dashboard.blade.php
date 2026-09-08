<div>
    {{-- Flash messages --}}
    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center justify-between mb-8">
        <h1 class="text-3xl font-bold text-gray-800">My Books</h1>
        <a href="{{ route('admin.upload') }}" class="bg-brand-500 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-brand-600 transition flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Upload Books
        </a>
    </div>

    {{-- Stats row --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center shadow-sm">
            <div class="w-10 h-10 bg-brand-50 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                </svg>
            </div>
            <div class="text-2xl font-bold text-brand-500">{{ $totalBooks }}</div>
            <div class="text-xs text-gray-500 mt-1">Total Books</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center shadow-sm">
            <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <div class="text-2xl font-bold text-green-600">{{ $readyBooks }}</div>
            <div class="text-xs text-gray-500 mt-1">Ready</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center shadow-sm">
            <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064" />
                </svg>
            </div>
            <div class="text-2xl font-bold text-blue-600">{{ $translatedBooks }}</div>
            <div class="text-xs text-gray-500 mt-1">Translated</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center shadow-sm">
            <div class="w-10 h-10 bg-purple-50 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                </svg>
            </div>
            <div class="text-2xl font-bold text-purple-600">{{ $narratedBooks }}</div>
            <div class="text-xs text-gray-500 mt-1">Narrated</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center shadow-sm">
            <div class="w-10 h-10 bg-amber-50 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div class="text-2xl font-bold text-amber-600">R{{ number_format($totalCostZar, 2) }}</div>
            <div class="text-xs text-gray-500 mt-1">Total AI Cost</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center shadow-sm">
            <div class="w-10 h-10 bg-yellow-50 rounded-lg flex items-center justify-center mx-auto mb-2">
                <svg class="w-5 h-5 text-yellow-600 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </div>
            <div class="text-2xl font-bold text-yellow-600">{{ $processingBooks }}</div>
            <div class="text-xs text-gray-500 mt-1">Processing</div>
        </div>
    </div>

    {{-- Search & Filters --}}
    <div class="mb-6 flex flex-col md:flex-row gap-4">
        {{-- Search --}}
        <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="Search books by title, SKU, or author..."
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-brand-500 focus:border-brand-500 bg-white shadow-sm">
        </div>
        {{-- Filter pills --}}
        <div class="flex items-center gap-2 flex-wrap">
            @foreach(['all' => 'All', 'ready' => 'Ready', 'processing' => 'Processing', 'translated' => 'Translated', 'narrated' => 'Narrated'] as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        class="px-3 py-1.5 rounded-full text-xs font-medium transition
                            {{ $filter === $key ? 'bg-brand-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Book Grid --}}
    @if($recentBooks->isEmpty())
        <div class="bg-white rounded-xl border border-gray-100 py-16 text-center">
            <svg class="w-20 h-20 mx-auto mb-4 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
            @if(!empty($search))
                <p class="text-lg text-gray-400">No books match "{{ $search }}"</p>
                <button wire:click="$set('search', '')" class="mt-2 text-sm text-brand-500 hover:underline">Clear search</button>
            @else
                <p class="text-lg text-gray-400">No books yet</p>
                <p class="text-sm text-gray-300 mt-1">Upload your first PDF to get started</p>
            @endif
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
            @foreach($recentBooks as $book)
                <div wire:key="book-{{ $book->id }}" class="bg-white rounded-xl border border-gray-100 overflow-hidden shadow-sm hover:shadow-lg transition group">
                    {{-- Cover image --}}
                    <a href="{{ route('reader', $book) }}" class="block relative bg-gray-100 overflow-hidden" style="aspect-ratio: 467/680;">
                        <canvas class="pdf-cover w-full h-full group-hover:scale-105 transition duration-300"
                                data-pdf="{{ asset('storage/' . $book->pdf_path) }}"></canvas>

                        {{-- Status badge --}}
                        <span class="absolute top-2 right-2 px-2 py-0.5 rounded-full text-[10px] font-medium
                            @if($book->status === 'ready') bg-green-500 text-white
                            @elseif($book->status === 'processing') bg-yellow-500 text-white
                            @else bg-gray-500 text-white @endif">
                            {{ ucfirst($book->status) }}
                        </span>

                        {{-- Language/narration badges --}}
                        <div class="absolute bottom-2 left-2 flex gap-1">
                            @if($book->translations->isNotEmpty())
                                <span class="bg-blue-500 text-white text-[9px] px-1.5 py-0.5 rounded font-medium">{{ $book->translations->count() }} lang</span>
                            @endif
                            @if($book->narrations->where('status', 'completed')->isNotEmpty())
                                <span class="bg-purple-500 text-white text-[9px] px-1.5 py-0.5 rounded font-medium">🎙️</span>
                            @endif
                        </div>
                    </a>

                    {{-- Info --}}
                    <div class="p-3">
                        <h3 class="font-medium text-gray-800 text-sm truncate">{{ $book->title }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $book->page_count }} pages &middot; {{ $book->sku }}</p>

                        {{-- Language selector --}}
                        <div class="mt-2 flex items-center gap-2">
                            <select class="flex-1 text-xs border border-gray-200 rounded px-2 py-1 bg-gray-50"
                                    onchange="if(this.value) window.open(this.value, '_blank')">
                                <option value="{{ route('reader', $book) }}">English</option>
                                @foreach($book->translations as $t)
                                    <option value="{{ route('reader', ['book' => $book->id, 'lang' => $t->language_code]) }}">{{ $t->language_name }}</option>
                                @endforeach
                            </select>
                            <a href="{{ route('admin.book', $book) }}" class="text-brand-500 hover:text-brand-600" title="Manage">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </a>
                            <a href="{{ route('admin.engine-compare', $book) }}" class="text-purple-500 hover:text-purple-600" title="Render & Review (V8)">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                            </a>
                            <a href="{{ route('admin.review-queue', $book) }}" class="text-yellow-500 hover:text-yellow-600" title="Review Queue">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                </svg>
                            </a>
                            {{-- Delete button --}}
                            <button wire:click="confirmDelete({{ $book->id }})" class="text-red-400 hover:text-red-600" title="Delete">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>

                        {{-- Delete confirmation --}}
                        @if($confirmingDelete === $book->id)
                            <div class="mt-2 p-2 bg-red-50 border border-red-200 rounded-lg">
                                <p class="text-xs text-red-700 mb-2">Delete "{{ $book->title }}" and all translations/narrations?</p>
                                <div class="flex gap-2">
                                    <button wire:click="deleteBook({{ $book->id }})" class="flex-1 text-xs bg-red-600 text-white py-1 rounded font-medium hover:bg-red-700">Yes, delete</button>
                                    <button wire:click="cancelDelete" class="flex-1 text-xs bg-gray-200 text-gray-700 py-1 rounded font-medium hover:bg-gray-300">Cancel</button>
                                </div>
                            </div>
                        @endif

                        {{-- Publish button --}}
                        <div class="mt-2">
                            @if($book->status === 'published')
                                <span class="block text-center text-xs bg-green-100 text-green-700 py-1.5 rounded-lg font-medium">✓ Published</span>
                            @else
                                <a href="{{ route('store.book', $book) }}" class="block text-center text-xs bg-brand-500 text-white py-1.5 rounded-lg font-medium hover:bg-brand-600 transition">
                                    Publish to Store
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
