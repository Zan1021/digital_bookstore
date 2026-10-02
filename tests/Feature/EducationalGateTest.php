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
 * unified-rendering-and-testing spec Req 6 / task C2.4 — educational validity is an
 * INDEPENDENT gate. An invalid exercise blocks approval even with a perfect layout; a
 * noneducational book records not_applicable with a reason (never a silent pass).
 */
class EducationalGateTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create([
            'title' => 'Edu Book', 'original_language' => 'en', 'page_count' => 1,
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
        // Visual QA is irrelevant to the educational layer; stub it.
        $qa = Mockery::mock(VisualQaService::class);
        return new BookTestingService($qa, new ExerciseService());
    }

    private function edition(?array $contract): Translation
    {
        return Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'education_phase' => 'foundation',
            'exercise_contract' => $contract,
        ]);
    }

    public function test_no_exercises_is_not_applicable_with_reason(): void
    {
        $result = $this->service()->educationalCheck($this->book, $this->edition(null));
        $this->assertFalse($result['applicable']);
        $this->assertSame('not_applicable', $result['status']);
        $this->assertSame('NO_EXERCISES_ON_EDITION', $result['reason']);
    }

    public function test_empty_exercise_blocks_even_with_perfect_layout(): void
    {
        // An exercise with no components is educationally invalid → failed, regardless of
        // any layout/visual pass elsewhere.
        $contract = ['exercises' => [['id' => 'e1', 'components' => []]]];
        $result = $this->service()->educationalCheck($this->book, $this->edition($contract));
        $this->assertTrue($result['applicable']);
        $this->assertSame('failed', $result['status']);
        $codes = array_column($result['issues'], 'code');
        $this->assertContains(ExerciseService::CODE_EMPTY, $codes);
    }

    public function test_unsupported_language_fails_not_silently_passes(): void
    {
        // Well-formed components, but Afrikaans pedagogy has no registered validator → the
        // educational layer must route to review, never fall back to English phonics.
        $contract = ['exercises' => [[
            'id' => 'e1',
            'components' => [
                ['source_id' => 'e1_obj', 'role' => 'objective', 'text' => 'Learn /st/'],
                ['source_id' => 'e1_ins', 'role' => 'instruction', 'text' => 'Sê die woord'],
            ],
        ]]];
        $result = $this->service()->educationalCheck($this->book, $this->edition($contract));
        $this->assertSame('failed', $result['status']);
        $codes = array_column($result['issues'], 'code');
        $this->assertContains(ExerciseService::CODE_UNSUPPORTED, $codes);
    }
}
