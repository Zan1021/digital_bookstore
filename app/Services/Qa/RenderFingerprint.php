<?php

namespace App\Services\Qa;

/**
 * RenderFingerprint — a stable content hash of everything that determines an edition's
 * rendered output (world-class-render-engine spec R9.2, Phase 6.6).
 *
 * Approvals (language / layout / artwork) are tied to a fingerprint. When ANY input
 * changes — source/manifest version, the per-id target text, the font policy, fit
 * policies, approved repair revisions, or the engine version — the fingerprint changes,
 * which invalidates the stored approvals (R9.3) and, when the TARGET TEXT changed, the
 * narration too (handled by the caller comparing text components).
 *
 * The hash is deterministic and order-independent for maps (keys are sorted) so an
 * identical rerun produces an identical fingerprint — no drift (R9.4).
 */
class RenderFingerprint
{
    /**
     * @param array{
     *   source_version?: string|int|null,
     *   manifest_version?: string|int|null,
     *   engine_version?: string|null,
     *   item_translations?: array<string,string>,
     *   typography_policy?: array<mixed>,
     *   fit_policies?: array<mixed>,
     *   repair_revisions?: array<mixed>
     * } $components
     */
    public static function compute(array $components): string
    {
        $normalized = [
            'source_version' => (string) ($components['source_version'] ?? ''),
            'manifest_version' => (string) ($components['manifest_version'] ?? ''),
            'engine_version' => (string) ($components['engine_version'] ?? ''),
            'text' => self::hashTextComponent($components['item_translations'] ?? []),
            'typography' => self::canonical($components['typography_policy'] ?? []),
            'fit' => self::canonical($components['fit_policies'] ?? []),
            'repair' => self::canonical($components['repair_revisions'] ?? []),
        ];
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The TEXT sub-hash alone (R9.3): a change here must invalidate narration. Callers
     * compare this between renders to decide whether to re-narrate.
     */
    public static function hashTextComponent(array $itemTranslations): string
    {
        return self::canonical($itemTranslations);
    }

    /** Deterministic, order-independent canonical hash of an array (keys sorted deep). */
    private static function canonical($value): string
    {
        return hash('sha256', json_encode(self::sortDeep($value), JSON_UNESCAPED_UNICODE));
    }

    private static function sortDeep($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortDeep($v);
        }
        if (self::isAssoc($out)) {
            ksort($out);
        }
        return $out;
    }

    private static function isAssoc(array $a): bool
    {
        return array_keys($a) !== range(0, count($a) - 1);
    }
}
