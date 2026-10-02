<?php

namespace Tests\Unit;

use App\Services\ExerciseService;
use PHPUnit\Framework\TestCase;

/**
 * unified-rendering-and-testing spec Req 6 / task C1.3 — component-ID validation.
 */
class ExerciseServiceTest extends TestCase
{
    private ExerciseService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ExerciseService();
    }

    private function comp(string $id, string $role = 'instruction'): array
    {
        return ['source_id' => $id, 'role' => $role, 'text' => 'x'];
    }

    public function test_matching_ids_have_no_issues(): void
    {
        $issues = $this->svc->componentIdIssues(['a', 'b'], [$this->comp('a'), $this->comp('b')]);
        $this->assertSame([], $issues);
    }

    public function test_unknown_id_flagged(): void
    {
        $issues = $this->svc->componentIdIssues(['a'], [$this->comp('a'), $this->comp('zzz')]);
        $codes = array_column($issues, 'code');
        $this->assertContains(ExerciseService::CODE_UNKNOWN_ID, $codes);
    }

    public function test_duplicate_id_flagged(): void
    {
        $issues = $this->svc->componentIdIssues(['a'], [$this->comp('a'), $this->comp('a')]);
        $codes = array_column($issues, 'code');
        $this->assertContains(ExerciseService::CODE_DUPLICATE_ID, $codes);
    }

    public function test_missing_id_flagged(): void
    {
        $issues = $this->svc->componentIdIssues(['a', 'b'], [$this->comp('a')]);
        $codes = array_column($issues, 'code');
        $this->assertContains(ExerciseService::CODE_MISSING_ID, $codes);
    }

    public function test_duplicate_expected_ids_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->componentIdIssues(['a', 'a'], [$this->comp('a')]);
    }

    public function test_empty_exercise_structure_flagged(): void
    {
        $issues = $this->svc->validateExerciseStructure(['id' => 'e1', 'components' => []]);
        $this->assertSame(ExerciseService::CODE_EMPTY, $issues[0]['code']);
    }

    public function test_valid_exercise_structure_passes(): void
    {
        $exercise = ['id' => 'e1', 'components' => [
            $this->comp('e1_obj', 'objective'),
            $this->comp('e1_ins', 'instruction'),
        ]];
        $this->assertSame([], $this->svc->validateExerciseStructure($exercise));
    }

    public function test_unsupported_language_routes_to_review_no_phonics_fallback(): void
    {
        // Afrikaans has no registered pedagogy validator → must be UNSUPPORTED, never a
        // silent English-phonics pass.
        $contract = ['exercises' => [[
            'id' => 'e1', 'components' => [$this->comp('e1_obj', 'objective')],
        ]]];
        $issues = $this->svc->educationalIssues($contract, ['language' => 'af']);
        $codes = array_column($issues, 'code');
        $this->assertContains(ExerciseService::CODE_UNSUPPORTED, $codes);
    }

    public function test_null_contract_is_no_issues(): void
    {
        // No exercises → not this layer's concern (caller marks not_applicable).
        $this->assertSame([], $this->svc->educationalIssues(null, ['language' => 'af']));
    }

    public function test_present_but_empty_contract_is_a_failure(): void
    {
        $issues = $this->svc->educationalIssues(['exercises' => []], ['language' => 'af']);
        $this->assertSame(ExerciseService::CODE_EMPTY, $issues[0]['code']);
    }
}
