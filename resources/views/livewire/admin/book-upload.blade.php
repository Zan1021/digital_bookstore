<div>
    @php
        $steps = ['upload' => 'Upload', 'crop' => 'Crop & Bleed', 'languages' => 'Languages', 'voice' => 'Narrator', 'processing' => 'Processing', 'done' => 'Done'];
        $stepKeys = array_keys($steps);
        $currentIdx = array_search($currentStep, $stepKeys);
    @endphp

    {{-- Progress bar --}}
    <div class="mb-8">
        <div class="flex items-center justify-between mb-2">
            <h1 class="text-2xl font-bold text-gray-800">Upload Books</h1>
            <span class="text-sm text-gray-400">Step {{ $currentIdx + 1 }} of {{ count($steps) }}</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-2">
            <div class="h-2 rounded-full transition-all duration-500" style="width: {{ ($currentIdx / (count($steps) - 1)) * 100 }}%; background: linear-gradient(to right, #fd5826, #ff8468);"></div>
        </div>
    </div>

    @if($currentStep === 'upload')
        {{-- STEP 1: Upload --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Upload Your Books</h2>
            <p class="text-sm text-gray-500 mb-6">Drop one or multiple PDF files.</p>
            <div class="border-2 border-dashed border-gray-300 rounded-xl p-16 text-center hover:border-brand-400 transition cursor-pointer">
                <input type="file" wire:model="files" multiple accept=".pdf" class="hidden" id="pdf-upload">
                <label for="pdf-upload" class="cursor-pointer">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    <p class="text-lg text-gray-600 font-medium">Drop PDF files here or click to browse</p>
                    <p class="text-sm text-gray-400 mt-2">Supports single or bulk upload</p>
                </label>
            </div>
            @if(count($files) > 0)
                <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">{{ count($files) }} file(s) ready</div>
            @endif
        </div>

    @elseif($currentStep === 'crop')
        {{-- STEP 2: Crop --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            @if($detectedCrop && $detectedCrop['detected'])
                {{-- Auto-detected — with celebration --}}
                <style>
                    @keyframes confetti-fall { 0% { transform: translateY(-100%) rotate(0deg); opacity: 1; } 100% { transform: translateY(500px) rotate(720deg); opacity: 0; } }
                    @keyframes pop-scale { 0% { transform: scale(0); } 60% { transform: scale(1.2); } 100% { transform: scale(1); } }
                    .confetti-piece { position: absolute; width: 10px; height: 10px; opacity: 0; animation: confetti-fall 2s ease-out forwards; }
                    .pop-anim { animation: pop-scale 0.5s ease-out; }
                </style>
                <div class="text-center max-w-lg mx-auto relative overflow-hidden" style="min-height: 400px;">
                    {{-- CSS Confetti pieces --}}
                    @for($i = 0; $i < 50; $i++)
                        <div class="confetti-piece" style="
                            left: {{ rand(5, 95) }}%;
                            top: -10px;
                            animation-delay: {{ $i * 0.05 }}s;
                            background: {{ ['#fd5826','#22c55e','#eab308','#6366f1','#ec4899','#14b8a6','#f59e0b'][$i % 7] }};
                            border-radius: {{ $i % 3 === 0 ? '50%' : '0' }};
                            width: {{ rand(6, 14) }}px;
                            height: {{ rand(6, 14) }}px;
                            transform: rotate({{ rand(0, 360) }}deg);
                        "></div>
                    @endfor

                    <div class="relative z-10 pt-8">
                        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 pop-anim">
                            <svg class="w-10 h-10 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 pop-anim" style="animation-delay: 0.2s;">TrimBox Auto-Detected!</h2>
                        <p class="text-gray-500 mt-2">Crop/bleed marks found. Trim area automatically set.</p>

                        <div class="mt-6 p-4 bg-gray-50 rounded-lg inline-block">
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div class="text-left">
                                    <span class="text-gray-400 text-xs block">Full page</span>
                                    <span class="font-mono font-bold text-gray-700">{{ $detectedCrop['media_width_mm'] }}mm x {{ $detectedCrop['media_height_mm'] }}mm</span>
                                </div>
                                <div class="text-left">
                                    <span class="text-gray-400 text-xs block">Book size (trimmed)</span>
                                    <span class="font-mono font-bold text-green-700">{{ $detectedCrop['trim_width_mm'] }}mm x {{ $detectedCrop['trim_height_mm'] }}mm</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-8">
                            <button wire:click="nextStep" class="bg-brand-500 text-white px-8 py-3 rounded-lg font-medium hover:bg-brand-600 transition text-lg">Looks good — Next →</button>
                        </div>
                        <button wire:click="prevStep" class="mt-4 text-sm text-gray-400 hover:text-gray-600">← Back</button>
                    </div>
                </div>
            @else
                {{-- No TrimBox — manual mode --}}
                <div class="text-center max-w-lg mx-auto">
                    <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800">No TrimBox Found</h2>
                    <p class="text-gray-500 mt-2">We couldn't auto-detect crop marks. Set the crop manually if needed.</p>

                    <div class="mt-6 space-y-3 text-left max-w-sm mx-auto">
                        <label class="flex items-center space-x-3 p-3 rounded-lg border cursor-pointer {{ !$hasCropMarks ? 'border-brand-500 bg-brand-50' : 'border-gray-200' }}">
                            <input type="radio" name="cropOpt" wire:model.live="hasCropMarks" value="0">
                            <span class="text-sm text-gray-700">No crop needed — display as-is</span>
                        </label>
                        <label class="flex items-center space-x-3 p-3 rounded-lg border cursor-pointer {{ $hasCropMarks ? 'border-brand-500 bg-brand-50' : 'border-gray-200' }}">
                            <input type="radio" name="cropOpt" wire:model.live="hasCropMarks" value="1">
                            <span class="text-sm text-gray-700">Crop edges manually</span>
                        </label>
                        @if($hasCropMarks)
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <label class="text-xs text-gray-600">Crop % from each edge</label>
                                <input type="range" wire:model.live="cropPercent" min="1" max="10" step="1" class="w-full mt-1">
                                <span class="text-xs font-bold text-brand-500">{{ $cropPercent }}%</span>
                            </div>
                        @endif
                    </div>

                    <div class="mt-8">
                        <button wire:click="nextStep" class="bg-brand-500 text-white px-8 py-3 rounded-lg font-medium hover:bg-brand-600 transition">Next →</button>
                    </div>
                    <button wire:click="prevStep" class="mt-4 text-sm text-gray-400 hover:text-gray-600">← Back</button>
                </div>
            @endif
        </div>

    @elseif($currentStep === 'languages')
        {{-- STEP 3: Languages --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Choose Languages</h2>
            <p class="text-sm text-gray-500 mb-6">Select which languages to auto-translate into.</p>
            <div class="space-y-3 max-w-lg">
                <label class="flex items-center p-4 border-2 border-brand-400 bg-brand-50 rounded-lg">
                    <input type="checkbox" checked disabled class="w-5 h-5 text-brand-500 rounded mr-4">
                    <span class="font-medium text-gray-700">English</span><span class="text-xs text-gray-400 ml-2">(original)</span>
                </label>
                <label class="flex items-center p-4 border-2 rounded-lg cursor-pointer transition {{ $langAfrikaans ? 'border-brand-400 bg-brand-50' : 'border-gray-200' }}">
                    <input type="checkbox" wire:model.live="langAfrikaans" class="w-5 h-5 text-brand-500 rounded mr-4">
                    <span class="font-medium text-gray-700">Afrikaans</span>
                </label>
                <label class="flex items-center p-4 border-2 rounded-lg cursor-pointer transition {{ $langZulu ? 'border-brand-400 bg-brand-50' : 'border-gray-200' }}">
                    <input type="checkbox" wire:model.live="langZulu" class="w-5 h-5 text-brand-500 rounded mr-4">
                    <span class="font-medium text-gray-700">isiZulu</span>
                </label>
            </div>
            <div class="flex justify-between mt-8 pt-6 border-t">
                <button wire:click="prevStep" class="px-5 py-2 text-gray-500 hover:text-gray-700 text-sm">← Back</button>
                <button wire:click="nextStep" class="bg-brand-500 text-white px-6 py-2.5 rounded-lg font-medium hover:bg-brand-600 transition">Next →</button>
            </div>
        </div>

    @elseif($currentStep === 'voice')
        {{-- STEP 4: Narrator --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">AI Narrator</h2>
                    <p class="text-sm text-gray-500 mt-1">Choose voice and style.</p>
                </div>
                <label class="flex items-center space-x-2 cursor-pointer">
                    <span class="text-sm text-gray-600">Enable</span>
                    <input type="checkbox" wire:model.live="enableNarration" class="w-5 h-5 text-brand-500 rounded">
                </label>
            </div>

            @if($enableNarration)
                <div class="space-y-6">
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <label class="block text-sm font-medium text-gray-700 mb-3">Drama Level</label>
                        <input type="range" wire:model.live="dramaLevel" min="0" max="100" step="5" class="w-full">
                        <div class="flex justify-between text-xs mt-1">
                            <span>😌 Chill</span><span class="font-bold text-brand-500">{{ $dramaLevel }}%</span><span>🎭 Full storyteller</span>
                        </div>
                    </div>
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <label class="block text-sm font-medium text-gray-700 mb-3">Reading Speed</label>
                        <input type="range" wire:model.live="speedLevel" min="0" max="100" step="5" class="w-full">
                        <div class="flex justify-between text-xs mt-1">
                            <span>🐢 Slow</span><span class="font-bold text-brand-500">{{ $speedLevel }}%</span><span>🐇 Normal</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Voice (click to preview)</label>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2 max-h-52 overflow-y-auto">
                            @foreach($availableVoices as $voice)
                                <label class="flex items-center p-2 border-2 rounded-lg cursor-pointer transition text-xs {{ $selectedVoice === $voice['voice_id'] ? 'border-brand-500 bg-brand-50' : 'border-gray-200' }}" onclick="playVoicePreview('{{ $voice['preview_url'] ?? '' }}')">
                                    <input type="radio" wire:model.live="selectedVoice" value="{{ $voice['voice_id'] }}" class="hidden">
                                    <div class="flex-1"><span class="font-medium text-gray-700">{{ $voice['name'] }}</span><span class="text-gray-400 block">{{ $voice['category'] }}</span></div>
                                    @if($voice['preview_url'] ?? null)<span class="text-brand-400">🔊</span>@endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <audio id="voice-preview-audio" preload="none"></audio>
                <script>
                function playVoicePreview(u) {
                    if (!u) return;
                    const a = document.getElementById('voice-preview-audio');
                    if (!a) return;
                    if (!a.paused && a.src === u) { a.pause(); return; }
                    a.src = u;
                    a.play().catch(function(e) { console.log('Preview failed:', e); });
                }
                </script>
            @endif

            <div class="flex justify-between mt-8 pt-6 border-t">
                <button wire:click="prevStep" class="px-5 py-2 text-gray-500 hover:text-gray-700 text-sm">← Back</button>
                <button wire:click="nextStep" class="bg-brand-500 text-white px-8 py-3 rounded-lg font-medium text-lg hover:bg-brand-600 transition shadow-lg">Process {{ count($files) }} Book(s) 🚀</button>
            </div>
        </div>

    @elseif($currentStep === 'processing')
        {{-- STEP 5: Processing --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-500 mx-auto mb-4"></div>
            <h2 class="text-xl font-semibold text-gray-800">Processing Books...</h2>
            <p class="text-gray-500 mt-2">{{ $processed }} / {{ $total }} completed</p>
            <div class="w-full bg-gray-200 rounded-full h-3 mt-6 max-w-md mx-auto overflow-hidden">
                <div class="h-3 rounded-full transition-all duration-500" style="width: {{ $total > 0 ? ($processed / $total * 100) : 0 }}%; background: linear-gradient(to right, #22c55e, #eab308, #ef4444);"></div>
            </div>
        </div>

    @elseif($currentStep === 'done')
        {{-- STEP 6: Done --}}
        <div class="space-y-4">
            <div class="bg-green-50 border border-green-200 rounded-xl p-6 text-center">
                <svg class="w-14 h-14 text-green-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                <h2 class="text-xl font-semibold text-green-800">All Done!</h2>
                <p class="text-green-600 mt-1">{{ count(array_filter($results, fn($r) => $r['success'])) }} book(s) processed</p>
            </div>
            @foreach($results as $result)
                <div class="bg-white rounded-xl border p-4 {{ $result['success'] ? 'border-gray-100' : 'border-red-200' }}">
                    @if($result['success'])
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="font-medium text-gray-800">{{ $result['title'] }}</h4>
                                <p class="text-xs text-gray-500">{{ $result['pages'] }} pages @if(!empty($result['translations'])) · Translated: {{ implode(', ', $result['translations']) }} @endif @if($result['narration'] === 'completed') · Narrated ✓ @endif</p>
                            </div>
                            <div class="flex gap-2">
                                <a href="{{ route('flipbook', $result['book_id']) }}" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-xs hover:bg-green-700">View</a>
                                <a href="{{ route('store.book', $result['book_id']) }}" class="bg-brand-500 text-white px-3 py-1.5 rounded-lg text-xs hover:bg-brand-600">Store</a>
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-red-700">{{ $result['filename'] }}: {{ $result['error'] }}</p>
                    @endif
                </div>
            @endforeach
            <div class="flex justify-center gap-4 mt-6">
                <button wire:click="uploadMore" class="bg-brand-500 text-white px-6 py-2.5 rounded-lg hover:bg-brand-600 transition">Upload More</button>
                <a href="{{ route('admin.dashboard') }}" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg hover:bg-gray-200 transition">Dashboard</a>
            </div>
        </div>
    @endif
</div>
