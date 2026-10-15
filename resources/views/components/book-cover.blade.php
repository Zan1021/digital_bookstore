@props(['book'])
{{-- Store/admin cover: the public cover image (task 3.2), never the protected PDF.
     Books without a cover yet get a plain title placeholder. --}}
@php($coverUrl = \App\Assets\AssetStorage::coverUrl($book))
@if ($coverUrl)
    <img src="{{ $coverUrl }}" alt="{{ __('Cover of :title', ['title' => $book->title]) }}" loading="lazy"
         {{ $attributes->merge(['class' => 'block w-full h-full object-cover']) }}>
@else
    <div {{ $attributes->merge(['class' => 'w-full h-full flex items-center justify-center bg-gray-100 text-gray-400 text-sm text-center p-4']) }}
         role="img" aria-label="{{ __('No cover yet for :title', ['title' => $book->title]) }}">
        {{ $book->title }}
    </div>
@endif
