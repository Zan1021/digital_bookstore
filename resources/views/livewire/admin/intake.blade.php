<div class="max-w-5xl mx-auto" @if ($busy) wire:poll.3s @endif>
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Add books</h1>
    <p class="text-sm text-gray-500 mb-6">Drop one PDF or a whole folder of them. Each book is saved straight away and prepared in the background, so you can leave this page and come back.</p>

    {{-- Drop zone and per-file sending progress (runs in the browser) --}}
    <section
        x-data="{
            maxBytes: {{ $maxMb }} * 1024 * 1024,
            items: [],
            dragging: false,
            sending: false,
            add(fileList) {
                for (const file of fileList) {
                    this.items.push({ key: Date.now() + '-' + Math.random(), name: file.name, size: file.size, file, progress: 0, state: 'waiting', error: null });
                }
                this.send();
            },
            async send() {
                if (this.sending) return;
                this.sending = true;
                for (const item of this.items) {
                    if (item.state !== 'waiting') continue;
                    if (! item.name.toLowerCase().endsWith('.pdf')) { item.state = 'error'; item.error = 'Only PDF files can be uploaded.'; continue; }
                    if (item.size > this.maxBytes) { item.state = 'error'; item.error = 'This file is larger than {{ $maxMb }} MB.'; continue; }
                    item.state = 'sending';
                    try {
                        await new Promise((resolve, reject) => this.$wire.upload('upload', item.file, resolve,
                            () => reject(new Error('The upload did not finish. Only PDF files up to {{ $maxMb }} MB can be uploaded; keep this page open while files are sending.')),
                            (event) => { item.progress = event.detail.progress; }));
                        const result = await this.$wire.finishUpload(item.name, item.addAnyway === true);
                        if (result.ok) { item.state = 'done'; item.progress = 100; }
                        else if (result.duplicate) { item.state = 'duplicate'; item.error = result.error; item.existingUrl = result.existing_url; }
                        else { item.state = 'error'; item.error = result.error; }
                    } catch (e) {
                        item.state = 'error'; item.error = e.message;
                    }
                    if (item.state !== 'duplicate') item.file = null; // kept so 'Add anyway' can send it again
                }
                this.sending = false;
                if (this.items.some(i => i.state === 'waiting')) this.send();
            },
            addAnyway(item) { item.addAnyway = true; item.state = 'waiting'; item.error = null; item.progress = 0; this.send(); },
            clearFinished() { this.items = this.items.filter(i => i.state === 'waiting' || i.state === 'sending'); },
        }"
        @beforeunload.window="if (sending) { $event.preventDefault(); $event.returnValue = ''; }"
        class="mb-8"
    >
        <label
            for="intake-files"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="dragging = false; add($event.dataTransfer.files)"
            :class="dragging ? 'border-brand-500 bg-brand-50' : 'border-gray-300 bg-white hover:border-brand-400'"
            class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-14 text-center cursor-pointer transition"
        >
            <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <span class="text-base font-medium text-gray-700">Drop PDF files here, or click to choose</span>
            <span class="text-sm text-gray-400 mt-1">PDF only, up to {{ $maxMb }} MB each</span>
            <input id="intake-files" type="file" accept=".pdf,application/pdf" multiple class="sr-only"
                   @change="add($event.target.files); $event.target.value = ''">
        </label>

        <template x-if="items.length">
            <div class="mt-4 bg-white rounded-xl border border-gray-200 divide-y divide-gray-100" aria-live="polite">
                <template x-for="item in items" :key="item.key">
                    <div class="px-4 py-3 flex items-center gap-4 text-sm">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-gray-900" x-text="item.name"></div>
                            <div x-show="item.error" class="text-xs mt-0.5" :class="item.state === 'duplicate' ? 'text-amber-700' : 'text-red-700'" x-text="item.error"></div>
                            <div x-show="item.state === 'duplicate'" class="mt-1 flex gap-4 text-xs">
                                <a :href="item.existingUrl" class="font-medium text-brand-700 hover:underline">Open the existing book</a>
                                <button type="button" @click="addAnyway(item)" class="text-gray-600 hover:text-gray-900 underline">Add it anyway</button>
                            </div>
                        </div>
                        <div class="w-40 h-2 rounded-full bg-gray-100 overflow-hidden" x-show="item.state === 'sending' || item.state === 'done'">
                            <div class="h-full bg-brand-500 transition-all" :style="`width: ${item.progress}%`"></div>
                        </div>
                        <span class="w-28 text-right text-xs"
                              :class="{ 'text-gray-400': item.state === 'waiting', 'text-brand-700': item.state === 'sending', 'text-green-700': item.state === 'done', 'text-red-700': item.state === 'error', 'text-amber-700': item.state === 'duplicate' }"
                              x-text="{ waiting: 'Waiting', sending: 'Sending ' + item.progress + '%', done: 'Saved', error: 'Not uploaded', duplicate: 'Already added' }[item.state]"></span>
                    </div>
                </template>
                <div class="px-4 py-2 text-right" x-show="! sending">
                    <button type="button" @click="clearFinished()" class="text-xs text-gray-500 hover:text-gray-700">Clear this list</button>
                </div>
            </div>
        </template>
    </section>

    {{-- Server-side progress: survives leaving the page --}}
    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-4 pb-2">
            <h2 class="font-semibold text-gray-800">Your uploads</h2>
            @if ($busy)
                <span class="text-xs text-gray-500">Updating…</span>
            @endif
        </div>
        @if ($rows->isEmpty())
            <p class="px-5 pb-5 text-sm text-gray-500">Nothing uploaded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[40rem]">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Book</th>
                            <th class="px-5 py-2 font-medium text-right">Pages</th>
                            <th class="px-5 py-2 font-medium">Status</th>
                            <th class="px-5 py-2 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $row)
                            @php($file = $row->details['file'] ?? null)
                            <tr wire:key="intake-{{ $row->id }}">
                                <td class="px-5 py-3">
                                    <div class="text-gray-900">{{ $row->book?->title ?? 'Deleted book' }}</div>
                                    @if ($file)
                                        <div class="text-xs text-gray-400">{{ $file }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-gray-600">{{ $row->book?->page_count ?: '—' }}</td>
                                <td class="px-5 py-3">
                                    @switch($row->status)
                                        @case('queued')
                                            <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">Waiting</span>
                                            @break
                                        @case('processing')
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs px-2 py-0.5 rounded bg-sky-500/10 text-sky-700">Preparing</span>
                                                <div class="w-24 h-1.5 rounded-full bg-gray-100 overflow-hidden" role="progressbar" aria-valuenow="{{ $row->progress }}" aria-valuemin="0" aria-valuemax="100">
                                                    <div class="h-full bg-sky-500" style="width: {{ $row->progress }}%"></div>
                                                </div>
                                            </div>
                                            @break
                                        @case('completed')
                                            <span class="text-xs px-2 py-0.5 rounded bg-green-50 text-green-700">Ready</span>
                                            @foreach ($row->details['notes'] ?? [] as $note)
                                                <div class="text-xs text-gray-500 mt-1 max-w-sm">{{ $note }}</div>
                                            @endforeach
                                            @break
                                        @case('rejected')
                                            <span class="text-xs px-2 py-0.5 rounded bg-red-50 text-red-700">Refused</span>
                                            <div class="text-xs text-red-700 mt-1 max-w-sm">{{ $row->error_message }}</div>
                                            <div class="text-xs text-gray-500 mt-0.5">The file was not kept.</div>
                                            @break
                                        @default
                                            <span class="text-xs px-2 py-0.5 rounded bg-red-50 text-red-700">Failed</span>
                                            @if ($row->error_message)
                                                <div class="text-xs text-red-700 mt-1 max-w-sm">{{ $row->error_message }}</div>
                                            @endif
                                    @endswitch
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    @if ($row->status === 'failed')
                                        <button type="button" wire:click="retry({{ $row->book_id }})" class="text-xs font-medium text-brand-700 hover:underline mr-3">Retry</button>
                                    @endif
                                    @if ($row->book && $row->status === 'completed')
                                        <a href="{{ route('admin.book', $row->book) }}" class="text-xs font-medium text-brand-700 hover:underline mr-3">Open book</a>
                                    @endif
                                    @if (in_array($row->status, ['completed', 'failed', 'rejected'], true))
                                        <button type="button" wire:click="dismiss({{ $row->book_id }})" class="text-xs text-gray-400 hover:text-gray-600" aria-label="Remove {{ $row->book?->title }} from this list">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <p class="text-xs text-gray-400 mt-3">Translation, narration and fonts are chosen on each book's page once it is ready.</p>
</div>
