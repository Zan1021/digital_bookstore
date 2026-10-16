<?php

namespace App\Scene;

use App\Models\Block;
use App\Models\SourcePage;
use Illuminate\Support\Facades\DB;

/**
 * Saves the extracted blocks of a page, keeping block IDs stable across re-extraction
 * (task 11.1, Req 4.1, design §4): translations, reviewer edits and confirmed treatments hang
 * off a block's ULID, so a block that is still there must keep it. Matching, in order:
 *   1. same text, boxes overlapping ≥ 50 %   (unchanged)
 *   2. same text anywhere on the page         (moved)
 *   3. boxes overlapping ≥ 70 %               (text corrected in place)
 * Blocks that no longer exist are removed; new ones get new ULIDs. What later stages decided
 * about a kept block (kind, role, treatment, confirmation) is left alone.
 */
class BlockStore
{
    /** @param list<array{bbox: array, text: string, lines: array, style: array, unmapped: int}> $extracted */
    public function replacePage(SourcePage $page, array $extracted): array
    {
        return DB::transaction(function () use ($page, $extracted) {
            $existing = $page->blocks()->get()->keyBy('id');
            $assigned = [];      // new index => existing id
            $taken = [];         // existing id => true

            foreach ([fn ($new, $old) => self::sameText($new, $old) && self::overlap($new['bbox'], $old->bbox) >= 0.5,
                fn ($new, $old) => self::sameText($new, $old),
                fn ($new, $old) => self::overlap($new['bbox'], $old->bbox) >= 0.7] as $rule) {
                foreach ($extracted as $i => $new) {
                    if (isset($assigned[$i])) {
                        continue;
                    }
                    $match = $existing->first(fn ($old) => ! isset($taken[$old->id]) && $rule($new, $old));
                    if ($match) {
                        $assigned[$i] = $match->id;
                        $taken[$match->id] = true;
                    }
                }
            }

            Block::whereIn('id', $existing->keys()->diff(array_keys($taken))->all())->delete();

            $stats = ['kept' => count($assigned), 'added' => 0, 'removed' => $existing->count() - count($taken)];
            foreach ($extracted as $i => $new) {
                $values = [
                    'bbox' => $new['bbox'],
                    'reading_order' => $i,
                    'style' => $new['style'] + ['lines' => array_column($new['lines'], 'bbox'), 'unmapped' => (int) ($new['unmapped'] ?? 0)],
                    'source_text' => $new['text'],
                ];
                if (isset($assigned[$i])) {
                    $existing[$assigned[$i]]->update($values);
                } else {
                    // Provisional kind and role until structure (11.2–11.3) and classification (14) decide.
                    Block::create($values + ['book_id' => $page->book_id, 'page_id' => $page->id, 'kind' => 'paragraph', 'role' => 'body']);
                    $stats['added']++;
                }
            }

            return $stats;
        });
    }

    private static function sameText(array $new, Block $old): bool
    {
        $norm = fn (string $t) => preg_replace('/\s+/u', ' ', trim($t));

        return $norm($new['text']) === $norm((string) $old->source_text);
    }

    /** Intersection over the smaller box: 1 when one box contains the other. */
    private static function overlap(array $a, ?array $b): float
    {
        if (! $b) {
            return 0.0;
        }
        $w = min($a[2], $b[2]) - max($a[0], $b[0]);
        $h = min($a[3], $b[3]) - max($a[1], $b[1]);
        if ($w <= 0 || $h <= 0) {
            return 0.0;
        }
        $area = fn ($r) => max(($r[2] - $r[0]) * ($r[3] - $r[1]), 1e-6);

        return ($w * $h) / min($area($a), $area($b));
    }
}
