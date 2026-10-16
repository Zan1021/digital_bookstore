<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Digital Bookstore - Admin' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- NOTE: Do NOT load a standalone Alpine (e.g. the unpkg CDN build) here.
         @livewireScripts (below) ships its OWN Alpine bundle INCLUDING the `navigate`
         plugin. Loading a second Alpine wins `window.Alpine` first but lacks
         `Alpine.navigate`, which made Livewire redirect()/wire:navigate throw
         "Alpine.navigate is not a function" and silently killed the auto-open-review
         redirect after a translation. Livewire's Alpine covers x-data / Alpine.data too. --}}
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
                @can('books.manage')
                <a href="{{ route('admin.intake') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm">Add Books</a>
                @endcan
                @can('ai_costs.view')
                <a href="{{ route('admin.ai-costs') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm">AI Costs</a>
                @endcan
                @can('staff.manage')
                <a href="{{ route('admin.staff') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm">Staff</a>
                @endcan
                <a href="{{ route('store') }}" class="px-3 py-1 rounded hover:bg-brand-700 transition text-sm" target="_blank">View Store ↗</a>
            </div>
            <div class="flex items-center gap-3 text-sm text-brand-200">
                <a href="{{ route('profile') }}" class="hover:text-white transition">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="px-3 py-1 rounded hover:bg-brand-700 hover:text-white transition">Log out</button>
                </form>
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

</body>
</html>
