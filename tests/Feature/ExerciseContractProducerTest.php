<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\ExerciseContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * unified-rendering-and-testing Req 6 / S1 — the exercise-contract PRODUCER. Proves the
 * previously-inert educational gate now has an upstream: a render manifest with a
 * vocabulary (exercise) page yields a structured contract with stable component IDs;
 * a storybook manifest (no vocabulary pages) yields null (educational stays not_applicable).
 */
class ExerciseContractProducerTest extends TestCase
{
    use RefreshDatabase;

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Workbook', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'draft', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);
    }

    public function test_vocabulary_page_produces_contract_with_stable_ids(): void
    {
        $edition = $this->edition();
        $manifest = [
            'page_types' => [1 => 'cover', 2 => 'story', 3 => 'vocabulary'],
            'diagnostic_manifest' => ['pages' => [
                ['page_number' => 3, 'regions' => [
                    ['text' => 'Pas die woord by die prent'],
                    ['text' => 'kat'],
                    ['text' => 'hond'],
                ]],
            ]],
        ];

        $contract = app(ExerciseContractService::class)
            ->extractFromManifest($edition->book, $edition, $manifest, true);

        $this->assertNotNull($contract);
        $this->assertSame('exercise-contract-1', $contract['schema_version']);
        $this->assertCount(1, $contract['exercises']);

        $ex = $contract['exercises'][0];
        $this->assertSame('ex-p3', $ex['id']);
        $this->assertSame(3, $ex['page_number']);

        $ids = array_column($ex['components'], 'source_id');
        $this->assertSame(['3:instruction:0', '3:questions:0', '3:questions:1'], $ids);

        // Persisted onto the edition.
        $this->assertNotNull($edition->fresh()->exercise_contract);
    }

    public function test_storybook_with_no_vocabulary_pages_yields_null(): void
    {
        $edition = $this->edition();
        $manifest = ['page_types' => [1 => 'cover', 2 => 'story', 3 => 'story', 4 => 'back_cover']];

        $contract = app(ExerciseContractService::class)
            ->extractFromManifest($edition->book, $edition, $manifest, true);

        $this->assertNull($contract);
        $this->assertNull($edition->fresh()->exercise_contract);
    }

    public function test_vocabulary_page_with_no_text_records_empty_exercise(): void
    {
        $edition = $this->edition();
        $manifest = ['page_types' => [1 => 'vocabulary']]; // no diagnostic_manifest text

        $contract = app(ExerciseContractService::class)
            ->extractFromManifest($edition->book, $edition, $manifest, false);

        $this->assertNotNull($contract);
        $this->assertSame([], $contract['exercises'][0]['components'],
            'a vocabulary page with no text should record an empty exercise (gate flags it)');
    }
}
