<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\ExerciseService;
use App\Services\Qa\BookTestingService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * unified-rendering-and-testing D2 — the educational layer participates in the gate. We
 * assert the SEAM BookTestingService exposes to the render path: an edition with an invalid
 * exercise contract yields a failing educational check (which createTranslatedPdf converts
 * into a NEEDS_LAYOUT_REVIEW routing + a QaReport 'educational' failure). A noneducational
 * edition yields not_applicable (no block). Avoids invoking the live Python render.
 */
class EducationalGateWiringTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create([
            'title' => 'Wiring Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(): BookTestingService
    {
        $qa = Mockery::mock(VisualQaService::class);
        return new BookTestingService($qa, new ExerciseService());
    }

    private function edition(?array $contract): Translation
    {
        return Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'exercise_contract' => $contract,
        ]);
    }

    public function test_invalid_exercise_edition_fails_educational_layer(): void
    {
        $contract = ['exercises' => [['id' => 'e1', 'components' => []]]];
        $result = $this->service()->educationalCheck($this->book, $this->edition($contract));

        // This is exactly the signal createTranslatedPdf consumes to route to review.
        $this->assertTrue($result['applicable']);
        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['issues']);
    }

    public function test_noneducational_edition_does_not_block(): void
    {
        $result = $this->service()->educationalCheck($this->book, $this->edition(null));
        $this->assertFalse($result['applicable']);
        $this->assertSame('not_applicable', $result['status']);
    }
}
