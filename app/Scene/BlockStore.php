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
    /** The kinds extraction decides (task 11.2); other kinds belong to later stages. */
    public const OWN_KINDS = ['paragraph', 'list', 'list_item'];

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
            $ids = [];
            foreach ($extracted as $i => $new) {
                $values = [
                    'bbox' => $new['bbox'],
                    'reading_order' => $i,
                    'style' => $new['style'] + ['lines' => array_column($new['lines'], 'bbox'), 'unmapped' => (int) ($new['unmapped'] ?? 0)],
                    'source_text' => $new['text'],
                ];
                $kind = $new['kind'] ?? 'paragraph';
                if (isset($assigned[$i])) {
                    $block = $existing[$assigned[$i]];
                    // Extraction owns paragraph / list / list_item; a kind a later stage chose (a table
                    // cell, a heading, a bubble) and its structure are kept.
                    if (in_array($block->kind, self::OWN_KINDS, true)) {
                        $values += ['kind' => $kind, 'structure' => $new['structure'] ?? null];
                    }
                    $block->update($values);
                } else {
                    // Role is provisional until classification (task 14) decides.
                    $block = Block::create($values + ['book_id' => $page->book_id, 'page_id' => $page->id,
                        'kind' => $kind, 'role' => 'body', 'structure' => $new['structure'] ?? null]);
                    $stats['added']++;
                }
                $ids[$i] = $block->id;
            }

            // List items point at their list (task 11.2); set once every block has its ID.
            foreach ($extracted as $i => $new) {
                if (in_array(Block::whereKey($ids[$i])->value('kind'), self::OWN_KINDS, true)) {
                    Block::whereKey($ids[$i])->update(['parent_id' => isset($new['parent']) ? $ids[$new['parent']] : null]);
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
