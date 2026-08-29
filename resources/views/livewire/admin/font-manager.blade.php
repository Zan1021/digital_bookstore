<div class="max-w-6xl mx-auto px-4 py-8">
    <div class="mb-6">
        <a href="{{ route('admin.dashboard') }}" class="text-blue-600 hover:underline">&larr; Back to Dashboard</a>
    </div>

    <h1 class="text-2xl font-bold mb-2">Font Manager: {{ $book->title }}</h1>
    <p class="text-gray-600 mb-6">View source fonts, resolved mappings, and upload custom fonts.</p>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 text-green-800 rounded-lg">{{ session('success') }}</div>
    @endif

    {{-- Source Fonts (from PDF) --}}
    <div class="mb-8">
        <h2 class="text-lg font-semibold mb-3">Source PDF Fonts</h2>
        @if(count($sourceFonts) > 0)
            <div class="border rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Font Name</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Encoding</th>
                            <th class="px-4 py-2 text-center">Embedded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sourceFonts as $font)
                            <tr class="border-t">
                                <td class="px-4 py-2 font-mono">{{ $font['name'] }}</td>
                                <td class="px-4 py-2">{{ $font['type'] }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $font['encoding'] }}</td>
                                <td class="px-4 py-2 text-center">
                                    @if($font['embedded'])
                                        <span class="text-green-600">✅</span>
                                    @else
                                        <span class="text-red-600">❌</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-400">No font data available. Run font verification first.</p>
        @endif
    </div>

    {{-- Font Mappings (source → target) --}}
    <div class="mb-8">
        <h2 class="text-lg font-semibold mb-3">Font Resolution Mappings</h2>
        @if(count($resolvedFonts) > 0)
            <div class="border rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">PDF Font</th>
                            <th class="px-4 py-2 text-center">→</th>
                            <th class="px-4 py-2 text-left">Resolved To</th>
                            <th class="px-4 py-2 text-left">Source</th>
                            <th class="px-4 py-2 text-center">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($resolvedFonts as $mapping)
                            <tr class="border-t">
                                <td class="px-4 py-2 font-mono text-xs">{{ $mapping['pdf_font'] ?? 'Unknown' }}</td>
                                <td class="px-4 py-2 text-center text-gray-400">→</td>
                                <td class="px-4 py-2 font-mono text-xs text-brand-600">{{ $mapping['matched_family'] ?? 'None' }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $mapping['source'] ?? 'unknown' }}</td>
                                <td class="px-4 py-2 text-center">
                                    @if(!empty($mapping['path']))
                                        <span class="text-green-600">✅</span>
                                    @else
                                        <span class="text-red-600">❌ Missing</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-400">No font mappings available.</p>
        @endif
    </div>

    {{-- Available Local Fonts --}}
    <div class="mb-8">
        <h2 class="text-lg font-semibold mb-3">Available Fonts ({{ count($availableFonts) }})</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            @foreach($availableFonts as $font)
                <div class="border rounded-lg p-3 text-sm">
                    <p class="font-mono font-semibold truncate">{{ $font['filename'] }}</p>
                    <p class="text-gray-400 text-xs">{{ $font['size'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Upload Font --}}
    <div class="border rounded-lg p-6 bg-gray-50">
        <h2 class="text-lg font-semibold mb-3">Upload Custom Font</h2>
        <p class="text-gray-600 text-sm mb-4">Upload .ttf or .otf font files for use in translations.</p>

        <div class="flex items-center gap-4">
            <input type="file" wire:model="fontUpload" accept=".ttf,.otf"
                   class="text-sm border rounded-lg px-3 py-2">
            <button wire:click="uploadFont" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-brand-600 text-white rounded-lg text-sm hover:bg-brand-700 disabled:opacity-50"
                    {{ !$fontUpload ? 'disabled' : '' }}>
                Upload
            </button>
        </div>
        @error('fontUpload')
            <p class="text-red-600 text-sm mt-2">{{ $message }}</p>
        @enderror
    </div>
</div>
