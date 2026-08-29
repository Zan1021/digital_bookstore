<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Models\Narration;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class Dashboard extends Component
{
    public ?int $confirmingDelete = null;
    public string $search = '';
    public string $filter = 'all'; // all, ready, processing, translated, narrated

    public function confirmDelete(int $bookId)
    {
        $this->confirmingDelete = $bookId;
    }

    public function cancelDelete()
    {
        $this->confirmingDelete = null;
    }

    public function setFilter(string $filter)
    {
        $this->filter = $filter;
    }

    public function deleteBook(int $bookId)
    {
        $book = Book::findOrFail($bookId);

        // Delete associated files
        if ($book->pdf_path && Storage::disk('public')->exists($book->pdf_path)) {
            Storage::disk('public')->delete($book->pdf_path);
        }

        // Delete narration audio files
        foreach ($book->narrations as $narration) {
            if ($narration->page_audio_paths) {
                foreach ($narration->page_audio_paths as $path) {
                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                    }
                    $timingPath = str_replace('.mp3', '-timing.json', $path);
                    if (Storage::disk('public')->exists($timingPath)) {
                        Storage::disk('public')->delete($timingPath);
                    }
                }
            }
        }

        // Delete translated PDFs
        foreach ($book->translations as $translation) {
            $translatedPdfPath = "books/translated/{$book->id}_{$translation->language_code}.pdf";
            if (Storage::disk('public')->exists($translatedPdfPath)) {
                Storage::disk('public')->delete($translatedPdfPath);
            }
            $translation->translatedPages()->delete();
        }

        // Delete related records
        $book->narrations()->delete();
        $book->translations()->delete();
        $book->pages()->delete();
        $book->delete();

        $this->confirmingDelete = null;
        session()->flash('success', "Deleted \"{$book->title}\" and all associated files.");
    }

    public function render()
    {
        // Build filtered book query
        $query = Book::with(['translations', 'narrations']);

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%' . $this->search . '%')
                  ->orWhere('sku', 'like', '%' . $this->search . '%')
                  ->orWhere('author', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filter === 'ready') {
            $query->where('status', 'ready');
        } elseif ($this->filter === 'processing') {
            $query->where('status', 'processing');
        } elseif ($this->filter === 'translated') {
            $query->whereHas('translations');
        } elseif ($this->filter === 'narrated') {
            $query->whereHas('narrations', fn($q) => $q->where('status', 'completed'));
        }

        $books = $query->latest()->take(50)->get();

        // Stats
        $totalBooks = Book::count();
        $readyBooks = Book::where('status', 'ready')->count();
        $processingBooks = Book::where('status', 'processing')->count();
        $translatedBooks = Book::whereHas('translations')->count();
        $narratedBooks = Book::whereHas('narrations', fn($q) => $q->where('status', 'completed'))->count();

        // Estimate total cost (translations + narrations)
        $totalTransChars = \App\Models\TranslatedPage::sum(\Illuminate\Support\Facades\DB::raw('LENGTH(translated_text)'));
        $totalTransCostUsd = (($totalTransChars / 4) * 2 * 0.15 / 1000000) + (($totalTransChars / 4) * 0.60 / 1000000);
        $totalCostZar = $totalTransCostUsd * 18.5;
        // Add narration cost estimate (chars of narrated text × ElevenLabs rate)
        $narratedChars = 0;
        $completedNarrations = Narration::where('status', 'completed')->get();
        foreach ($completedNarrations as $n) {
            $book = $n->book;
            if ($book) {
                $narratedChars += $book->pages->pluck('extracted_text')->filter()->map(fn($t) => mb_strlen($t))->sum();
            }
        }
        $narrationCostZar = ($narratedChars / 1000) * 0.30 * 18.5;
        $totalCostZar += $narrationCostZar;

        return view('livewire.admin.dashboard', [
            'totalBooks' => $totalBooks,
            'readyBooks' => $readyBooks,
            'processingBooks' => $processingBooks,
            'translatedBooks' => $translatedBooks,
            'narratedBooks' => $narratedBooks,
            'totalCostZar' => $totalCostZar,
            'recentBooks' => $books,
            'activeJobs' => ProcessingJob::whereIn('status', ['queued', 'processing'])->count(),
        ])->layout('layouts.admin');
    }
}
