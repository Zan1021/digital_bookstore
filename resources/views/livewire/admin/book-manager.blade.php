<div>
    {{-- Flash messages --}}
    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">{{ $book->title }}</h1>
            <p class="text-gray-500 mt-1">{{ $book->sku }} &middot; {{ $book->page_count }} pages</p>
        </div>
        <a href="{{ route('flipbook', $book) }}" target="_blank"
           class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
            View Flipbook
        </a>
    </div>

    {{-- Tabs --}}
    <div class="flex space-x-1 bg-gray-100 rounded-xl p-1 mb-6">
        @foreach(['overview' => 'Overview', 'text' => 'Extracted Text', 'translate' => 'Translate', 'narrate' => 'Narrate', 'settings' => 'Settings'] as $tab => $label)
            <button wire:click="switchTab('{{ $tab }}')"
                    class="flex-1 py-2 px-4 rounded-lg text-sm font-medium transition
                        {{ $activeTab === $tab ? 'bg-white text-brand-600 shadow-sm' : 'text-gray-600 hover:text-gray-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'overview')
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Book Info --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-800 mb-4">Book Information</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">Title</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $book->title }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">Author</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $book->author ?? 'Unknown' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">Pages</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $book->page_count }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">Language</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $book->original_language }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">Category</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $book->category ?? 'Unset' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">Age Group</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $book->age_group ?? 'Unset' }}</dd>
                    </div>
                </dl>

                <div class="mt-6 pt-4 border-t">
                    <button wire:click="suggestMetadata" wire:loading.attr="disabled"
                            class="w-full bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 transition">
                        <span wire:loading.remove wire:target="suggestMetadata">AI Suggest Metadata</span>
                        <span wire:loading wire:target="suggestMetadata">Analysing...</span>
                    </button>
                </div>

                @if($suggestedMetadata)
                    <div class="mt-4 bg-purple-50 rounded-lg p-4 border border-purple-200">
                        <h4 class="text-sm font-semibold text-purple-800 mb-2">AI Suggestions</h4>
                        <dl class="space-y-1 text-sm">
                            @foreach($suggestedMetadata as $key => $value)
                                <div>
                                    <dt class="inline text-purple-600">{{ ucfirst(str_replace('_', ' ', $key)) }}:</dt>
                                    <dd class="inline text-gray-700">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <button wire:click="applyMetadata" class="mt-3 bg-purple-600 text-white px-3 py-1 rounded text-xs hover:bg-purple-700">
                            Apply Suggestions
                        </button>
                    </div>
                @endif
            </div>

            {{-- Languages & Narrations Status --}}
            <div class="space-y-6">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="font-semibold text-gray-800 mb-4">Translations</h3>
                    @if($book->translations->isEmpty())
                        <p class="text-gray-400 text-sm">No translations yet. Go to the Translate tab to get started.</p>
                    @else
                        <div class="space-y-2">
                            @foreach($book->translations as $translation)
                                <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                                    <span class="text-sm font-medium">{{ $translation->language_name }}</span>
                                    <span class="text-xs px-2 py-1 rounded-full
                                        {{ $translation->status === 'draft' ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700' }}">
                                        {{ ucfirst($translation->status) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="font-semibold text-gray-800 mb-4">Narrations</h3>
                    @if($book->narrations->isEmpty())
                        <p class="text-gray-400 text-sm">No narrations yet. Go to the Narrate tab to generate audio.</p>
                    @else
                        <div class="space-y-2">
                            @foreach($book->narrations as $narration)
                                <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                                    <div>
                                        <span class="text-sm font-medium">{{ $narration->language_name }}</span>
                                        <span class="text-xs text-gray-500 ml-1">({{ $narration->voice_name }})</span>
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded-full
                                        {{ $narration->status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                        {{ ucfirst($narration->status) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

    @elseif($activeTab === 'text')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Extracted Text by Page</h3>
            @if($book->pages->filter(fn($p) => !empty($p->extracted_text))->isEmpty())
                <p class="text-gray-400">No text was extracted from this PDF. It may be image-only (OCR needed for production).</p>
            @else
                <div class="space-y-4">
                    @foreach($book->pages as $page)
                        @if(!empty($page->extracted_text))
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="text-xs font-medium text-brand-500 mb-2">Page {{ $page->page_number }}</div>
                                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $page->extracted_text }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

    @elseif($activeTab === 'translate')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Generate Translation</h3>
            <p class="text-sm text-gray-500 mb-6">Select a target language to translate the book using AI. The translation will be stored as a draft for review.</p>

            {{-- Cost Estimate --}}
            @php
                $storyText = $book->pages->pluck('extracted_text')->filter()->implode(' ');
                $wordCount = str_word_count($storyText);
                $charCount = mb_strlen($storyText);
                // GPT-4o-mini: ~$0.15 per 1M input tokens + $0.60 per 1M output tokens
                // ~4 chars per token, we send context + get translation back
                $estTokensIn = ($charCount / 4) * 2; // context multiplier
                $estTokensOut = $charCount / 4;
                $estCostUsd = ($estTokensIn * 0.15 / 1000000) + ($estTokensOut * 0.60 / 1000000);
                $estCostZar = $estCostUsd * 18.5;
            @endphp
            <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-blue-800">📊 Estimated Translation Cost</p>
                        <p class="text-xs text-blue-600 mt-1">{{ number_format($wordCount) }} words · {{ number_format($charCount) }} characters · GPT-4o-mini</p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold text-blue-800">~R{{ number_format($estCostZar, 2) }}</p>
                        <p class="text-xs text-blue-500">(~${{ number_format($estCostUsd, 3) }})</p>
                    </div>
                </div>
            </div>

            <div class="flex items-end space-x-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Target Language</label>
                    <select wire:model.live="selectedLanguage" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-brand-500 focus:border-brand-500">
                        <option value="">Select language...</option>
                        @foreach($languages as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <button wire:click="translate" wire:loading.attr="disabled"
                        class="bg-brand-500 text-white px-6 py-2 rounded-lg text-sm hover:bg-brand-600 transition disabled:opacity-50"
                        @if(empty($selectedLanguage)) disabled @endif>
                    <span wire:loading.remove wire:target="translate">Generate Translation</span>
                    <span wire:loading wire:target="translate" class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Translating...
                    </span>
                </button>
            </div>

            {{-- Existing Translations --}}
            @if($book->translations->isNotEmpty())
                <div class="mt-8 pt-6 border-t">
                    <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                        <p class="text-sm text-green-700 font-medium">✅ Translation complete! View the translated book below.</p>
                    </div>
                    <h4 class="font-medium text-gray-700 mb-4">Completed Translations</h4>
                    @foreach($book->translations as $translation)
                        <div class="mb-6 border border-gray-200 rounded-xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h5 class="font-medium text-brand-600">{{ $translation->language_name }}</h5>
                                <div class="flex items-center gap-3">
                                    @php
                                        $transChars = $translation->translatedPages->sum(fn($tp) => mb_strlen($tp->translated_text ?? ''));
                                        $transTokensEst = $transChars / 4;
                                        $transCostUsd = ($transTokensEst * 2 * 0.15 / 1000000) + ($transTokensEst * 0.60 / 1000000);
                                        $transCostZar = $transCostUsd * 18.5;
                                    @endphp
                                    <span class="text-xs text-green-600 font-medium bg-green-50 px-2 py-1 rounded">~R{{ number_format($transCostZar, 2) }}</span>
                                    <span class="text-xs text-gray-500">{{ $translation->translatedPages->count() }} pages</span>
                                    <a href="{{ route('flipbook', ['book' => $book->id, 'lang' => $translation->language_code]) }}" target="_blank"
                                       class="inline-flex items-center gap-1 text-xs bg-brand-500 text-white px-3 py-1.5 rounded-lg hover:bg-brand-600 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                        </svg>
                                        View Flipbook
                                    </a>
                                    <a href="{{ route('admin.review', ['book' => $book->id, 'language' => $translation->language_code]) }}" target="_blank"
                                       class="inline-flex items-center gap-1 text-xs bg-purple-500 text-white px-3 py-1.5 rounded-lg hover:bg-purple-600 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        Review
                                    </a>
                                    <button wire:click="renderPdf({{ $translation->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="renderPdf({{ $translation->id }})"
                                            class="inline-flex items-center gap-1 text-xs bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700 transition disabled:opacity-50">
                                        <span wire:loading.remove wire:target="renderPdf({{ $translation->id }})">
                                            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            Render PDF
                                        </span>
                                        <span wire:loading wire:target="renderPdf({{ $translation->id }})" class="flex items-center">
                                            <svg class="animate-spin w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                            Rendering...
                                        </span>
                                    </button>
                                    <button wire:click="deleteTranslation({{ $translation->id }})"
                                            wire:confirm="Delete {{ $translation->language_name }} translation? This cannot be undone."
                                            class="inline-flex items-center gap-1 text-xs bg-red-100 text-red-600 px-3 py-1.5 rounded-lg hover:bg-red-200 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                        Delete
                                    </button>
                                </div>
                            </div>
                            {{-- Rendered PDF download --}}
                            @if($translation->rendered_pdf_path ?? null)
                                <div class="mb-3 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="text-sm text-green-700 font-medium">Rendered PDF ready</span>
                                    </div>
                                    <a href="{{ asset('storage/' . $translation->rendered_pdf_path) }}" target="_blank"
                                       class="text-xs bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700 transition">
                                        📥 Download / View PDF
                                    </a>
                                </div>
                            @endif
                            <div class="space-y-3 max-h-60 overflow-y-auto">
                                @foreach($translation->translatedPages as $tp)
                                    <div class="bg-gray-50 rounded-lg p-3">
                                        <div class="text-xs font-medium text-brand-500 mb-1">Page {{ $tp->page_number }}</div>
                                        <p class="text-sm text-gray-700">{{ $tp->translated_text }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    @elseif($activeTab === 'narrate')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Generate Narration</h3>
            <p class="text-sm text-gray-500 mb-6">Generate natural-sounding narration using ElevenLabs AI voices. Select a language and voice to get started.</p>

            {{-- Cost Estimate --}}
            @php
                $narrationText = $book->pages->pluck('extracted_text')->filter()->implode(' ');
                $narCharCount = mb_strlen($narrationText);
                // ElevenLabs Scale tier: ~$0.30 per 1,000 characters
                $narCostUsd = ($narCharCount / 1000) * 0.30;
                $narCostZar = $narCostUsd * 18.5;
                // Narration pages (story pages only, skip first 2 + last 2)
                $narPages = max(0, $book->page_count - 4);
            @endphp
            <div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-amber-800">🎙️ Estimated Narration Cost</p>
                        <p class="text-xs text-amber-600 mt-1">{{ number_format($narCharCount) }} characters · ~{{ $narPages }} story pages · ElevenLabs TTS</p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold text-amber-800">~R{{ number_format($narCostZar, 2) }}</p>
                        <p class="text-xs text-amber-500">(~${{ number_format($narCostUsd, 2) }})</p>
                    </div>
                </div>
                <p class="text-xs text-amber-600 mt-2">⚠️ Narration is the most expensive operation. Cost is per language.</p>
            </div>

            @if(empty($availableVoices))
                <button wire:click="loadVoices" wire:loading.attr="disabled"
                        class="bg-gray-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-700 transition mb-6">
                    <span wire:loading.remove wire:target="loadVoices">Load Available Voices</span>
                    <span wire:loading wire:target="loadVoices">Fetching voices...</span>
                </button>
            @endif

            @if(!empty($availableVoices))
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Language</label>
                        <select wire:model="selectedLanguage" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select language...</option>
                            <option value="en">English (Original)</option>
                            @foreach($book->translations as $t)
                                <option value="{{ $t->language_code }}">{{ $t->language_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Voice</label>
                        <select wire:model="selectedVoice" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select voice...</option>
                            @foreach($availableVoices as $voice)
                                <option value="{{ $voice['voice_id'] }}">{{ $voice['name'] }} ({{ $voice['category'] }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button wire:click="narrate" wire:loading.attr="disabled"
                                class="w-full bg-brand-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-brand-600 transition disabled:opacity-50"
                                @if(empty($selectedVoice) || empty($selectedLanguage)) disabled @endif>
                            <span wire:loading.remove wire:target="narrate">Generate Narration</span>
                            <span wire:loading wire:target="narrate" class="flex items-center justify-center">
                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Generating...
                            </span>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Existing Narrations --}}
            @if($book->narrations->isNotEmpty())
                <div class="mt-8 pt-6 border-t">
                    <h4 class="font-medium text-gray-700 mb-4">Generated Narrations</h4>
                    @foreach($book->narrations as $narration)
                        @if($narration->status === 'completed')
                            <div class="mb-4 border border-gray-200 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <h5 class="font-medium text-gray-800">{{ $narration->language_name }}</h5>
                                        <p class="text-xs text-gray-500">Voice: {{ $narration->voice_name }}</p>
                                    </div>
                                    <button wire:click="deleteNarration({{ $narration->id }})"
                                            wire:confirm="Delete {{ $narration->language_name }} narration? Audio files will be permanently removed."
                                            class="inline-flex items-center gap-1 text-xs bg-red-100 text-red-600 px-3 py-1.5 rounded-lg hover:bg-red-200 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                        Delete
                                    </button>
                                </div>
                                @if($narration->audio_path)
                                    <audio controls class="w-full mt-2">
                                        <source src="{{ asset('storage/' . $narration->audio_path) }}" type="audio/mpeg">
                                    </audio>
                                @endif
                                @if($narration->page_audio_paths)
                                    <details class="mt-3">
                                        <summary class="text-sm text-brand-500 cursor-pointer">Page-by-page audio</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach($narration->page_audio_paths as $pageNum => $audioPath)
                                                <div class="flex items-center space-x-3 bg-gray-50 rounded-lg p-2">
                                                    <span class="text-xs font-medium text-gray-500 w-16">Page {{ $pageNum }}</span>
                                                    <audio controls class="flex-1 h-8">
                                                        <source src="{{ asset('storage/' . $audioPath) }}" type="audio/mpeg">
                                                    </audio>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

    @elseif($activeTab === 'settings')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-800 mb-6">Reading & Display Settings</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                {{-- Narration Settings --}}
                <div>
                    <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                        </svg>
                        Narration Range
                    </h4>
                    <p class="text-sm text-gray-500 mb-4">Set which pages the narrator reads. Skip cover, publisher info, and back matter.</p>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Start narrating from page</label>
                            <input type="number" wire:model="narrationStartPage" min="1" max="{{ $book->page_count }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-400 mt-1">Pages before this are silent (cover, publisher info, etc.)</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Stop narrating at page (leave empty for auto)</label>
                            <input type="number" wire:model="narrationEndPage" min="1" max="{{ $book->page_count }}"
                                   placeholder="Auto: page {{ $book->page_count - 1 }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-400 mt-1">Last page is skipped by default (back cover)</p>
                        </div>
                    </div>

                    <div class="mt-4 p-3 bg-brand-50 rounded-lg">
                        <p class="text-xs text-brand-600">
                            <strong>Narration active:</strong> Pages {{ $narrationStartPage }} – {{ $narrationEndPage ?? $book->page_count - 1 }} of {{ $book->page_count }}
                        </p>
                    </div>
                </div>

                {{-- Crop Settings --}}
                <div>
                    <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Crop & Display
                    </h4>
                    <p class="text-sm text-gray-500 mb-4">Remove print marks, trim lines, and extra margins from the PDF.</p>

                    <div class="space-y-4">
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" wire:model="cropEnabled"
                                   class="w-4 h-4 text-brand-500 border-gray-300 rounded focus:ring-brand-500">
                            <div>
                                <span class="text-sm font-medium text-gray-700">Crop to content</span>
                                <p class="text-xs text-gray-400">Removes outer margins and print/trim marks</p>
                            </div>
                        </label>

                        @if($cropEnabled)
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1">Crop amount (%)</label>
                                <input type="range" wire:model.live="cropPercent" min="1" max="10" step="1"
                                       class="w-full">
                                <div class="flex justify-between text-xs text-gray-400 mt-1">
                                    <span>Light (1%)</span>
                                    <span class="font-medium text-brand-500">{{ $cropPercent }}%</span>
                                    <span>Heavy (10%)</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t flex justify-end">
                <button wire:click="saveSettings"
                        class="bg-brand-500 text-white px-6 py-2 rounded-lg text-sm hover:bg-brand-600 transition">
                    Save Settings
                </button>
            </div>
        </div>
    @endif

    {{-- Celebration Overlay --}}
    <div id="celebration-overlay" class="fixed inset-0 z-[9999] pointer-events-none hidden">
        <style>
            @keyframes confetti-fall { 0% { transform: translateY(-100%) rotate(0deg); opacity: 1; } 100% { transform: translateY(100vh) rotate(720deg); opacity: 0; } }
            @keyframes pop-in { 0% { transform: scale(0) rotate(-10deg); opacity: 0; } 60% { transform: scale(1.15) rotate(2deg); } 100% { transform: scale(1) rotate(0); opacity: 1; } }
            @keyframes float-up { 0% { transform: translateY(20px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
            .confetti-p { position: absolute; width: 10px; height: 10px; opacity: 0; animation: confetti-fall 3s ease-out forwards; }
            .celebration-card { animation: pop-in 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
            .celebration-msg { animation: float-up 0.5s ease-out 0.3s both; }
        </style>
        <div class="absolute inset-0 bg-black/20 pointer-events-auto" onclick="dismissCelebration()"></div>
        <div class="absolute inset-0" id="confetti-container"></div>
        <div class="absolute inset-0 flex items-center justify-center">
            <div class="celebration-card bg-white rounded-2xl shadow-2xl border border-gray-100 p-8 max-w-md text-center pointer-events-auto">
                <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="text-4xl">🎉</span>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 celebration-msg" id="celebration-title">Amazing!</h2>
                <p class="text-gray-600 mt-2 celebration-msg" id="celebration-message"></p>
                <button onclick="dismissCelebration()" class="mt-6 bg-brand-500 text-white px-6 py-2.5 rounded-lg font-medium hover:bg-brand-600 transition celebration-msg">
                    Review Translation →
                </button>
            </div>
        </div>
    </div>

    <script>
        const celebrationTitles = [
            "Boom! 💥",
            "Magic happens! ✨",
            "Nailed it! 🎯",
            "That was fast! ⚡",
            "Publishing wizard! 🧙‍♂️",
            "Story unlocked! 📖",
            "Another language conquered! 🌍",
        ];

        function showCelebration(message) {
            const overlay = document.getElementById('celebration-overlay');
            const container = document.getElementById('confetti-container');
            const titleEl = document.getElementById('celebration-title');
            const msgEl = document.getElementById('celebration-message');

            titleEl.textContent = celebrationTitles[Math.floor(Math.random() * celebrationTitles.length)];
            msgEl.textContent = message;

            container.innerHTML = '';
            const colors = ['#fd5826','#22c55e','#eab308','#6366f1','#ec4899','#14b8a6','#f59e0b','#3b82f6'];
            for (let i = 0; i < 80; i++) {
                const piece = document.createElement('div');
                piece.className = 'confetti-p';
                piece.style.left = Math.random() * 100 + '%';
                piece.style.top = '-10px';
                piece.style.animationDelay = (i * 0.04) + 's';
                piece.style.background = colors[i % colors.length];
                piece.style.borderRadius = i % 3 === 0 ? '50%' : (i % 3 === 1 ? '2px' : '0');
                piece.style.width = (Math.random() * 10 + 6) + 'px';
                piece.style.height = (Math.random() * 10 + 6) + 'px';
                container.appendChild(piece);
            }

            overlay.classList.remove('hidden');
        }

        function dismissCelebration() {
            document.getElementById('celebration-overlay').classList.add('hidden');
            // Navigate to review page if a translation was just completed
            if (window._celebrationRedirect) {
                window.location.href = window._celebrationRedirect;
                window._celebrationRedirect = null;
            }
        }

        document.addEventListener('livewire:initialized', () => {
            Livewire.on('celebration', (data) => {
                window._celebrationRedirect = data.redirect || null;
                showCelebration(data.message || 'Operation complete!');
            });
        });
    </script>
</div>

