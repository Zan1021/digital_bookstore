<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StoryBooks — Children's Digital Publishing Platform</title>
    <script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={theme:{extend:{colors:{brand:{50:"#fff7f5",100:"#ffede8",200:"#ffd5cc",300:"#ffb3a3",400:"#ff8468",500:"#fd5826",600:"#e84a1c",700:"#c23a14",800:"#9d3114",900:"#7d2b15"}}}}}</script>
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #fd5826, #ff8468, #e84a1c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-gradient {
            background: linear-gradient(135deg, #fff7f5 0%, #ffede8 50%, #fdf2f8 100%);
        }
        .card-hover:hover {
            transform: translateY(-4px);
        }
    </style>
</head>
<body class="bg-white font-sans antialiased">
    {{-- Navigation --}}
    <nav class="bg-white/80 backdrop-blur-md border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('store') }}" class="text-2xl font-bold text-brand-600 flex items-center">
                <img src="{{ asset('images/logo.png') }}" alt="StoryBooks" class="h-10">
            </a>
            <div class="hidden md:flex items-center space-x-8">
                <a href="#features" class="text-sm text-gray-600 hover:text-brand-500 transition">Features</a>
                <a href="#how-it-works" class="text-sm text-gray-600 hover:text-brand-500 transition">How It Works</a>
                <a href="{{ route('store.browse') }}" class="text-sm text-gray-600 hover:text-brand-500 transition">Books</a>
                <a href="#pricing" class="text-sm text-gray-600 hover:text-brand-500 transition">Pricing</a>
            </div>
            <div class="flex items-center space-x-3">
                <a href="{{ route('admin.dashboard') }}" class="text-sm text-gray-500 hover:text-brand-500 transition">Publisher Login</a>
                <a href="{{ route('store.browse') }}" class="bg-brand-500 text-white px-5 py-2 rounded-full text-sm font-medium hover:bg-brand-600 transition shadow-sm">Browse Books</a>
            </div>
        </div>
    </nav>

    {{-- Hero Section --}}
    <section class="hero-gradient py-24 md:py-32">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <div class="inline-flex items-center bg-brand-100 text-brand-600 px-4 py-1.5 rounded-full text-xs font-medium mb-8">
                Powered by AI — Available in multiple languages
            </div>
            <h1 class="text-5xl md:text-6xl lg:text-7xl font-bold text-gray-900 leading-tight max-w-4xl mx-auto">
                Wonderful stories for <span class="gradient-text">curious minds</span>
            </h1>
            <p class="text-xl text-gray-500 mt-6 max-w-2xl mx-auto leading-relaxed">
                Interactive digital children's books with AI narration, beautiful page-turning effects, and multilingual support. Read, listen, and discover.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4 mt-10">
                <a href="#books" class="bg-brand-500 text-white px-8 py-3.5 rounded-full font-medium text-lg hover:bg-brand-600 transition shadow-lg shadow-brand-200">
                    Explore Books
                </a>
                <a href="#how-it-works" class="bg-white text-gray-700 px-8 py-3.5 rounded-full font-medium text-lg border border-gray-200 hover:border-brand-300 hover:text-brand-500 transition">
                    How It Works
                </a>
            </div>

            {{-- Stats --}}
            <div class="flex justify-center gap-12 mt-16">
                <div>
                    <div class="text-3xl font-bold text-gray-800">{{ $books->count() }}+</div>
                    <div class="text-sm text-gray-400 mt-1">Books Available</div>
                </div>
                <div>
                    <div class="text-3xl font-bold text-gray-800">3</div>
                    <div class="text-sm text-gray-400 mt-1">Languages</div>
                </div>
                <div>
                    <div class="text-3xl font-bold text-gray-800">AI</div>
                    <div class="text-sm text-gray-400 mt-1">Narrated</div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-800">Everything a digital bookstore needs</h2>
                <p class="text-gray-500 mt-4 max-w-xl mx-auto">A complete children's book platform — from PDF upload to a fully narrated, multilingual digital reading experience.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="p-6 rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-lg transition card-hover">
                    <div class="w-12 h-12 bg-brand-100 rounded-xl flex items-center justify-center text-2xl mb-4">📖</div>
                    <h3 class="font-semibold text-gray-800 text-lg">Interactive Flipbook</h3>
                    <p class="text-sm text-gray-500 mt-2 leading-relaxed">Beautiful 3D page-turning effects that make reading feel like holding a real book. Touch, swipe, or click to turn pages.</p>
                </div>

                <div class="p-6 rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-lg transition card-hover">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center text-2xl mb-4">🔊</div>
                    <h3 class="font-semibold text-gray-800 text-lg">AI Narration</h3>
                    <p class="text-sm text-gray-500 mt-2 leading-relaxed">Professional-quality AI storyteller voices that read each page aloud. Words highlight as they're spoken — perfect for early readers.</p>
                </div>

                <div class="p-6 rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-lg transition card-hover">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center text-2xl mb-4">🌍</div>
                    <h3 class="font-semibold text-gray-800 text-lg">Multilingual</h3>
                    <p class="text-sm text-gray-500 mt-2 leading-relaxed">Every book available in English, Afrikaans, and isiZulu. Switch languages with one click — narration adapts automatically.</p>
                </div>

                <div class="p-6 rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-lg transition card-hover">
                    <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center text-2xl mb-4">✨</div>
                    <h3 class="font-semibold text-gray-800 text-lg">Word Highlighting</h3>
                    <p class="text-sm text-gray-500 mt-2 leading-relaxed">Each word highlights in real-time as the narrator reads — helps children connect spoken words with text. Builds reading confidence.</p>
                </div>

                <div class="p-6 rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-lg transition card-hover">
                    <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center text-2xl mb-4">📱</div>
                    <h3 class="font-semibold text-gray-800 text-lg">Works Everywhere</h3>
                    <p class="text-sm text-gray-500 mt-2 leading-relaxed">Desktop, tablet, or phone. Full-screen mode fills the entire device. No app download needed — works in any browser.</p>
                </div>

                <div class="p-6 rounded-2xl border border-gray-100 hover:border-brand-200 hover:shadow-lg transition card-hover">
                    <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center text-2xl mb-4">🎭</div>
                    <h3 class="font-semibold text-gray-800 text-lg">Dramatic Storytelling</h3>
                    <p class="text-sm text-gray-500 mt-2 leading-relaxed">AI narrators with adjustable drama levels — from calm bedtime reading to full theatrical performance. Children love it.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- How It Works --}}
    <section id="how-it-works" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-800">How It Works</h2>
                <p class="text-gray-500 mt-4">From PDF to fully narrated multilingual flipbook in minutes</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-brand-500 text-white rounded-2xl flex items-center justify-center text-2xl font-bold mx-auto mb-4">1</div>
                    <h3 class="font-semibold text-gray-800">Upload PDF</h3>
                    <p class="text-sm text-gray-500 mt-2">Drop your children's book PDF. Single or bulk upload — we handle the rest.</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-purple-600 text-white rounded-2xl flex items-center justify-center text-2xl font-bold mx-auto mb-4">2</div>
                    <h3 class="font-semibold text-gray-800">AI Translates</h3>
                    <p class="text-sm text-gray-500 mt-2">Our AI instantly translates your book into multiple languages while keeping the tone child-friendly.</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-pink-600 text-white rounded-2xl flex items-center justify-center text-2xl font-bold mx-auto mb-4">3</div>
                    <h3 class="font-semibold text-gray-800">AI Narrates</h3>
                    <p class="text-sm text-gray-500 mt-2">Professional storyteller AI voices bring every page to life with drama, expression, and warmth.</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-green-600 text-white rounded-2xl flex items-center justify-center text-2xl font-bold mx-auto mb-4">4</div>
                    <h3 class="font-semibold text-gray-800">Publish & Sell</h3>
                    <p class="text-sm text-gray-500 mt-2">Your book appears in the store as an interactive flipbook, audiobook, PDF, and even physical copy.</p>
                </div>
            </div>

            {{-- Arrow connector (desktop) --}}
            <div class="hidden md:flex justify-center mt-[-60px] mb-8">
                <div class="flex items-center gap-[140px] text-gray-300">
                    <span>→</span><span>→</span><span>→</span>
                </div>
            </div>
        </div>
    </section>

    {{-- For Publishers --}}
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-16 items-center">
                <div>
                    <div class="inline-flex items-center bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-medium mb-4">For Publishers</div>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-800 leading-tight">Turn 300 books into a<br><span class="gradient-text">multilingual digital library</span></h2>
                    <p class="text-gray-500 mt-4 leading-relaxed">Upload your entire catalogue. Our AI handles translation, narration, and formatting. What would take months of manual work happens in hours — for a fraction of the cost.</p>

                    <div class="mt-8 space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="text-green-500 mt-0.5">&#10003;</span>
                            <div>
                                <span class="font-medium text-gray-800">300 books × 3 languages = R2,500 total</span>
                                <p class="text-xs text-gray-400">vs R300,000+ for human translators</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-500 mt-0.5">&#10003;</span>
                            <div>
                                <span class="font-medium text-gray-800">Professional AI narration at R1.80/book</span>
                                <p class="text-xs text-gray-400">vs R5,000+ per book for voice actors</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-500 mt-0.5">&#10003;</span>
                            <div>
                                <span class="font-medium text-gray-800">Bulk processing — upload 100 books at once</span>
                                <p class="text-xs text-gray-400">Process overnight, review in the morning</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-500 mt-0.5">&#10003;</span>
                            <div>
                                <span class="font-medium text-gray-800">Human review before publishing</span>
                                <p class="text-xs text-gray-400">Reviewers approve every page before it goes live</p>
                            </div>
                        </div>
                    </div>

                    <a href="{{ route('admin.dashboard') }}" class="inline-block mt-8 bg-brand-500 text-white px-6 py-3 rounded-full font-medium hover:bg-brand-600 transition shadow-lg shadow-brand-200">
                        Publisher Dashboard →
                    </a>
                </div>

                <div class="bg-gray-50 rounded-2xl p-8 border border-gray-100">
                    <div class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-4">Cost comparison per book</div>
                    <div class="space-y-4">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Human translation (1 language)</span>
                                <span class="font-bold text-gray-800">R1,000+</span>
                            </div>
                            <div class="w-full bg-red-100 rounded-full h-2"><div class="bg-red-500 h-2 rounded-full" style="width:100%"></div></div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Human voice actor</span>
                                <span class="font-bold text-gray-800">R5,000+</span>
                            </div>
                            <div class="w-full bg-red-100 rounded-full h-2"><div class="bg-red-500 h-2 rounded-full" style="width:100%"></div></div>
                        </div>
                        <div class="pt-2 border-t border-gray-200">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">AI translation (1 language)</span>
                                <span class="font-bold text-green-600">R0.07</span>
                            </div>
                            <div class="w-full bg-green-100 rounded-full h-2"><div class="bg-green-500 h-2 rounded-full" style="width:1%"></div></div>
                        </div>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">AI narration</span>
                                <span class="font-bold text-green-600">R1.80</span>
                            </div>
                            <div class="w-full bg-green-100 rounded-full h-2"><div class="bg-green-500 h-2 rounded-full" style="width:2%"></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Books Section --}}
    <section id="books" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-800">Featured Books</h2>
                <p class="text-gray-500 mt-3">Discover stories in English, Afrikaans, and isiZulu</p>
            </div>

            @if($books->isEmpty())
                <div class="text-center py-12 text-gray-400 bg-white rounded-2xl border border-gray-100">
                    <p class="text-lg">No books published yet.</p>
                    <p class="text-sm mt-1">Books will appear here once published from the admin dashboard.</p>
                </div>
            @else
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                    @foreach($books as $book)
                        <a href="{{ route('store.book', $book) }}" class="group">
                            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden shadow-sm hover:shadow-xl transition duration-300 card-hover">
                                <div class="aspect-[3/4] bg-gray-50 overflow-hidden relative">
                                    <canvas class="pdf-cover w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                            data-pdf="{{ asset('storage/' . $book->pdf_path) }}"></canvas>
                                    <div class="absolute top-2 left-2 flex flex-col gap-1">
                                        @if($book->narrations->where('status', 'completed')->count() > 0)
                                            <span class="bg-purple-500 text-white text-[9px] px-2 py-0.5 rounded-full font-medium">AUDIO</span>
                                        @endif
                                        @if($book->translations->count() > 0)
                                            <span class="bg-blue-500 text-white text-[9px] px-2 py-0.5 rounded-full font-medium">{{ $book->translations->count() + 1 }} LANGS</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="p-4">
                                    <h3 class="font-semibold text-gray-800 text-sm line-clamp-2">{{ $book->title }}</h3>
                                    @if($book->author)
                                        <p class="text-xs text-gray-400 mt-1">{{ $book->author }}</p>
                                    @endif
                                    <div class="flex items-center justify-between mt-3">
                                        <span class="text-xs text-gray-500">{{ $book->page_count }} pages</span>
                                        <span class="text-sm font-bold text-brand-500">R89</span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- Pricing --}}
    <section id="pricing" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-800">Simple Pricing</h2>
                <p class="text-gray-500 mt-3">Multiple formats to suit every reader</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-4xl mx-auto">
                <div class="p-6 bg-white rounded-2xl border border-gray-200 text-center hover:border-brand-300 transition">
                    <div class="text-3xl mb-3">📱</div>
                    <h3 class="font-bold text-gray-800">Flipbook</h3>
                    <p class="text-xs text-gray-400 mt-1">Interactive reading online</p>
                    <div class="text-3xl font-bold text-brand-500 mt-4">R89</div>
                    <p class="text-[10px] text-gray-400 mt-1">per book</p>
                </div>

                <div class="p-6 bg-brand-50 rounded-2xl border-2 border-brand-300 text-center relative">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-brand-500 text-white text-[10px] px-3 py-0.5 rounded-full font-medium">POPULAR</div>
                    <div class="text-3xl mb-3">🔊</div>
                    <h3 class="font-bold text-gray-800">Flipbook + Audio</h3>
                    <p class="text-xs text-gray-400 mt-1">Read-along with narrator</p>
                    <div class="text-3xl font-bold text-brand-500 mt-4">R129</div>
                    <p class="text-[10px] text-gray-400 mt-1">per book</p>
                </div>

                <div class="p-6 bg-white rounded-2xl border border-gray-200 text-center hover:border-brand-300 transition">
                    <div class="text-3xl mb-3">📕</div>
                    <h3 class="font-bold text-gray-800">Physical Book</h3>
                    <p class="text-xs text-gray-400 mt-1">Hardcover, delivered</p>
                    <div class="text-3xl font-bold text-brand-500 mt-4">R299</div>
                    <p class="text-[10px] text-gray-400 mt-1">per book + shipping</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Newsletter / CTA --}}
    <section class="py-20 hero-gradient">
        <div class="max-w-3xl mx-auto px-6 text-center">
            <h2 class="text-3xl font-bold text-gray-800">Ready to bring your stories to life?</h2>
            <p class="text-gray-500 mt-4">Whether you're a publisher with hundreds of titles or a parent looking for quality children's content — we've got you covered.</p>

            <div class="flex flex-col sm:flex-row justify-center gap-4 mt-8">
                <a href="#books" class="bg-brand-500 text-white px-8 py-3 rounded-full font-medium hover:bg-brand-600 transition shadow-lg shadow-brand-200">
                    Browse Books
                </a>
                <a href="{{ route('admin.dashboard') }}" class="bg-white text-brand-500 px-8 py-3 rounded-full font-medium border border-brand-200 hover:bg-brand-50 transition">
                    Publisher Portal
                </a>
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-gray-900 text-gray-400 py-12">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <div class="text-white font-bold text-lg mb-3 flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                        StoryBooks
                    </div>
                    <p class="text-sm">Children's digital publishing platform with AI narration and multilingual support.</p>
                </div>
                <div>
                    <h4 class="text-white font-medium mb-3">Platform</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#features" class="hover:text-white transition">Features</a></li>
                        <li><a href="#how-it-works" class="hover:text-white transition">How It Works</a></li>
                        <li><a href="#pricing" class="hover:text-white transition">Pricing</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-medium mb-3">Formats</h4>
                    <ul class="space-y-2 text-sm">
                        <li>Interactive Flipbook</li>
                        <li>Audiobook</li>
                        <li>PDF & EPUB</li>
                        <li>Paperback & Hardcover</li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-medium mb-3">Languages</h4>
                    <ul class="space-y-2 text-sm">
                        <li>English</li>
                        <li>Afrikaans</li>
                        <li>isiZulu</li>
                        <li>More coming soon...</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 mt-8 pt-8 text-center text-sm">
                <p>&copy; 2026 StoryBooks — Children's Digital Publishing Platform. All rights reserved.</p>
            </div>
        </div>
    </footer>

    {{-- PDF.js for covers — crops to TrimBox --}}
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
                const cropPct = 0.065;
                const cropX = vp.width * cropPct;
                const cropY = vp.height * cropPct;
                const trimW = vp.width - (cropX * 2);
                const trimH = vp.height - (cropY * 2);
                const container = canvas.parentElement;
                const scale = Math.max(container.clientWidth / trimW, container.clientHeight / trimH) * 2;
                canvas.width = Math.round(trimW * scale);
                canvas.height = Math.round(trimH * scale);
                const ctx = canvas.getContext('2d');
                ctx.translate(-cropX * scale, -cropY * scale);
                await page.render({ canvasContext: ctx, viewport: page.getViewport({ scale }) }).promise;
            } catch (e) {}
        });
    </script>
</body>
</html>


