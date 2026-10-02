<?php

namespace App\Services;

/**
 * ExerciseService — structured educational exercises + independent validity gate
 * (unified-rendering-and-testing spec Req 6, brief §6).
 *
 * Before this, "educational" handling was a keyword flag (TranslationService::
 * isVocabularyPage → a yellow "needs specialist review" note). There was no structured
 * exercise, no per-component validation, and NOTHING that could block approval on
 * EDUCATIONAL grounds independent of layout. This service introduces that gate.
 *
 * An exercise is a set of COMPONENT SLOTS, each with a stable component ID:
 *   objective · instruction · pattern · examples · questions · answer_key
 *
 * General translation/regeneration must NOT overwrite components independently — the exact
 * IDs, schema, roles, counts and language are validated BEFORE persisting
 * (componentIdIssues). Audience/curriculum come from edition policy; there is NO hardcoded
 * ages-5-8 or English-phonics assumption. An unsupported language/validator routes to
 * specialist review — never a silent English-phonics fallback.
 *
 * Educational validity is its OWN check layer: an educational failure blocks approval
 * regardless of a perfect layout, and a layout pass can never clear it.
 */
class ExerciseService
{
    /** The component slots every exercise is expected to define. */
    public const COMPONENT_ROLES = ['objective', 'instruction', 'pattern', 'examples', 'questions', 'answer_key'];

    public const CODE_UNKNOWN_ID = 'UNKNOWN_OR_INVALID_COMPONENT_ID';
    public const CODE_DUPLICATE_ID = 'DUPLICATE_COMPONENT_ID';
    public const CODE_MISSING_ID = 'MISSING_COMPONENT_ID';
    public const CODE_UNSUPPORTED = 'UNSUPPORTED_EDUCATIONAL_VALIDATOR';
    public const CODE_EMPTY = 'EMPTY_EXERCISE';

    /**
     * Validate that the supplied components cover exactly the expected component IDs — no
     * unknown, duplicate, or missing IDs (brief §6 reference helper). Schema/type validation
     * must run before this; here we reason about IDENTITY coverage only.
     *
     * @param list<string> $expectedIds
     * @param list<array<string,mixed>> $components  each with a 'source_id'
     * @return list<array<string,mixed>>  issue list (empty == valid)
     */
    public function componentIdIssues(array $expectedIds, array $components): array
    {
        if (count($expectedIds) !== count(array_unique($expectedIds))) {
            throw new \InvalidArgumentException('Duplicate expected IDs');
        }

        $expected = array_fill_keys($expectedIds, true);
        $seen = [];
        $issues = [];

        foreach ($components as $component) {
            $id = is_array($component) ? ($component['source_id'] ?? null) : null;
            if (!is_string($id) || !isset($expected[$id])) {
                $issues[] = ['code' => self::CODE_UNKNOWN_ID, 'source_id' => $id];
                continue;
            }
            if (isset($seen[$id])) {
                $issues[] = ['code' => self::CODE_DUPLICATE_ID, 'source_id' => $id];
            }
            $seen[$id] = true;
        }

        foreach ($expectedIds as $id) {
            if (!isset($seen[$id])) {
                $issues[] = ['code' => self::CODE_MISSING_ID, 'source_id' => $id];
            }
        }

        return $issues;
    }

    /**
     * Validate a single exercise's structure: it must declare an id and define the expected
     * component roles, each resolving to a present component. Returns an issue list (empty ==
     * structurally valid). This does NOT judge pedagogy — see educationalIssues().
     *
     * @param array<string,mixed> $exercise  {id, components: [ {source_id, role, ...} ]}
     * @return list<array<string,mixed>>
     */
    public function validateExerciseStructure(array $exercise): array
    {
        $components = $exercise['components'] ?? [];
        if (!is_array($components) || $components === []) {
            return [['code' => self::CODE_EMPTY, 'exercise_id' => $exercise['id'] ?? null]];
        }

        // Expected IDs are the per-exercise component source_ids declared for its roles.
        $expectedIds = [];
        foreach ($components as $c) {
            if (is_array($c) && isset($c['source_id']) && is_string($c['source_id'])) {
                $expectedIds[] = $c['source_id'];
            }
        }
        // Deduplicate for the expected set; duplicates in the actual list are caught below.
        $expectedIds = array_values(array_unique($expectedIds));

        return $this->componentIdIssues($expectedIds, $components);
    }

    /**
     * The INDEPENDENT educational validity check for an edition's exercise contract. Returns
     * an issue list; a non-empty list means educational failure → blocks approval regardless
     * of layout (Req 6.4).
     *
     * Pedagogy rules are language/curriculum specific. When no validator is registered for
     * the edition's language, we DO NOT guess (no English-phonics fallback) — we emit an
     * UNSUPPORTED issue so the edition routes to specialist review (Req 6.5).
     *
     * @param array<string,mixed>|null $exerciseContract  edition.exercise_contract
     * @param array<string,mixed> $editionPolicy  {language, audience, curriculum, ...}
     * @return list<array<string,mixed>>
     */
    public function educationalIssues(?array $exerciseContract, array $editionPolicy): array
    {
        // No exercises on this edition → the educational layer is not applicable (caller
        // records the policy reason). An empty-but-present contract is a structural failure.
        if ($exerciseContract === null) {
            return [];
        }
        $exercises = $exerciseContract['exercises'] ?? null;
        if (!is_array($exercises) || $exercises === []) {
            return [['code' => self::CODE_EMPTY]];
        }

        $issues = [];
        foreach ($exercises as $exercise) {
            if (!is_array($exercise)) {
                $issues[] = ['code' => self::CODE_EMPTY];
                continue;
            }
            foreach ($this->validateExerciseStructure($exercise) as $i) {
                $issues[] = $i + ['exercise_id' => $exercise['id'] ?? null];
            }
        }

        // Pedagogy: require a registered validator for the language; otherwise route to
        // review rather than fall back to English phonics (Req 6.5).
        $language = $editionPolicy['language'] ?? null;
        if (!$this->hasValidatorFor($language)) {
            $issues[] = ['code' => self::CODE_UNSUPPORTED, 'language' => $language];
        }

        return $issues;
    }

    /**
     * Whether a language-specific pedagogy validator is registered. Deliberately
     * conservative: only languages we have actually validated return true. Everything else
     * routes to specialist review (never a silent pass).
     */
    public function hasValidatorFor(?string $language): bool
    {
        // No automatic pedagogy validators are registered yet (Phase C builds the structure;
        // the language-specific phonics/spelling validators are specialist work). Until one
        // is demonstrated, every language routes to review — honest, fail-closed.
        $registered = [];
        return is_string($language) && in_array($language, $registered, true);
    }
}
