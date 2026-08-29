<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="{{ route('admin.dashboard') }}" class="text-blue-600 hover:underline">&larr; Back to Dashboard</a>
    </div>

    <h1 class="text-2xl font-bold mb-2">Render & Review: {{ $book->title }}</h1>
    <p class="text-gray-600 mb-6">Render the translated PDF using the V8 engine and review the output.</p>

    {{-- Render button --}}
    <div class="flex gap-4 mb-8 flex-wrap">
        <button wire:click="renderV8" wire:loading.attr="disabled"
                class="px-6 py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 disabled:opacity-50">
            <span wire:loading.remove wire:target="renderV8">🚀 Render PDF (V8 Engine)</span>
            <span wire:loading wire:target="renderV8">⏳ Rendering...</span>
        </button>

        <a href="{{ route('admin.review-queue', [$book, $selectedLanguage ?? 'af']) }}"
           class="px-6 py-3 bg-gray-600 text-white rounded-lg font-semibold hover:bg-gray-700 inline-flex items-center">
            📋 Review Queue
        </a>

        <a href="{{ route('admin.fonts', $book) }}"
           class="px-6 py-3 bg-purple-600 text-white rounded-lg font-semibold hover:bg-purple-700 inline-flex items-center">
            🔤 Font Manager
        </a>
    </div>

    {{-- Book info --}}
    <div class="mb-6 p-4 bg-gray-50 rounded-lg text-sm">
        <p><strong>Pages:</strong> {{ $book->page_count }} | <strong>Manifest:</strong> {{ $book->manifest_path ? '✅ Generated' : '❌ Not generated' }}</p>
    </div>

    {{-- Status --}}
    <div class="mb-8">
        <div class="border rounded-lg p-4 {{ $v8Status === 'ready' ? 'border-green-300 bg-green-50' : 'border-gray-200' }}">
            <h3 class="font-bold text-lg mb-2">V8 Engine Output</h3>
            @if($v8Status === 'ready')
                <span class="inline-block px-2 py-1 bg-green-100 text-green-800 text-sm rounded">✅ Ready</span>
            @elseif($v8Status)
                <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-sm rounded">{{ $v8Status }}</span>
            @else
                <span class="inline-block px-2 py-1 bg-gray-100 text-gray-600 text-sm rounded">Not rendered yet</span>
            @endif

            @if($v8Report)
                <div class="mt-3 text-sm text-gray-600">
                    <p>Pages: {{ $v8Report['pages_processed'] ?? 0 }} | Spans replaced: {{ $v8Report['spans_replaced'] ?? 0 }}</p>
                    @if(!empty($v8Report['coverage']))
                        <p>Coverage: {{ $v8Report['coverage']['total_translated'] ?? 0 }}/{{ $v8Report['coverage']['total_source_spans'] ?? 0 }} spans</p>
                    @endif
                    @if(!empty($v8Report['errors']))
                        <p class="text-red-600">Errors: {{ count($v8Report['errors']) }}</p>
                    @endif
                    @if(!empty($v8Report['overflow_warnings']))
                        <p class="text-yellow-600">Warnings: {{ count($v8Report['overflow_warnings']) }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- PDF viewer --}}
    @if($v8PdfUrl)
        <div class="border rounded-lg overflow-hidden">
            <div class="bg-green-600 text-white px-4 py-2 font-semibold">Translated PDF</div>
            <iframe src="{{ $v8PdfUrl }}" class="w-full" style="height: 800px;" frameborder="0"></iframe>
        </div>
    @else
        <div class="border rounded-lg flex items-center justify-center h-48 text-gray-400">
            <p>Click "Render PDF" to generate the translated output</p>
        </div>
    @endif
</div>
