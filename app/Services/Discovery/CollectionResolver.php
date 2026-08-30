<?php

namespace App\Services\Discovery;

use App\Models\Book;
use App\Models\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Resolves the books in a collection (Req 9). Manual collections use the pivot list
 * (pinned first). Rule-based collections evaluate their JSON rule via BookQuery — so a
 * new rule-based collection can be added as data with NO application-code change.
 */
class CollectionResolver
{
    /** @return SupportCollection<int,Book> */
    public function books(Collection $collection): SupportCollection
    {
        if ($collection->type === 'manual') {
            // pinned first, then sort_order (relationship already orders by sort_order).
            return $collection->books()->get()
                ->sortByDesc(fn ($b) => $b->pivot->pinned ? 1 : 0)->values();
        }

        // Rule-based: translate the stored rule into BookQuery filters.
        $rules = $collection->rules ?? [];
        $filters = [];
        if (!empty($rules['language'])) {
            $filters['language'] = is_array($rules['language']) ? $rules['language'][0] : $rules['language'];
        }
        if (!empty($rules['reading_level'])) {
            $filters['reading_level'] = $rules['reading_level'];
        }
        if (!empty($rules['book_type'])) {
            $filters['book_type'] = $rules['book_type'];
        }
        if (!empty($rules['category'])) {
            $filters['category'] = $rules['category'];
        }
        if (in_array('narrated', $rules['features'] ?? [], true)
            || in_array('word_highlighting', $rules['features'] ?? [], true)) {
            $filters['narrated'] = true;
        }

        $query = (new BookQuery($filters))->base();

        // Pinned manual books (if any) are surfaced first, then rule matches.
        $pinnedIds = $collection->books()->wherePivot('pinned', true)->pluck('books.id')->all();
        $ruleBooks = $query->get();

        if (empty($pinnedIds)) {
            return $ruleBooks;
        }
        $pinned = Book::whereIn('id', $pinnedIds)->get();
        return $pinned->merge($ruleBooks->whereNotIn('id', $pinnedIds))->values();
    }
}
