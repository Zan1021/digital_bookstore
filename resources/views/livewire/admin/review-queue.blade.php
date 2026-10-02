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

                {{-- Readiness summary (D3): AUTOMATED check status + coverage, distinct from
                     the human approvals below. Read-only — shows WHY the edition is ready or
                     blocked. --}}
                <div class="mb-4 p-3 rounded-lg border {{ ($readinessReport['ready'] ?? false) ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50' }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-semibold text-sm">Automated readiness</span>
                        <span class="text-sm {{ ($readinessReport['ready'] ?? false) ? 'text-green-700' : 'text-amber-700' }}">
                            {{ ($readinessReport['ready'] ?? false) ? '✅ Ready (all required checks passed)' : '⏳ Not ready' }}
                        </span>
                    </div>
                    @if(!empty($readinessReport['checks']))
                        <div class="flex flex-wrap gap-1 mb-2">
                            @foreach($readinessReport['checks'] as $name => $status)
                                <span class="inline-block px-2 py-0.5 rounded text-xs {{ $status === 'passed' ? 'bg-green-100 text-green-800' : ($status === 'failed' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700') }}">
                                    {{ $name }}: {{ $status }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @if($readinessReport['coverage'] ?? null)
                        <p class="text-xs text-gray-600">
                            Visual coverage: {{ $readinessReport['coverage']['checked'] }}/{{ $readinessReport['coverage']['expected'] }} pages checked
                            {{ ($readinessReport['coverage']['covered'] ?? false) ? '✓' : '— incomplete' }}
                        </p>
                    @endif
                    @if(!empty($readinessReport['issues']))
                        <p class="text-xs text-amber-700 mt-1">
                            Blocking: {{ collect($readinessReport['issues'])->map(fn($i) => $i['code'] ?? '?')->unique()->implode(', ') }}
                        </p>
                    @endif
                    <p class="text-[11px] text-gray-500 mt-1 italic">Automated findings remain visible after approval; a human approval cannot clear a failed automated check.</p>
                </div>

                {{-- Approval tracks (R10.4) + overlay toggle --}}
                <div class="flex items-center gap-2 mb-4 flex-wrap">
                    @foreach(['language' => 'Language', 'layout' => 'Layout', 'artwork' => 'Artwork'] as $tk => $lbl)
                        <button wire:click="approveTrack('{{ $tk }}')"
                                class="px-3 py-1.5 rounded text-sm {{ ($approvalTracks[$tk] ?? false) ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                            {{ ($approvalTracks[$tk] ?? false) ? '✅' : '○' }} {{ $lbl }}
                        </button>
                    @endforeach
                    @if($approvalTracks['artwork'] ?? false)
                        <button wire:click="reuseArtworkAcrossLanguages"
                                class="px-3 py-1.5 rounded text-sm bg-indigo-600 text-white hover:bg-indigo-700">
                            ♻️ Reuse artwork across languages
                        </button>
                    @endif
                    <button wire:click="toggleOverlay"
                            class="ml-auto px-3 py-1.5 rounded text-sm {{ $showOverlay ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                        {{ $showOverlay ? '🔲 Overlay on' : '⬚ Overlay off' }}
                    </button>
                </div>

                {{-- Overlay layer toggles (7.2) --}}
                @if($showOverlay && !empty($overlay))
                    <div class="flex gap-3 mb-3 text-xs flex-wrap">
                        @foreach(['sourceBounds' => 'Source ink', 'layoutContainer' => 'Container', 'eraseMask' => 'Erase mask', 'targetGlyphBounds' => 'Glyph bounds', 'protectedArtwork' => 'Protected artwork'] as $layer => $lbl)
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:click="toggleOverlayLayer('{{ $layer }}')"
                                       @checked($overlayToggles[$layer] ?? false)>
                                {{ $lbl }}
                            </label>
                        @endforeach
                        <span class="text-gray-400">drag a container box to adjust it</span>
                    </div>
                @endif

                {{-- Side-by-side images (with interactive overlay on the translated side) --}}
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
                            @if($showOverlay && !empty($overlay) && !empty($overlay['image_width']))
                                {{-- Interactive overlay (UI1/UI2): boxes drawn in % of the engine
                                     render size so they align regardless of displayed width.
                                     Alpine handles draw + drag; Livewire persists the drag. --}}
                                <div class="relative select-none"
                                     x-data="overlayLayer(@js($overlay), @js($overlayToggles), @js($selectedRegionId))">
                                    <img src="{{ $currentPageData['translated_image'] }}" class="w-full block" alt="Translated page" x-ref="img">
                                    <template x-for="r in regions" :key="r.regionId">
                                        <div class="absolute pointer-events-none" :style="boxStyle(r)">
                                            {{-- source ink --}}
                                            <template x-if="toggles.sourceBounds && r.sourceBounds">
                                                <div class="absolute inset-0 border border-sky-400/80"></div>
                                            </template>
                                        </div>
                                    </template>
                                    {{-- container boxes are draggable, so rendered separately with pointer events --}}
                                    <template x-for="r in regions" :key="'c'+r.regionId">
                                        <div x-show="toggles.layoutContainer && r.layoutContainer"
                                             class="absolute border-2 cursor-move"
                                             :class="selected===r.regionId ? 'border-amber-500' : 'border-emerald-500/70'"
                                             :style="rectStyle(r.layoutContainer)"
                                             @mousedown="startDrag($event, r)"
                                             @click="select(r.regionId)"></div>
                                    </template>
                                    <template x-for="r in regions" :key="'m'+r.regionId">
                                        <div x-show="toggles.eraseMask && r.eraseMask"
                                             class="absolute bg-fuchsia-500/25 border border-fuchsia-500 pointer-events-none"
                                             :style="rectStyle(r.eraseMask)"></div>
                                    </template>
                                    <template x-for="r in regions" :key="'g'+r.regionId">
                                        <div x-show="toggles.targetGlyphBounds && r.targetGlyphBounds"
                                             class="absolute border border-yellow-400 pointer-events-none"
                                             :style="rectStyle(r.targetGlyphBounds)"></div>
                                    </template>
                                    <template x-for="r in regions" :key="'a'+r.regionId">
                                        <div x-show="toggles.protectedArtwork && r.protectedArtwork"
                                             class="absolute bg-indigo-500/10 border border-indigo-500 pointer-events-none"
                                             :style="rectStyle(r.protectedArtwork)"></div>
                                    </template>
                                </div>
                            @elseif($currentPageData['translated_image'])
                                <img src="{{ $currentPageData['translated_image'] }}" class="w-full" alt="Translated page">
                            @else
                                <div class="h-48 flex items-center justify-center text-gray-400 text-sm">No render available</div>
                            @endif
                        </div>
                    </div>

                    {{-- Selected-region info panel (R10.2) --}}
                    @if($showOverlay && $selectedRegionId && !empty($overlay['regions']))
                        @php $sel = collect($overlay['regions'])->firstWhere('regionId', $selectedRegionId); @endphp
                        @if($sel)
                            <div class="mb-6 p-3 bg-gray-50 border rounded-lg text-xs grid grid-cols-2 gap-x-6 gap-y-1">
                                <div><strong>Region:</strong> {{ $sel['regionId'] }}</div>
                                <div><strong>Type:</strong> {{ $sel['semanticType'] ?? '—' }}</div>
                                <div><strong>Content class:</strong> {{ $sel['contentClass'] ?? '—' }}</div>
                                <div><strong>Visual scale:</strong> {{ $sel['visualScaleRatio'] ?? '—' }}</div>
                                <div><strong>Line height:</strong> {{ $sel['lineHeight'] ?? '—' }}</div>
                                <div><strong>Clipped glyphs:</strong> {{ $sel['clippedGlyphCount'] ?? 0 }}</div>
                                @if(!empty($sel['reasons']))
                                    <div class="col-span-2 text-red-600"><strong>Issues:</strong> {{ implode('; ', $sel['reasons']) }}</div>
                                @endif
                            </div>
                        @endif
                    @endif
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

@script
<script>
Alpine.data('overlayLayer', (overlay, toggles, selectedRegionId) => ({
    overlay,
    toggles,
    selected: selectedRegionId,
    regions: overlay.regions || [],
    // engine render size the boxes are expressed in (image pixels)
    get rw() { return this.overlay.image_width || 1; },
    get rh() { return this.overlay.image_height || 1; },
    dragging: null,

    // box [x0,y0,x1,y1] in engine px -> CSS % within the displayed (scaled) image
    rectStyle(b) {
        if (!b) return 'display:none';
        const l = (b[0] / this.rw) * 100, t = (b[1] / this.rh) * 100;
        const w = ((b[2] - b[0]) / this.rw) * 100, h = ((b[3] - b[1]) / this.rh) * 100;
        return `left:${l}%;top:${t}%;width:${w}%;height:${h}%`;
    },
    boxStyle(r) { return this.rectStyle(r.sourceBounds || r.layoutContainer); },

    select(id) { this.selected = id; $wire.selectRegion(id); },

    startDrag(ev, r) {
        if (!this.toggles.layoutContainer || !r.layoutContainer) return;
        ev.preventDefault();
        this.select(r.regionId);
        const imgRect = this.$refs.img.getBoundingClientRect();
        const startX = ev.clientX, startY = ev.clientY;
        const orig = [...r.layoutContainer];
        const pxPerClientX = this.rw / imgRect.width;   // engine px per displayed px
        const pxPerClientY = this.rh / imgRect.height;
        const onMove = (e) => {
            const dx = (e.clientX - startX) * pxPerClientX;
            const dy = (e.clientY - startY) * pxPerClientY;
            r.layoutContainer = [orig[0] + dx, orig[1] + dy, orig[2] + dx, orig[3] + dy];
        };
        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            const b = r.layoutContainer;
            // persist the moved container (engine px) — the component converts px->pt
            $wire.updateRegionContainer(r.regionId, b[0], b[1], b[2], b[3]);
        };
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    },
}));
</script>
@endscript
