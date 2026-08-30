<div class="max-w-4xl mx-auto p-4">
    <h1 class="text-xl font-bold mb-1">Review Book Details</h1>
    <p class="text-sm text-gray-500 mb-4">{{ $book->title }} — classification status:
        <strong>{{ $book->classification_status }}</strong></p>

    @if (session('success'))
        <div class="mb-3 p-2 rounded bg-green-50 text-green-700 text-sm">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-3 p-2 rounded bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif

    {{-- Publish gate status --}}
    <div class="mb-4 p-3 rounded border {{ $canPublish ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50' }}">
        @if ($canPublish)
            <span class="text-green-700 font-semibold">Ready to publish — all required fields present.</span>
        @else
            <span class="text-amber-700 font-semibold">Not publishable yet.</span>
            <span class="text-sm text-amber-700">Missing: {{ implode(', ', $missing) }}</span>
        @endif
    </div>

    {{-- 2. Category & book type --}}
    <section class="mb-5">
        <h2 class="font-semibold text-gray-700 mb-2">Category &amp; Book Type</h2>
        <label class="block text-sm mb-1">Book type</label>
        <select wire:model="bookType" class="border rounded p-2 text-sm w-full mb-3">
            <option value="">— select —</option>
            @foreach (['picture_book','early_reader','storybook','chapter_book','workbook','textbook','activity_book','comic','poetry','reference'] as $bt)
                <option value="{{ $bt }}">{{ ucwords(str_replace('_',' ',$bt)) }}</option>
            @endforeach
        </select>

        <label class="block text-sm mb-1">Primary category</label>
        <select wire:model="primaryCategory" class="border rounded p-2 text-sm w-full">
            <option value="">— select —</option>
            @foreach ($categories as $c)
                <option value="{{ $c->slug }}">{{ $c->label() }}</option>
            @endforeach
        </select>
    </section>

    {{-- 3. Audience & reading level --}}
    <section class="mb-5">
        <h2 class="font-semibold text-gray-700 mb-2">Audience &amp; Reading Level</h2>
        <div class="flex items-center gap-2 text-sm">
            <span>Age</span>
            <input type="number" wire:model="ageMin" class="border rounded p-1 w-16" min="0" max="18">
            <span>to</span>
            <input type="number" wire:model="ageMax" class="border rounded p-1 w-16" min="0" max="18">
            <span class="text-xs text-amber-600">(requires confirmation — safeguarding)</span>
        </div>
    </section>

    {{-- 4. Tags --}}
    <section class="mb-5">
        <h2 class="font-semibold text-gray-700 mb-2">Tags</h2>
        <div class="grid grid-cols-3 gap-1 max-h-56 overflow-auto border rounded p-2 text-sm">
            @foreach ($allTags as $t)
                <label class="inline-flex items-center gap-1">
                    <input type="checkbox" wire:model="selectedTagIds" value="{{ $t->id }}">
                    <span>{{ $t->label() }}</span>
                </label>
            @endforeach
        </div>
        @if ($pendingTags->isNotEmpty())
            <p class="mt-2 text-xs text-amber-700">
                Pending tags awaiting approval (not selectable until approved):
                {{ $pendingTags->pluck('canonical_name')->implode(', ') }}
            </p>
        @endif
    </section>

    {{-- 7. Description --}}
    <section class="mb-5">
        <h2 class="font-semibold text-gray-700 mb-2">Store Description</h2>
        @if ($description)
            <p class="text-xs text-gray-500 mb-1">Status: <strong>{{ $description->status }}</strong> · source: {{ $description->source }}</p>
            <p class="text-sm border rounded p-2 bg-gray-50">{{ $description->short_text }}</p>
            @if ($description->status !== 'approved')
                <button wire:click="approveDescription" class="mt-2 px-3 py-1 text-xs rounded bg-emerald-600 text-white">Approve description</button>
            @endif
        @else
            <p class="text-sm text-gray-400">No description drafted yet.</p>
        @endif
    </section>

    <div class="flex gap-2">
        <button wire:click="save" class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 text-sm">Save draft</button>
        <button wire:click="publish" @disabled(!$canPublish)
            class="px-4 py-2 rounded text-sm text-white {{ $canPublish ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-gray-400 cursor-not-allowed' }}">
            Publish
        </button>
    </div>
</div>
