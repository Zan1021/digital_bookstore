<?php

namespace App\Services\Classification;

use App\Models\Book;
use App\Models\BookDescription;
use App\Models\Category;
use App\Models\ClassificationReview;
use App\Models\Tag;
use Illuminate\Support\Carbon;

/**
 * Applies human review decisions to a book, turning confirmed suggestions into
 * APPROVED classification records (with source + approved_by), and enforces the
 * publish gate. This is the domain core the review UI drives — kept UI-free so it is
 * unit/feature-testable.
 */
class BookClassificationService
{
    /** Required before a book may be published (Req 6.4). */
    public const REQUIRED_FIELDS = ['primary_category', 'language', 'book_type', 'age_range', 'description'];

    /**
     * Attach a category (deduped). $isPrimary makes it THE primary (only one).
     */
    public function setCategory(Book $book, string $slug, bool $isPrimary, string $source, ?int $userId = null): void
    {
        $category = Category::where('slug', $slug)->firstOrFail()->effective();

        if ($isPrimary) {
            // demote any existing primary
            $book->categories()->newPivotStatement()
                ->where('book_id', $book->id)->update(['is_primary' => false]);
        }
        $book->categories()->syncWithoutDetaching([
            $category->id => [
                'is_primary' => $isPrimary,
                'source' => $source,
                'approved_at' => Carbon::now(),
                'approved_by' => $userId,
            ],
        ]);
    }

    /**
     * Attach a tag by id (dedup enforced by unique pivot). Only ACTIVE tags may be
     * attached to a published book; pending tags must be approved first.
     */
    public function addTag(Book $book, int $tagId, string $source, ?int $userId = null): void
    {
        $tag = Tag::findOrFail($tagId);
        $book->tags()->syncWithoutDetaching([
            $tag->id => [
                'source' => $source,
                'approved_at' => Carbon::now(),
                'approved_by' => $userId,
            ],
        ]);
    }

    /** Set the validated age range (kids-domain: explicit human confirmation). */
    public function setAgeRange(Book $book, int $min, int $max, ?int $userId = null): void
    {
        [$min, $max] = [min($min, $max), max($min, $max)];
        $band = \App\Models\AudienceRange::forAge($min);
        $book->forceFill([
            'age_min' => $min,
            'age_max' => $max,
            'audience_range_id' => $band?->id,
        ])->save();

        ClassificationReview::create([
            'book_id' => $book->id, 'reviewer_id' => $userId, 'field' => 'age_range',
            'decision' => 'accepted', 'final_value' => ['min' => $min, 'max' => $max],
            'reviewed_at' => Carbon::now(),
        ]);
    }

    public function setBookType(Book $book, string $type, ?int $userId = null): void
    {
        $book->forceFill(['book_type' => $type])->save();
    }

    /** Approve a description draft (the published copy is human-approved). */
    public function approveDescription(BookDescription $desc, ?int $userId = null): void
    {
        $desc->forceFill([
            'status' => 'approved', 'approved_by' => $userId, 'approved_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Which required fields are still missing (Req 6.4). Empty array => publishable.
     * @return string[]
     */
    public function missingRequirements(Book $book): array
    {
        $missing = [];

        if (!$book->categories()->wherePivot('is_primary', true)->exists()) {
            $missing[] = 'primary_category';
        }
        // Language: the work's original language OR at least one edition.
        if (empty($book->original_language) && !$book->translations()->exists()) {
            $missing[] = 'language';
        }
        if (empty($book->book_type)) {
            $missing[] = 'book_type';
        }
        if ($book->age_min === null || $book->age_max === null) {
            $missing[] = 'age_range';
        }
        if (!$book->descriptions()->where('status', 'approved')->exists()) {
            $missing[] = 'description';
        }
        // R1 (unified-rendering-and-testing): a book with translated editions must have at
        // least ONE render-ready edition before it can be published, so a failed edition can
        // never reach the public store. The trusted English source counts as render-ready.
        if ($book->translations()->exists() && ! $this->hasPublishableEdition($book)) {
            $missing[] = 'publishable_edition';
        }
        return $missing;
    }

    /**
     * True when the book has at least one edition that passes render readiness (R1).
     * A store-visible edition is: the trusted English source, or a translated edition
     * that passed the readiness gate. An English-original book with NO edition rows yet
     * is also eligible — its trusted source PDF is materialised as an 'en' edition at
     * publish time (Book::publishBook). But once a translated edition exists, that
     * edition must itself be ready: an English-original book whose only edition is a
     * FAILED translation stays unpublishable (a bad QA edition can't reach the store).
     */
    private function hasPublishableEdition(Book $book): bool
    {
        if (! $book->translations()->exists() && $book->original_language === 'en') {
            return true; // trusted source, no editions yet -> 'en' created at publish
        }
        foreach ($book->translations as $edition) {
            if ($edition->isStoreVisible()) {
                return true;
            }
        }
        return false;
    }

    public function canPublish(Book $book): bool
    {
        return empty($this->missingRequirements($book));
    }

    /**
     * Publish the book if the gate passes. Returns true on publish; false (no state
     * change) when requirements are missing. Marks classification reviewed.
     *
     * Delegates the actual go-live to Book::publishBook(), which flips the book AND its
     * render-ready editions to published. Previously this only set book.status, leaving
     * every edition's publication_status='draft' — so the catalogue gate (which also
     * requires a published edition) still hid the "published" book. Going through
     * publishBook() closes that gap so a gated publish is actually visible in the store.
     */
    public function publish(Book $book): bool
    {
        // Materialise the trusted English source edition first so the readiness gate and
        // the catalogue reason over it (an English-original book's proofed source always
        // counts, even if only a translated edition row existed before).
        $book->ensureSourceEdition();

        if (!$this->canPublish($book)) {
            return false;
        }
        if (! $book->publishBook()) {
            return false; // no render-ready edition could be taken live
        }
        $book->forceFill(['classification_status' => 'reviewed'])->save();
        return true;
    }
}
