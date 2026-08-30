<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\Category;
use App\Models\ClassificationSuggestion;
use App\Models\Tag;
use App\Services\Classification\BookClassificationService;
use Livewire\Component;

/**
 * "Review Book Details" — the admin confirmation screen (Req 6). Shows AI/deterministic
 * suggestions pre-selected; the admin edits/accepts, then publishes (gated). All writes
 * go through BookClassificationService so the domain rules (dedup, source, gate) hold.
 */
class BookReviewDetails extends Component
{
    public ?Book $book = null;

    // Editable review fields (pre-filled from suggestions).
    public ?string $bookType = null;
    public ?string $primaryCategory = null;
    public ?int $ageMin = null;
    public ?int $ageMax = null;
    public array $selectedTagIds = [];
    public array $secondaryCategories = [];

    public function mount(Book $book): void
    {
        $this->book = $book;
        $this->prefillFromSuggestions();
    }

    private function suggestion(string $field): ?ClassificationSuggestion
    {
        return ClassificationSuggestion::where('book_id', $this->book->id)
            ->where('field', $field)->orderByDesc('confidence')->first();
    }

    private function prefillFromSuggestions(): void
    {
        $this->bookType = $this->book->book_type ?? ($this->suggestion('book_type')->value['value'] ?? null);
        $this->primaryCategory = $this->book->primaryCategory()?->slug
            ?? ($this->suggestion('primary_category')->value['value'] ?? null);

        if ($this->book->age_min !== null) {
            $this->ageMin = $this->book->age_min;
            $this->ageMax = $this->book->age_max;
        } elseif ($age = $this->suggestion('age_range')) {
            $this->ageMin = $age->value['min'] ?? null;
            $this->ageMax = $age->value['max'] ?? null;
        }

        // Pre-select already-attached tags + suggested (matched, non-pending) tags.
        $this->selectedTagIds = $this->book->tags()->pluck('tags.id')->all();
        foreach (ClassificationSuggestion::where('book_id', $this->book->id)->where('field', 'tag')->get() as $s) {
            if (!($s->value['pending'] ?? false) && !empty($s->value['tag_id'])) {
                $this->selectedTagIds[] = (int) $s->value['tag_id'];
            }
        }
        $this->selectedTagIds = array_values(array_unique($this->selectedTagIds));
    }

    public function save(BookClassificationService $svc): void
    {
        $userId = auth()->id();

        if ($this->bookType) {
            $svc->setBookType($this->book, $this->bookType, $userId);
        }
        if ($this->primaryCategory) {
            $svc->setCategory($this->book, $this->primaryCategory, true, 'administrator', $userId);
        }
        foreach ($this->secondaryCategories as $slug) {
            if ($slug !== $this->primaryCategory) {
                $svc->setCategory($this->book, $slug, false, 'administrator', $userId);
            }
        }
        if ($this->ageMin !== null && $this->ageMax !== null) {
            $svc->setAgeRange($this->book, (int) $this->ageMin, (int) $this->ageMax, $userId);
        }
        // Dedup tag ids before attaching (prevent duplicate tags — Req 6.3).
        foreach (array_unique($this->selectedTagIds) as $tagId) {
            $svc->addTag($this->book, (int) $tagId, 'administrator', $userId);
        }
        $this->book->refresh();
        session()->flash('success', 'Draft saved.');
    }

    public function approveDescription(BookClassificationService $svc): void
    {
        $desc = $this->book->descriptions()->where('status', 'suggested')->latest()->first();
        if ($desc) {
            $svc->approveDescription($desc, auth()->id());
            session()->flash('success', 'Description approved.');
        }
    }

    public function publish(BookClassificationService $svc): void
    {
        $this->save($svc);
        if ($svc->publish($this->book->refresh())) {
            session()->flash('success', 'Book published.');
        } else {
            $missing = implode(', ', $svc->missingRequirements($this->book));
            session()->flash('error', "Cannot publish — missing: {$missing}");
        }
    }

    public function render(BookClassificationService $svc)
    {
        return view('livewire.admin.book-review-details', [
            'categories' => Category::active()->orderBy('sort_order')->get(),
            'allTags' => Tag::active()->orderBy('group')->orderBy('canonical_name')->get(),
            'pendingTags' => Tag::pending()->orderBy('canonical_name')->get(),
            'description' => $this->book->descriptions()->latest()->first(),
            'missing' => $svc->missingRequirements($this->book),
            'canPublish' => $svc->canPublish($this->book),
        ])->layout('layouts.admin');
    }
}
