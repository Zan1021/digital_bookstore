<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Digital Bookstore - Admin' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#fff7f5',
                            100: '#ffede8',
                            200: '#ffd5cc',
                            300: '#ffb3a3',
                            400: '#ff8468',
                            500: '#fd5826',
                            600: '#e84a1c',
                            700: '#c23a14',
                            800: '#9d3114',
                            900: '#7d2b15',
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    @livewireStyles
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-brand-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.dashboard') }}" class="text-xl font-bold flex items-center">
                    <img src="{{ asset('images/logo-white.png') }}" alt="Logo" class="h-8 mr-2">
                </a>
                <a href="{{ route('admin.dashboard') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm">Dashboard</a>
                <a href="{{ route('admin.upload') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm">Upload Books</a>
                <a href="{{ route('store') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm" target="_blank">View Store ↗</a>
            </div>
            <div class="text-sm text-brand-200">
                Publisher Admin
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 py-8">
        @if (session('error'))
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        @if (session('success'))
            <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        {{ $slot }}
    </main>

    @livewireScripts

    {{-- PDF.js for rendering book covers (cropped to TrimBox) --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>
</html>
