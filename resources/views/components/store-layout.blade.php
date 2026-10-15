@props(['title' => 'StoryBooks'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} — StoryBooks</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-white font-sans antialiased text-gray-800">
    <nav class="bg-white/80 backdrop-blur-md border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('store') }}" class="text-2xl font-bold text-brand-600 flex items-center">
                <img src="{{ asset('images/logo.png') }}" alt="StoryBooks" class="h-10" onerror="this.style.display='none';this.insertAdjacentText('afterend','StoryBooks')">
            </a>
            <div class="hidden md:flex items-center space-x-8 text-sm text-gray-600">
                <a href="{{ route('store.browse') }}" class="hover:text-brand-500 transition">Browse Books</a>
                <a href="{{ route('store') }}#how-it-works" class="hover:text-brand-500 transition">How It Works</a>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="text-sm text-gray-500 hover:text-brand-500 transition">Publisher Login</a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
