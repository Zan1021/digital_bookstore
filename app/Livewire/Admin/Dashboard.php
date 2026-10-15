<?php

namespace App\Livewire\Admin;

use App\Livewire\Attributes\Can;
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

    #[Can('books.manage')]
    public function confirmDelete(int $bookId)
    {
        $this->confirmingDelete = $bookId;
    }

    #[Can('books.manage')]
    public function cancelDelete()
    {
        $this->confirmingDelete = null;
    }

    #[Can('admin.access')]
    public function setFilter(string $filter)
    {
        $this->filter = $filter;
    }

    /**
     * Publish a book to the storefront — the final step of the upload→review→publish
     * flow. Goes through the single publish authority (BookClassificationService), which
     * enforces the metadata + render-ready-edition gate and flips the book AND its
     * editions live. On refusal it reports exactly what's missing so the user knows what
     * to fix, instead of a dead button that silently does nothing.
     */
    #[Can('editions.publish')]
    public function publish(int $bookId)
    {
        $book = Book::find($bookId);
        if (! $book) {
            return;
        }

        $svc = app(\App\Services\Classification\BookClassificationService::class);
        if ($svc->publish($book)) {
            session()->flash('success', "Published \"{$book->title}\" — it's now live in the store.");
            return;
        }

        $missing = $svc->missingRequirements($book->refresh());
        $labels = [
            'primary_category' => 'a primary category',
            'language' => 'a language',
            'book_type' => 'a book type',
            'age_range' => 'an age range',
            'description' => 'an approved description',
            'publishable_edition' => 'a render-ready edition (review/approve a translation, or the English source)',
        ];
        $needs = implode(', ', array_map(fn ($m) => $labels[$m] ?? $m, $missing));
        session()->flash('error', "Can't publish \"{$book->title}\" yet — still needs: {$needs}. Set these on the book's Manage page, then publish.");
    }

    /** Pull a book from the storefront (reverses publish; non-destructive). */
    #[Can('editions.publish')]
    public function unpublish(int $bookId)
    {
        $book = Book::find($bookId);
        if (! $book) {
            return;
        }

        $book->unpublish();
        session()->flash('success', "Unpublished \"{$book->title}\" — it's back to draft and hidden from the store.");
    }

    #[Can('books.manage')]
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
