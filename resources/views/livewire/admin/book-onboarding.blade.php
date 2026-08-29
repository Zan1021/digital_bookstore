<div>
    @php
        $steps = ['upload' => 'Upload PDF', 'crop' => 'Crop & Bleed', 'font' => 'Fonts', 'preview' => 'Preview', 'narrate' => 'Narrator', 'signoff' => 'Sign Off'];
        $stepKeys = array_keys($steps);
        $currentIdx = array_search($currentStep, $stepKeys);
    @endphp

    {{-- Progress Steps --}}
    <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold text-gray-800">Book Onboarding</h1>
            <span class="text-sm text-gray-400">Phase 1 — Step {{ $currentIdx + 1 }} of {{ count($steps) }}</span>
        </div>
        <div class="flex items-center gap-1">
            @foreach($steps as $key => $label)
                @php $idx = array_search($key, $stepKeys); @endphp
                <div class="flex-1">
                    <div class="h-2 rounded-full {{ $idx <= $currentIdx ? 'bg-orange-500' : 'bg-gray-200' }} transition-all"></div>
                    <p class="text-xs mt-1 {{ $idx === $currentIdx ? 'text-orange-600 font-semibold' : 'text-gray-400' }}">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Processing Overlay --}}
    @if($processing)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-xl p-8 shadow-2xl text-center max-w-sm">
                <div class="animate-spin w-12 h-12 border-4 border-orange-500 border-t-transparent rounded-full mx-auto mb-4"></div>
                <p class="text-gray-700 font-medium">{{ $processingMessage }}</p>
            </div>
        </div>
    @endif

    {{-- ===== STEP 1: UPLOAD ===== --}}
    @if($currentStep === 'upload')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Upload Your Book</h2>
            <p class="text-sm text-gray-500 mb-6">Upload a single children's book PDF. We'll extract the text, detect fonts, and prepare it for translation.</p>

            <div class="border-2 border-dashed border-gray-300 rounded-xl p-16 text-center hover:border-orange-400 transition cursor-pointer">
                <input type="file" wire:model="pdfFile" accept=".pdf" class="hidden" id="pdf-onboard">
                <label for="pdf-onboard" class="cursor-pointer">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    <p class="text-lg text-gray-600 font-medium">Drop a PDF here or click to browse</p>
                    <p class="text-sm text-gray-400 mt-2">Max 100MB • Single book per upload</p>
                </label>
            </div>

            @if($pdfFile)
                <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ $pdfFile->getClientOriginalName() }}
                </div>
            @endif
        </div>

    {{-- ===== STEP 2: CROP ===== --}}
    @elseif($currentStep === 'crop')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Crop & Bleed Detection</h2>

            @if($detectedCrop && $detectedCrop['detected'])
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                    <p class="text-green-800 font-medium">TrimBox Detected!</p>
                    <p class="text-green-700 text-sm mt-1">
                        Trim size: {{ $detectedCrop['trim_width_mm'] }}mm × {{ $detectedCrop['trim_height_mm'] }}mm
                        (from {{ $detectedCrop['media_width_mm'] }}mm × {{ $detectedCrop['media_height_mm'] }}mm)
                    </p>
                    <p class="text-green-600 text-sm mt-1">Crop: ~{{ $detectedCrop['crop_avg'] }}% on each side</p>
                </div>

                <div class="flex gap-4">
                    <button wire:click="confirmCrop" class="px-6 py-3 bg-orange-500 text-white rounded-lg font-medium hover:bg-orange-600 transition">
                        Use Detected Crop
                    </button>
                    <button wire:click="skipCrop" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-medium hover:bg-gray-300 transition">
                        No Crop Needed
                    </button>
                </div>
            @else
                <p class="text-gray-500 mb-6">No crop marks detected. The PDF appears to be print-ready without bleed.</p>
                <button wire:click="skipCrop" class="px-6 py-3 bg-orange-500 text-white rounded-lg font-medium hover:bg-orange-600 transition">
                    Continue
                </button>
            @endif
        </div>

    {{-- ===== STEP 3: FONTS ===== --}}
    @elseif($currentStep === 'font')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Font Detection</h2>
            <p class="text-sm text-gray-500 mb-6">We detected these fonts in your PDF. Fonts marked as "missing" need to be uploaded for perfect translation rendering.</p>

            <div class="space-y-3 mb-6">
                @foreach($detectedFonts as $font)
                    <div class="flex items-center justify-between p-3 rounded-lg {{ $font['has_file'] ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' }}">
                        <div>
                            <p class="font-medium {{ $font['has_file'] ? 'text-green-800' : 'text-red-800' }}">{{ $font['pdf_name'] }}</p>
                            @if($font['has_file'])
                                <p class="text-xs text-green-600">
                                    @if($font['source'] === 'uploaded') Uploaded by you
                                    @elseif($font['source'] === 'local') Found locally
                                    @else Matched to: {{ $font['matched_to'] }}
                                    @endif
                                </p>
                            @else
                                <p class="text-xs text-red-600">Not available — upload the TTF file</p>
                            @endif
                        </div>
                        <div>
                            @if($font['has_file'])
                                <span class="text-green-600 text-sm font-medium">Ready</span>
                            @else
                                <button wire:click="$set('fontUploadTarget', '{{ $font['pdf_name'] }}')" class="text-sm px-3 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 transition">
                                    Upload Font
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Font upload area --}}
            @if($fontUploadTarget)
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                    <p class="text-sm font-medium text-gray-700 mb-2">Upload TTF file for: <strong>{{ $fontUploadTarget }}</strong></p>
                    <input type="file" wire:model="fontUpload" accept=".ttf,.otf" class="text-sm">
                    @if($fontUpload)
                        <button wire:click="uploadFont" class="mt-2 px-4 py-2 bg-orange-500 text-white rounded text-sm hover:bg-orange-600">Upload</button>
                    @endif
                </div>
            @endif

            <button wire:click="confirmFonts" class="px-6 py-3 bg-orange-500 text-white rounded-lg font-medium hover:bg-orange-600 transition">
                Continue ({{ collect($fontStatus)->filter(fn($s) => $s !== 'missing')->count() }}/{{ count($fontStatus) }} fonts ready)
            </button>
        </div>

    {{-- ===== STEP 4: PREVIEW ===== --}}
    @elseif($currentStep === 'preview')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Preview Extraction</h2>
            <p class="text-sm text-gray-500 mb-6">Review the extracted text. Make sure it looks correct before proceeding.</p>

            @if($book)
                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <p class="text-blue-800 font-medium">{{ $book->title }}</p>
                    <p class="text-blue-600 text-sm">{{ $book->page_count }} pages • {{ collect($extractedPages)->where('has_text', true)->count() }} with text</p>
                </div>

                <div class="max-h-96 overflow-y-auto border border-gray-200 rounded-lg divide-y">
                    @foreach($extractedPages as $page)
                        <div class="p-3 {{ $page['has_text'] ? '' : 'bg-gray-50' }}">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-medium text-gray-400">Page {{ $page['page_number'] }}</span>
                                @if(!$page['has_text'])
                                    <span class="text-xs text-gray-400 italic">Image only</span>
                                @endif
                            </div>
                            @if($page['has_text'])
                                <p class="text-sm text-gray-700 whitespace-pre-line">{{ Str::limit($page['text'], 200) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-4 mt-6">
                <button wire:click="confirmPreview" class="px-6 py-3 bg-orange-500 text-white rounded-lg font-medium hover:bg-orange-600 transition">
                    Text Looks Good — Continue
                </button>
                <button wire:click="prevStep" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-medium hover:bg-gray-300 transition">
                    Back
                </button>
            </div>
        </div>

    {{-- ===== STEP 5: NARRATOR ===== --}}
    @elseif($currentStep === 'narrate')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Choose Narrator</h2>
            <p class="text-sm text-gray-500 mb-6">Select a voice for the original language narration.</p>

            @if(count($availableVoices) > 0)
                {{-- Gender filter --}}
                <div class="flex gap-4 mb-6">
                    <button wire:click="$set('voiceGender', 'male')" class="px-4 py-2 rounded-lg text-sm font-medium {{ $voiceGender === 'male' ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-700' }}">
                        Male Voices
                    </button>
                    <button wire:click="$set('voiceGender', 'female')" class="px-4 py-2 rounded-lg text-sm font-medium {{ $voiceGender === 'female' ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-700' }}">
                        Female Voices
                    </button>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
                    @foreach($availableVoices as $voice)
                        @php $isGenderMatch = str_contains(strtolower($voice['labels']['gender'] ?? ''), $voiceGender); @endphp
                        @if($isGenderMatch || empty($voice['labels']['gender']))
                            <button
                                wire:click="$set('selectedVoice', '{{ $voice['voice_id'] }}')"
                                class="p-3 rounded-lg border text-left transition {{ $selectedVoice === $voice['voice_id'] ? 'border-orange-500 bg-orange-50' : 'border-gray-200 hover:border-orange-300' }}"
                            >
                                <p class="font-medium text-sm">{{ $voice['name'] }}</p>
                                <p class="text-xs text-gray-400">{{ $voice['labels']['accent'] ?? 'Neutral' }} • {{ $voice['labels']['age'] ?? '' }}</p>
                            </button>
                        @endif
                    @endforeach
                </div>
            @else
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <p class="text-yellow-800 text-sm">ElevenLabs not configured. Narration will be skipped.</p>
                </div>
            @endif

            <div class="flex gap-4">
                <button wire:click="confirmNarration" class="px-6 py-3 bg-orange-500 text-white rounded-lg font-medium hover:bg-orange-600 transition">
                    {{ count($availableVoices) > 0 ? 'Generate Narration' : 'Continue' }}
                </button>
                <button wire:click="skipNarration" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-medium hover:bg-gray-300 transition">
                    Skip Narration
                </button>
            </div>
        </div>

    {{-- ===== STEP 6: SIGN-OFF ===== --}}
    @elseif($currentStep === 'signoff')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center">
            @if(!$signedOff)
                <div class="max-w-md mx-auto">
                    <svg class="w-20 h-20 mx-auto text-orange-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h2 class="text-xl font-bold text-gray-800 mb-2">Ready for Sign-Off</h2>
                    <p class="text-gray-500 mb-6">
                        Please review the book one final time. Once signed off, this becomes the source for all translations.
                    </p>

                    @if($book)
                        <div class="bg-gray-50 rounded-lg p-4 mb-6 text-left text-sm">
                            <p><strong>Title:</strong> {{ $book->title }}</p>
                            <p><strong>Pages:</strong> {{ $book->page_count }}</p>
                            <p><strong>Narration:</strong> {{ $enableNarration ? 'Generated' : 'Skipped' }}</p>
                            <p><strong>Fonts:</strong> {{ collect($fontStatus)->filter(fn($s) => $s !== 'missing')->count() }}/{{ count($fontStatus) }} ready</p>
                        </div>
                    @endif

                    <button wire:click="signOff" class="px-8 py-4 bg-green-600 text-white rounded-xl font-bold text-lg hover:bg-green-700 transition shadow-lg">
                        Approve Base Book
                    </button>
                    <p class="text-xs text-gray-400 mt-3">This unlocks the Translation phase</p>
                </div>
            @else
                <div class="max-w-md mx-auto">
                    <svg class="w-20 h-20 mx-auto text-green-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <h2 class="text-xl font-bold text-green-700 mb-2">Book Approved!</h2>
                    <p class="text-gray-500 mb-6">The base book is locked. You can now proceed to translate into other languages.</p>

                    <div class="flex gap-4 justify-center">
                        <a href="/admin/books/{{ $book?->id }}/translate" class="px-6 py-3 bg-orange-500 text-white rounded-lg font-medium hover:bg-orange-600 transition">
                            Start Translating
                        </a>
                        <a href="/admin" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-medium hover:bg-gray-300 transition">
                            Back to Dashboard
                        </a>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
