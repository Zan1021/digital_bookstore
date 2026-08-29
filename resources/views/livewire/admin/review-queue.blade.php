<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="{{ route('admin.dashboard') }}" class="text-blue-600 hover:underline">&larr; Back to Dashboard</a>
    </div>

    <h1 class="text-2xl font-bold mb-2">Review Queue: {{ $book->title }}</h1>
    <p class="text-gray-600 mb-6">Review translated pages, approve or reject, and edit translations.</p>

    {{-- Stats bar --}}
    <div class="grid grid-cols-5 gap-4 mb-6">
        <div class="bg-gray-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold">{{ $stats['total'] }}</div>
            <div class="text-xs text-gray-500">Total</div>
        </div>
        <div class="bg-green-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-green-700">{{ $stats['approved'] }}</div>
            <div class="text-xs text-green-600">Approved</div>
        </div>
        <div class="bg-red-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-red-700">{{ $stats['rejected'] }}</div>
            <div class="text-xs text-red-600">Rejected</div>
        </div>
        <div class="bg-yellow-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-yellow-700">{{ $stats['flagged'] }}</div>
            <div class="text-xs text-yellow-600">Flagged</div>
        </div>
        <div class="bg-blue-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-blue-700">{{ $stats['unreviewed'] }}</div>
            <div class="text-xs text-blue-600">Unreviewed</div>
        </div>
    </div>

    {{-- Filter buttons --}}
    <div class="flex gap-2 mb-6">
        @foreach(['all' => 'All', 'flagged' => '⚠️ Flagged', 'approved' => '✅ Approved', 'rejected' => '❌ Rejected'] as $key => $label)
            <button wire:click="setFilter('{{ $key }}')"
                    class="px-4 py-2 rounded-lg text-sm font-medium {{ $filter === $key ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="grid grid-cols-12 gap-6">
        {{-- Page list (sidebar) --}}
        <div class="col-span-3 border rounded-lg overflow-hidden">
            <div class="bg-gray-100 px-3 py-2 font-semibold text-sm border-b">Pages</div>
            <div class="max-h-[600px] overflow-y-auto">
                @forelse($pages as $page)
                    <button wire:click="selectPage({{ $page['page_number'] }})"
                            class="w-full text-left px-3 py-2 border-b text-sm hover:bg-blue-50 flex items-center gap-2
                                   {{ $currentPage === $page['page_number'] ? 'bg-blue-100 font-semibold' : '' }}">
                        {{-- Quality indicator --}}
                        @if($page['quality_flag'] === 'green')
                            <span class="w-2 h-2 rounded-full bg-green-500"></span>
                        @elseif($page['quality_flag'] === 'yellow')
                            <span class="w-2 h-2 rounded-full bg-yellow-500"></span>
                        @elseif($page['quality_flag'] === 'red')
                            <span class="w-2 h-2 rounded-full bg-red-500"></span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-gray-300"></span>
                        @endif

                        <span>Page {{ $page['page_number'] }}</span>
                        <span class="ml-auto text-xs text-gray-400">{{ number_format($page['confidence_score'] ?? 0, 1) }}</span>
                    </button>
                @empty
                    <div class="p-4 text-gray-400 text-sm">No pages to review</div>
                @endforelse
            </div>
        </div>

        {{-- Main content area --}}
        <div class="col-span-9">
            @if($currentPageData)
                {{-- Page header with actions --}}
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">
                        Page {{ $currentPageData['page_number'] }}
                        @if($currentPageData['quality_flag'] === 'green')
                            <span class="text-sm font-normal text-green-600">✅ Approved</span>
                        @elseif($currentPageData['quality_flag'] === 'yellow')
                            <span class="text-sm font-normal text-yellow-600">⚠️ Needs Review</span>
                        @elseif($currentPageData['quality_flag'] === 'red')
                            <span class="text-sm font-normal text-red-600">❌ Rejected</span>
                        @endif
                    </h3>

                    <div class="flex gap-2">
                        <button wire:click="approvePage({{ $currentPageData['id'] }})"
                                class="px-3 py-1.5 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                            ✅ Approve
                        </button>
                        <button wire:click="flagForReview({{ $currentPageData['id'] }})"
                                class="px-3 py-1.5 bg-yellow-500 text-white rounded text-sm hover:bg-yellow-600">
                            ⚠️ Flag
                        </button>
                        <button wire:click="rejectPage({{ $currentPageData['id'] }})"
                                class="px-3 py-1.5 bg-red-600 text-white rounded text-sm hover:bg-red-700">
                            ❌ Reject
                        </button>
                    </div>
                </div>

                {{-- Quality notes --}}
                @if($currentPageData['quality_notes'])
                    <div class="mb-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-800">
                        <strong>Notes:</strong> {{ $currentPageData['quality_notes'] }}
                    </div>
                @endif

                {{-- Side-by-side images --}}
                @if($currentPageData['source_image'] || $currentPageData['translated_image'])
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="border rounded-lg overflow-hidden">
                            <div class="bg-gray-200 px-3 py-1 text-xs font-semibold">Original</div>
                            @if($currentPageData['source_image'])
                                <img src="{{ $currentPageData['source_image'] }}" class="w-full" alt="Source page">
                            @else
                                <div class="h-48 flex items-center justify-center text-gray-400 text-sm">No render available</div>
                            @endif
                        </div>
                        <div class="border rounded-lg overflow-hidden">
                            <div class="bg-green-200 px-3 py-1 text-xs font-semibold">Translated</div>
                            @if($currentPageData['translated_image'])
                                <img src="{{ $currentPageData['translated_image'] }}" class="w-full" alt="Translated page">
                            @else
                                <div class="h-48 flex items-center justify-center text-gray-400 text-sm">No render available</div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Translation text (editable) --}}
                <div class="border rounded-lg p-4">
                    <label class="block text-sm font-semibold mb-2">Translated Text (editable)</label>
                    <textarea
                        class="w-full h-32 p-3 border rounded-lg text-sm font-mono resize-y"
                        wire:change="updateTranslation({{ $currentPageData['id'] }}, $event.target.value)"
                    >{{ $currentPageData['translated_text'] }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">Edit the text above and click outside to save. Page will be re-rendered on next render cycle.</p>
                </div>
            @else
                <div class="flex items-center justify-center h-64 text-gray-400">
                    <p>Select a page from the sidebar to review</p>
                </div>
            @endif
        </div>
    </div>
</div>
