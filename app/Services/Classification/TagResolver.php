<?php

namespace App\Services\Classification;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagTranslation;
use Illuminate\Support\Str;

/**
 * Maps free-text tag strings (e.g. from the LLM) to CANONICAL tag identities.
 * - Exact slug / canonical name / translation / alias match -> existing active tag.
 * - No match -> create a PENDING tag (approval queue), NEVER an active one.
 * This is what stops uncontrolled AI text from polluting the public tag set (Req 3).
 */
class TagResolver
{
    /**
     * @param string[] $strings
     * @return array{matched: Tag[], pending: Tag[]}
     */
    public function resolveMany(array $strings): array
    {
        $matched = [];
        $pending = [];
        foreach ($strings as $s) {
            $tag = $this->resolveOne($s);
            if (!$tag) {
                continue;
            }
            if ($tag->status === 'active') {
                $matched[$tag->id] = $tag;
            } else {
                $pending[$tag->id] = $tag;
            }
        }
        return ['matched' => array_values($matched), 'pending' => array_values($pending)];
    }

    public function resolveOne(string $string): ?Tag
    {
        $norm = Str::lower(trim($string));
        if ($norm === '') {
            return null;
        }
        $slug = Str::slug($norm);

        // 1. exact slug
        if ($tag = Tag::where('slug', $slug)->first()) {
            return $this->follow($tag);
        }
        // 2. canonical name (case-insensitive)
        if ($tag = Tag::whereRaw('lower(canonical_name) = ?', [$norm])->first()) {
            return $this->follow($tag);
        }
        // 3. translation label
        $tt = TagTranslation::whereRaw('lower(label) = ?', [$norm])->first();
        if ($tt) {
            return $this->follow($tt->tag);
        }
        // 4. alias
        $al = TagAlias::whereRaw('lower(alias) = ?', [$norm])->first();
        if ($al) {
            return $this->follow($al->tag);
        }
        // 5. no match -> pending tag in the approval queue (never active)
        $tag = Tag::firstOrCreate(
            ['slug' => $slug],
            ['canonical_name' => Str::title($norm), 'group' => 'pending', 'status' => 'pending']
        );
        return $tag;
    }

    /** Follow a merge chain to the live tag. */
    private function follow(Tag $tag): Tag
    {
        $guard = 0;
        while ($tag->merged_into_id && $guard++ < 20) {
            $next = Tag::find($tag->merged_into_id);
            if (!$next) {
                break;
            }
            $tag = $next;
        }
        return $tag;
    }
}
