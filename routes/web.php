<?php

use App\Livewire\Admin\BookManager;
use App\Livewire\Admin\BookUpload;
use App\Livewire\Admin\Dashboard;
use App\Models\Book;
use Illuminate\Support\Facades\Route;

// Redirect root to store (the customer-facing page)
Route::get('/', fn() => redirect()->route('store'));

// Store (customer-facing landing). Only PUBLISHED books surface (brief: public store shows
// published editions only). Uses the same discovery engine as /browse for consistency.
Route::get('/store', function () {
    $books = (new \App\Services\Discovery\BookQuery([]))->base()
        ->with(['translations', 'narrations'])
        ->latest()
        ->get();
    return view('store.index', compact('books'));
})->name('store');

Route::get('/store/book/{book}', function (Book $book) {
    $book->load(['translations', 'narrations']);
    return view('store.book', compact('book'));
})->name('store.book');

// Store discovery catalogue — customer-facing filters/search/facets over PUBLISHED books
// (wires the tested App\Services\Discovery\BookQuery engine to a real UI).
Route::get('/browse', \App\Livewire\Store\Catalog::class)->name('store.browse');

// Admin routes (no auth for POC - add later)
Route::prefix('admin')->group(function () {
    Route::get('/', Dashboard::class)->name('admin.dashboard');
    Route::get('/upload', BookUpload::class)->name('admin.upload');
    Route::get('/onboard', \App\Livewire\Admin\BookOnboarding::class)->name('admin.onboard');
    Route::get('/books/{book}', BookManager::class)->name('admin.book');
    Route::get('/books/{book}/details', \App\Livewire\Admin\BookReviewDetails::class)->name('admin.book-details');
    Route::get('/books/{book}/review/{language?}', \App\Livewire\Admin\BookReviewer::class)->name('admin.review');
});

// Engine Comparison (V7 vs V8) — Test route
Route::get('/admin/engine-compare/{book}', \App\Livewire\Admin\EngineCompare::class)->name('admin.engine-compare');

// Review Queue — Per-page review with approve/reject/edit
Route::get('/admin/review-queue/{book}/{language?}', \App\Livewire\Admin\ReviewQueue::class)->name('admin.review-queue');

// Font Manager — View source fonts, mappings, upload custom fonts
Route::get('/admin/fonts/{book}', \App\Livewire\Admin\FontManager::class)->name('admin.fonts');

// Flipbook viewer
Route::get('/flipbook/{book}', function (Book $book) {
    $book->load(['translations.translatedPages', 'narrations', 'pages']);

    // Check if a specific language is requested and has a rendered PDF
    $requestedLang = request()->query('lang');
    $pdfPath = $book->pdf_path; // Default: original English PDF

    if ($requestedLang && $requestedLang !== 'en') {
        $translation = $book->translations->where('language_code', $requestedLang)->first();
        if ($translation && $translation->rendered_pdf_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($translation->rendered_pdf_path)) {
            $pdfPath = $translation->rendered_pdf_path;
        }
    }

    // Build timing map for word-level sync
    $pageTimingMap = [];
    $narration = $book->narrations->where('status', 'completed')->first();
    if ($narration && $narration->page_audio_paths) {
        foreach ($narration->page_audio_paths as $page => $path) {
            $timingPath = str_replace('.mp3', '-timing.json', $path);
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($timingPath)) {
                $timing = json_decode(\Illuminate\Support\Facades\Storage::disk('public')->get($timingPath), true);
                if ($timing) {
                    $pageTimingMap[(int)$page] = $timing;
                }
            }
        }
    }

    return view('flipbook', compact('book', 'pageTimingMap', 'pdfPath'));
})->name('flipbook');

// Smart Book Reader (new)
Route::get('/read/{book}', function (Book $book) {
    $book->load(['translations.translatedPages', 'narrations']);

    $lang = request()->query('lang', 'en');
    $pdfPath = $book->pdf_path;

    if ($lang !== 'en') {
        $translation = $book->translations->where('language_code', $lang)->first();
        if ($translation && $translation->rendered_pdf_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($translation->rendered_pdf_path)) {
            $pdfPath = $translation->rendered_pdf_path;
        }
    }

    // Narration data — an outdated edition narration (translated text edited after
    // it was generated) must not be served as current (gated flow Req 6.2).
    $narration = $book->narrations
        ->where('status', 'completed')
        ->where('language_code', $lang)
        ->where('is_outdated', false)
        ->first();
    $pageAudioMap = [];
    $pageTimingMap = [];

    if ($narration && $narration->page_audio_paths) {
        foreach ($narration->page_audio_paths as $page => $path) {
            $pageAudioMap[(int)$page] = asset('storage/' . $path);
            
            // Load timing data for word-level sync
            $timingPath = str_replace('.mp3', '-timing.json', $path);
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($timingPath)) {
                $timing = json_decode(\Illuminate\Support\Facades\Storage::disk('public')->get($timingPath), true);
                if ($timing) {
                    $pageTimingMap[(int)$page] = $timing;
                }
            }
        }
    }

    return view('reader', compact('book', 'pdfPath', 'lang', 'pageAudioMap', 'pageTimingMap'));
})->name('reader');

// THROWAWAY page-curl prototype (spec: specs/page-curl-reader). Self-contained, does NOT
// touch reader.blade.php. Renders PDF.js page canvases into StPageFlip html-mode pages.
Route::get('/read-curl/{book}', function (Book $book) {
    $book->load(['translations.translatedPages']);
    $lang = request()->query('lang', 'en');
    $pdfPath = $book->pdf_path;
    if ($lang !== 'en') {
        $t = $book->translations->where('language_code', $lang)->first();
        if ($t && $t->rendered_pdf_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($t->rendered_pdf_path)) {
            $pdfPath = $t->rendered_pdf_path;
        }
    }
    return view('read-curl', compact('book', 'pdfPath', 'lang'));
})->name('read-curl');

// RAW PDF — serve the translated PDF directly, no reader/crop, for debugging the engine output.
Route::get('/raw-pdf/{book}', function (Book $book) {
    $lang = request()->query('lang', 'af');
    $pdfPath = $book->pdf_path;

    if ($lang !== 'en') {
        $translation = $book->translations->where('language_code', $lang)->first();
        if ($translation && $translation->rendered_pdf_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($translation->rendered_pdf_path)) {
            $pdfPath = $translation->rendered_pdf_path;
        }
    }

    abort_unless(\Illuminate\Support\Facades\Storage::disk('public')->exists($pdfPath), 404, 'PDF not found');

    return response(\Illuminate\Support\Facades\Storage::disk('public')->get($pdfPath), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . basename($pdfPath) . '"',
    ]);
})->name('raw-pdf');

