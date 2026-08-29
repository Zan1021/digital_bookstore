<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $book->title }} — StoryBooks</title>
    <script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={theme:{extend:{colors:{brand:{50:"#fff7f5",100:"#ffede8",200:"#ffd5cc",300:"#ffb3a3",400:"#ff8468",500:"#fd5826",600:"#e84a1c",700:"#c23a14",800:"#9d3114",900:"#7d2b15"}}}}}</script>
</head>
<body class="bg-white">
    {{-- Navigation --}}
    <nav class="bg-white border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ route('store') }}" class="text-2xl font-bold text-brand-600 flex items-center">
                <img src="{{ asset('images/logo.png') }}" alt="StoryBooks" class="h-10">
            </a>
            <div class="flex items-center space-x-6">
                <a href="{{ route('store') }}" class="text-sm text-gray-600 hover:text-brand-500">← All Books</a>
            </div>
        </div>
    </nav>

    {{-- Product Page --}}
    <div class="max-w-6xl mx-auto px-4 py-10">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-start">
            {{-- Book Cover — fixed size, cropped, no stretch --}}
            <div class="flex justify-center items-start">
                <div class="w-[450px] rounded-2xl overflow-hidden shadow-2xl flex-shrink-0 border border-gray-100">
                    <canvas class="pdf-cover w-full" style="display:block;" data-pdf="{{ asset('storage/' . $book->pdf_path) }}"></canvas>
                </div>
            </div>

            {{-- Book Info --}}
            <div>
                <h1 class="text-3xl font-bold text-gray-800">{{ $book->title }}</h1>
                @if($book->author)
                    <p class="text-gray-500 mt-1">by {{ $book->author }}</p>
                @endif

                {{-- Description under title --}}
                <p class="text-sm text-gray-500 mt-3 leading-relaxed">
                    {{ $book->description ?? 'A wonderful children\'s story with beautiful illustrations, available as an interactive digital flipbook with professional AI narration in multiple languages.' }}
                </p>

                <div class="flex items-center gap-4 mt-5">
                    <span class="text-3xl font-bold text-brand-500">R89</span>
                    <span class="text-sm text-gray-400 line-through">R129</span>
                    <span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full font-medium">31% off</span>
                </div>

                {{-- Preview button — small, under price --}}
                <a href="{{ route('flipbook', $book) }}" class="inline-block mt-3 text-xs text-brand-500 hover:text-brand-700 font-medium">
                    ▶ Preview Flipbook
                </a>

                {{-- Features --}}
                <div class="flex flex-wrap gap-2 mt-5">
                    <span class="bg-purple-100 text-purple-700 text-xs px-3 py-1 rounded-full">📖 {{ $book->page_count }} Pages</span>
                    @if($book->narrations->where('status', 'completed')->count() > 0)
                        <span class="bg-brand-100 text-brand-600 text-xs px-3 py-1 rounded-full">🔊 Audio Narration</span>
                    @endif
                    @if($book->translations->count() > 0)
                        <span class="bg-blue-100 text-blue-700 text-xs px-3 py-1 rounded-full">🌍 {{ $book->translations->count() + 1 }} Languages</span>
                    @endif
                    <span class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full">✨ Interactive</span>
                </div>

                {{-- Languages --}}
                <div class="mt-6">
                    <h3 class="text-sm font-medium text-gray-700 mb-2">Available in:</h3>
                    <div class="flex gap-2">
                        <span class="border border-brand-300 bg-brand-50 text-brand-600 text-xs px-3 py-1.5 rounded-lg font-medium">English</span>
                        @foreach($book->translations as $t)
                            <span class="border border-gray-200 text-gray-600 text-xs px-3 py-1.5 rounded-lg">{{ $t->language_name }}</span>
                        @endforeach
                    </div>
                </div>

                {{-- Formats --}}
                <div class="mt-6">
                    <h3 class="text-sm font-medium text-gray-700 mb-2">Formats:</h3>
                    <div class="space-y-2">
                        <div class="flex items-center justify-between p-3 border border-brand-200 bg-brand-50 rounded-lg">
                            <div class="flex items-center gap-2">
                                <span>📱</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">Interactive Flipbook</span>
                                    <span class="text-[10px] text-gray-400 block">Read online with page-turn effects</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R89</span>
                        </div>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:border-brand-200 transition">
                            <div class="flex items-center gap-2">
                                <span>🔊</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">Flipbook + Audio Narration</span>
                                    <span class="text-[10px] text-gray-400 block">Interactive reading with AI storyteller</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R129</span>
                        </div>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:border-brand-200 transition">
                            <div class="flex items-center gap-2">
                                <span>📄</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">PDF Download</span>
                                    <span class="text-[10px] text-gray-400 block">Download & print at home</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R69</span>
                        </div>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:border-brand-200 transition">
                            <div class="flex items-center gap-2">
                                <span>📚</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">EPUB Download</span>
                                    <span class="text-[10px] text-gray-400 block">Read on Kindle, tablets & e-readers</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R79</span>
                        </div>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:border-brand-200 transition">
                            <div class="flex items-center gap-2">
                                <span>🎧</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">Audiobook (MP3)</span>
                                    <span class="text-[10px] text-gray-400 block">Listen anywhere, offline</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R99</span>
                        </div>

                        <div class="border-t border-gray-100 pt-2 mt-2">
                            <p class="text-[10px] text-gray-400 uppercase tracking-wider font-medium mb-2">Physical</p>
                        </div>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:border-brand-200 transition">
                            <div class="flex items-center gap-2">
                                <span>📖</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">Paperback</span>
                                    <span class="text-[10px] text-gray-400 block">Soft cover, full colour print</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R199</span>
                        </div>
                        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:border-brand-200 transition">
                            <div class="flex items-center gap-2">
                                <span>📕</span>
                                <div>
                                    <span class="text-sm text-gray-700 font-medium">Hardcover</span>
                                    <span class="text-[10px] text-gray-400 block">Premium hard cover, gift quality</span>
                                </div>
                            </div>
                            <span class="text-sm font-bold text-gray-800">R299</span>
                        </div>
                    </div>
                </div>

                {{-- CTA --}}
                <div class="mt-6 space-y-3">
                    <button class="w-full bg-brand-500 text-white py-3 rounded-xl font-medium text-lg hover:bg-brand-600 transition shadow-lg shadow-brand-200">
                        Add to Cart — R89
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- PDF.js for cover — crops to TrimBox --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        document.querySelectorAll('.pdf-cover').forEach(async (canvas) => {
            const pdfUrl = canvas.dataset.pdf;
            if (!pdfUrl) return;
            try {
                const pdf = await pdfjsLib.getDocument(pdfUrl).promise;
                const page = await pdf.getPage(1);
                const vp = page.getViewport({ scale: 1 });

                // Detect TrimBox from page view (crop 6.5% from each side as default for print-ready PDFs)
                const cropPct = 0.065;
                const cropX = vp.width * cropPct;
                const cropY = vp.height * cropPct;
                const trimW = vp.width - (cropX * 2);
                const trimH = vp.height - (cropY * 2);

                // Scale to fit container
                const container = canvas.parentElement;
                const scale = Math.min(container.clientWidth / trimW, container.clientHeight / trimH) * 2;

                const fullVp = page.getViewport({ scale });
                canvas.width = Math.round(trimW * scale);
                canvas.height = Math.round(trimH * scale);

                const ctx = canvas.getContext('2d');
                // Offset to crop the edges
                ctx.translate(-cropX * scale, -cropY * scale);

                await page.render({ canvasContext: ctx, viewport: fullVp }).promise;
            } catch (e) {}
        });
    </script>
</body>
</html>


