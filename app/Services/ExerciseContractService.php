<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Support\Facades\Log;

/**
 * ExerciseContractService — the PRODUCER for structured exercise contracts
 * (unified-rendering-and-testing spec Req 6 / tasks C1–C2, S1).
 *
 * Context: the educational gate (BookTestingService::educationalCheck + ExerciseService)
 * was fully built and tested but INERT — nothing wrote `translations.exercise_contract`, so
 * for every book it returned `not_applicable` and the gate never did any work. This service
 * is the missing upstream: it extracts a structured exercise contract from a book's rendered
 * manifest so the gate finally has data to validate.
 *
 * SOURCE OF TRUTH: the engine's own page classification. The render emits `page_types`
 * (cover / copyright / story / vocabulary / back_cover …) and per-page text in the persisted
 * qa_report. Pages the engine classifies as `vocabulary` are the exercise pages; we build one
 * exercise per such page, with component slots carrying STABLE IDs so a re-render / re-extract
 * is idempotent and the gate can reason about identity (ExerciseService::componentIdIssues).
 *
 * SAFETY: this is CONFIG-GATED and OFF by default (`bookstore.exercise_extraction.enabled`).
 * Populating a contract makes the educational gate active for that edition, which — until a
 * language pedagogy validator is registered — routes the edition to specialist review
 * (fail-closed, by design). So it must never run silently on storybooks that currently pass.
 */
class ExerciseContractService
{
    /**
     * Build (and optionally persist) an exercise contract for an edition from its last
     * render's manifest. Returns the contract array, or null when the book has no exercise
     * (vocabulary) pages — in which case the edition's exercise_contract is left untouched.
     *
     * @return array{exercises: list<array<string,mixed>>, source: string}|null
     */
    public function extractForEdition(Book $book, Translation $translation, bool $persist = true): ?array
    {
        $qa = $translation->qa_report;
        if (!is_array($qa)) {
            Log::info('ExerciseContract: no qa_report manifest to extract from', [
                'book' => $book->id, 'language' => $translation->language_code,
            ]);
            return null;
        }

        return $this->extractFromManifest($book, $translation, $qa, $persist);
    }

    /**
     * Build (and optionally persist) a contract from an explicit manifest array — used
     * DURING a render, where the fresh manifest is in-memory and not yet persisted on the
     * edition. Returns the contract array, or null when there are no exercise pages.
     *
     * @param array<string,mixed> $qa  the engine render manifest (fresh $report)
     * @return array{schema_version:string, source:string, exercises: list<array<string,mixed>>}|null
     */
    public function extractFromManifest(Book $book, Translation $translation, array $qa, bool $persist = true): ?array
    {
        $pageTypes = $qa['page_types'] ?? [];
        if (!is_array($pageTypes) || $pageTypes === []) {
            return null;
        }

        // The per-page item source. The contract renderer records resolved items under
        // 'scene'/'diagnostic_manifest'; we read item text per page defensively from whatever
        // the manifest exposes, so this stays book-agnostic.
        $exercises = [];
        foreach ($pageTypes as $pageNum => $type) {
            if ($type !== 'vocabulary') {
                continue;
            }
            $pageNum = (int) $pageNum;
            $components = $this->componentsForPage($qa, $pageNum, $translation);
            if ($components === []) {
                // A vocabulary page with no extractable text is still an exercise page — record
                // an empty exercise so the gate flags it (EMPTY_EXERCISE) rather than ignoring it.
                $exercises[] = [
                    'id' => "ex-p{$pageNum}",
                    'page_number' => $pageNum,
                    'components' => [],
                ];
                continue;
            }
            $exercises[] = [
                'id' => "ex-p{$pageNum}",
                'page_number' => $pageNum,
                'components' => $components,
            ];
        }

        if ($exercises === []) {
            return null; // no exercise pages — storybook; leave contract null (not_applicable)
        }

        $contract = [
            'schema_version' => 'exercise-contract-1',
            'source' => 'manifest-vocabulary-pages',
            'exercises' => $exercises,
        ];

        if ($persist) {
            $translation->forceFill(['exercise_contract' => $contract])->save();
            Log::info('ExerciseContract: extracted + persisted', [
                'book' => $book->id, 'language' => $translation->language_code,
                'exercise_count' => count($exercises),
            ]);
        }

        return $contract;
    }

    /**
     * Build the component slots for a single exercise (vocabulary) page. We map the page's
     * text units to the canonical component roles. A vocabulary page's words/prompts become
     * `questions`; the page title/heading (if any) becomes the `instruction`. Each component
     * gets a STABLE source_id: "{page}:{role}:{index}".
     *
     * @return list<array<string,mixed>>
     */
    private function componentsForPage(array $qa, int $pageNum, Translation $translation): array
    {
        $texts = $this->pageTexts($qa, $pageNum, $translation);
        if ($texts === []) {
            return [];
        }

        $components = [];
        // First line → instruction (the activity prompt). Remainder → questions (the items).
        $first = array_shift($texts);
        $components[] = [
            'source_id' => "{$pageNum}:instruction:0",
            'role' => 'instruction',
            'text' => $first,
        ];
        foreach (array_values($texts) as $i => $t) {
            $components[] = [
                'source_id' => "{$pageNum}:questions:{$i}",
                'role' => 'questions',
                'text' => $t,
            ];
        }

        return $components;
    }

    /**
     * Pull the translated text units for a page, newest render first. Defensive about the
     * manifest shape: reads item_translations keyed by id (preferred), else the page's scene
     * entries, else nothing. Returns a flat list of non-empty strings in document order.
     *
     * @return list<string>
     */
    private function pageTexts(array $qa, int $pageNum, Translation $translation): array
    {
        $out = [];

        // Preferred: the per-id item_translations store carries the authoritative resolved
        // text; the diagnostic_manifest maps items to pages.
        $manifest = $qa['diagnostic_manifest']['pages'] ?? null;
        if (is_array($manifest)) {
            foreach ($manifest as $page) {
                if ((int) ($page['page_number'] ?? -1) !== $pageNum) {
                    continue;
                }
                foreach (($page['regions'] ?? []) as $region) {
                    $t = trim((string) ($region['text'] ?? $region['translated_text'] ?? ''));
                    if ($t !== '') {
                        $out[] = $t;
                    }
                }
            }
        }

        // Fallback: a 'scene' map keyed by page number carrying text lines.
        if ($out === [] && isset($qa['scene'][$pageNum]) && is_array($qa['scene'][$pageNum])) {
            foreach ($qa['scene'][$pageNum] as $entry) {
                $t = is_string($entry) ? $entry : trim((string) ($entry['text'] ?? ''));
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }

        return $out;
    }
}
