<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Models\Narration;
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
        // Resolve without throwing: if the card was already deleted (e.g. a
        // double-click or a stale list before Livewire re-rendered), just clear
        // the confirm state so the UI recovers without needing a page refresh.
        $book = Book::find($bookId);

        if (! $book) {
            $this->confirmingDelete = null;
            return;
        }

        $title = $book->title;

        // The Book model's deleting hook removes every associated file (source PDF,
        // cover, manifest, translated PDFs, narration audio tree, comparison renders)
        // and cascades child records — so a single delete() is a complete teardown.
        $book->delete();

        $this->confirmingDelete = null;
        session()->flash('success', "Deleted \"{$title}\" and all associated files.");
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
