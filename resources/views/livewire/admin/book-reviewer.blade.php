<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Translation Review</h1>
            @if($book && $translation)
                <p class="text-sm text-gray-500">{{ $book->title }} — {{ $translation->language_name }}</p>
            @endif
        </div>
        <div class="flex gap-3">
            <button wire:click="renderPdf" wire:loading.attr="disabled" wire:target="renderPdf"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition disabled:opacity-50 flex items-center gap-2">
                <span wire:loading.remove wire:target="renderPdf">
                    <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    {{ ($translation->rendered_pdf_path ?? null) ? 'Re-render PDF' : 'Render PDF' }}
                </span>
                <span wire:loading wire:target="renderPdf" class="flex items-center">
                    <svg class="animate-spin w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    Rendering...
                </span>
            </button>
            <button wire:click="approveAll" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition">
                Approve All Unreviewed
            </button>
            <a href="{{ route('admin.book', $book) }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300 transition">
                Back
            </a>
        </div>
    </div>

    {{-- Rendered PDF notice --}}
    @if($translation->rendered_pdf_path ?? null)
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center justify-between">
            <span class="text-sm text-green-700">📄 Rendered PDF available — edit text below and click "Re-render PDF" to update.</span>
            <a href="{{ asset('storage/' . $translation->rendered_pdf_path) }}" target="_blank"
               class="text-xs bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700">📥 View/Download PDF</a>
        </div>
    @endif

    {{-- Layout QA / debug overlay (brief §15). Shows the render-gate verdict and
         per-page failing regions from the persisted diagnostic manifest. --}}
    @php($qa = $translation->qa_report ?? null)
    @if(is_array($qa))
        @php($blocked = ($translation->render_status ?? null) === 'NEEDS_LAYOUT_REVIEW')
        <div x-data="{ open: {{ $blocked ? 'true' : 'false' }} }" class="mb-4 border rounded-lg {{ $blocked ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50' }}">
            <button type="button" @click="open = !open" class="w-full flex items-center justify-between px-4 py-2 text-sm font-medium">
                <span class="{{ $blocked ? 'text-red-700' : 'text-gray-700' }}">
                    🔎 Layout QA — status: <strong>{{ $translation->render_status ?? 'n/a' }}</strong>
                    @if($blocked) (NOT publishable — {{ count($qa['review_pages'] ?? []) }} page(s) need review) @else (passed) @endif
                </span>
                <span x-text="open ? '▲' : '▼'"></span>
            </button>
            <div x-show="open" class="px-4 pb-3">
                @php($manifest = $qa['diagnostic_manifest']['pages'] ?? [])
                @if(count($manifest))
                    <table class="w-full text-xs">
                        <thead><tr class="text-left text-gray-500">
                            <th class="py-1">Page</th><th>Type</th><th>Status</th><th>Units</th><th>Failure reasons</th>
                        </tr></thead>
                        <tbody>
                        @foreach($manifest as $mp)
                            <tr class="border-t border-gray-200 {{ ($mp['fitStatus'] ?? '') === 'FAILED' ? 'bg-red-100' : '' }}">
                                <td class="py-1 font-mono">{{ $mp['page'] }}</td>
                                <td>{{ $mp['page_type'] }}</td>
                                <td class="{{ ($mp['fitStatus'] ?? '') === 'FAILED' ? 'text-red-700 font-semibold' : 'text-green-700' }}">{{ $mp['fitStatus'] ?? 'OK' }}</td>
                                <td>{{ $mp['unit_count'] ?? 0 }}</td>
                                <td class="text-red-600">{{ implode('; ', $mp['failureReasons'] ?? []) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-[11px] text-gray-500">
                        Font: {{ $qa['diagnostic_manifest']['font_resolved'] ?? '?' }}
                        (hash {{ $qa['diagnostic_manifest']['font_file_hash'] ?? '?' }},
                        fallback {{ ($qa['diagnostic_manifest']['font_fallback_used'] ?? false) ? 'YES' : 'no' }}).
                        Fix the flagged page's translation and click "Re-render PDF".
                    </p>
                @else
                    <p class="text-xs text-gray-500">No diagnostic manifest recorded for this render.</p>
                @endif
            </div>
        </div>
    @endif

    {{-- Stats Bar --}}
    @if(!empty($stats))
        <div class="grid grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg border p-3 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ $stats['total'] }}</p>
                <p class="text-xs text-gray-500">Total Pages</p>
            </div>
            <div class="bg-white rounded-lg border p-3 text-center">
                <p class="text-2xl font-bold text-green-600">{{ $stats['approved'] }}</p>
                <p class="text-xs text-gray-500">Approved</p>
            </div>
            <div class="bg-white rounded-lg border p-3 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ $stats['unreviewed'] }}</p>
                <p class="text-xs text-gray-500">Unreviewed</p>
            </div>
            <div class="bg-white rounded-lg border p-3 text-center">
                <p class="text-2xl font-bold text-red-600">{{ $stats['needs_edit'] }}</p>
                <p class="text-xs text-gray-500">Needs Edit</p>
            </div>
            <div class="bg-white rounded-lg border p-3 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ $stats['avg_score'] }}</p>
                <p class="text-xs text-gray-500">Avg Score /10</p>
            </div>
        </div>
    @endif

    {{-- Filter --}}
    <div class="flex gap-2 mb-4">
        @foreach(['all' => 'All', 'unreviewed' => 'Unreviewed', 'approved' => 'Approved', 'needs_edit' => 'Needs Edit'] as $key => $label)
            <button
                wire:click="$set('filterStatus', '{{ $key }}')"
                class="px-3 py-1.5 rounded-lg text-sm font-medium transition {{ $filterStatus === $key ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Cover Page Text Block Configuration --}}
    @if(!empty($coverBlocks))
        <div class="bg-white rounded-xl border border-orange-200 shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                        <svg class="w-5 h-5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Cover Page Configuration
                    </h3>
                    <p class="text-xs text-gray-500 mt-1">Choose which text blocks on the cover should be translated. Character names and publisher info are auto-skipped.</p>
                </div>
                <button wire:click="saveCoverConfig" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm font-medium hover:bg-orange-600 transition">
                    Save Config
                </button>
            </div>

            @if(session('success'))
                <div class="mb-4 p-2 bg-green-50 border border-green-200 rounded text-xs text-green-700">{{ session('success') }}</div>
            @endif

            <div class="space-y-2">
                @foreach($coverBlocks as $block)
                    <div class="flex items-center justify-between p-3 rounded-lg border {{ ($coverBlockTranslate[$block['index']] ?? false) ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200' }}">
                        <div class="flex items-center gap-3">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox"
                                       wire:model.live="coverBlockTranslate.{{ $block['index'] }}"
                                       class="sr-only peer">
                                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500"></div>
                            </label>
                            <div>
                                <p class="text-sm font-medium text-gray-800">"{{ $block['text'] }}"</p>
                                <p class="text-xs text-gray-400">{{ $block['font'] }} · {{ $block['size'] }}pt {{ $block['is_bold'] ? '· Bold' : '' }}</p>
                            </div>
                        </div>
                        <span class="text-xs font-medium px-2 py-1 rounded {{ ($coverBlockTranslate[$block['index']] ?? false) ? 'bg-blue-100 text-blue-700' : 'bg-gray-200 text-gray-500' }}">
                            {{ ($coverBlockTranslate[$block['index']] ?? false) ? '🌍 Translate' : '🔒 Keep Original' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Pages List --}}
    <div class="space-y-4">
        @forelse($pages as $page)
            <div class="bg-white rounded-xl border shadow-sm overflow-hidden {{ $page['review_status'] === 'approved' ? 'border-green-200' : ($page['review_status'] === 'needs_edit' ? 'border-red-200' : 'border-gray-200') }}">
                {{-- Page Header --}}
                <div class="flex items-center justify-between px-4 py-2 bg-gray-50 border-b">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-bold text-gray-600">Page {{ $page['page_number'] }}</span>
                        @if($page['confidence_score'])
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $page['quality_flag'] === 'green' ? 'bg-green-100 text-green-700' : ($page['quality_flag'] === 'yellow' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                {{ $page['confidence_score'] }}/10
                            </span>
                        @endif
                        @if($page['quality_notes'])
                            <span class="text-xs text-gray-400 italic">{{ Str::limit($page['quality_notes'], 50) }}</span>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        @if($page['review_status'] === 'approved')
                            <span class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded font-medium">Approved</span>
                        @else
                            <button wire:click="approvePage({{ $page['id'] }})" class="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600">Approve</button>
                            <button wire:click="startEdit({{ $page['id'] }})" class="text-xs px-2 py-1 bg-blue-500 text-white rounded hover:bg-blue-600">Edit</button>
                            <button wire:click="rejectPage({{ $page['id'] }})" class="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600">Flag</button>
                        @endif
                    </div>
                </div>

                {{-- Side by Side Content --}}
                @if($editingPageId === $page['id'])
                    {{-- Edit Mode --}}
                    <div class="p-4">
                        <label class="text-xs font-medium text-gray-500 mb-1 block">Edit Translation:</label>
                        <textarea wire:model="editingText" class="w-full border border-gray-300 rounded-lg p-3 text-sm" rows="4"></textarea>
                        <div class="flex gap-2 mt-2">
                            <button wire:click="saveEdit" class="px-4 py-2 bg-green-600 text-white rounded text-sm hover:bg-green-700">Save & Approve</button>
                            <button wire:click="cancelEdit" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Cancel</button>
                        </div>
                    </div>
                @else
                    {{-- View Mode: Side by Side --}}
                    <div class="grid grid-cols-2 divide-x">
                        {{-- Original --}}
                        <div class="p-4">
                            <p class="text-xs font-medium text-gray-400 mb-1">Original (English)</p>
                            <p class="text-sm text-gray-700 whitespace-pre-line">{{ $page['original_text'] }}</p>
                        </div>
                        {{-- Translation --}}
                        <div class="p-4">
                            <p class="text-xs font-medium text-orange-400 mb-1">{{ $translation->language_name }}</p>
                            <p class="text-sm text-gray-700 whitespace-pre-line">{{ $page['translated_text'] }}</p>
                        </div>
                    </div>

                    {{-- Back Translation (collapsible) --}}
                    @if($page['back_translation'])
                        <details class="border-t">
                            <summary class="px-4 py-2 text-xs text-gray-400 cursor-pointer hover:text-gray-600">
                                Show back-translation (verification)
                            </summary>
                            <div class="px-4 pb-3">
                                <p class="text-xs text-gray-500 italic whitespace-pre-line">{{ $page['back_translation'] }}</p>
                            </div>
                        </details>
                    @endif
                @endif
            </div>
        @empty
            <div class="bg-white rounded-xl border p-8 text-center text-gray-400">
                No pages match the current filter.
            </div>
        @endforelse
    </div>
</div>
