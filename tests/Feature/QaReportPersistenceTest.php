<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6.3 (R8.5) — qa_report decoder handles both the correct array-cast persistence
 * and legacy DOUBLE-ENCODED rows, with no diagnostic loss. Also checks fingerprint fields
 * persist.
 */
class QaReportPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(): Translation
    {
        $book = Book::create([
            'title' => 'QA Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);
    }

    public function test_array_cast_report_decodes_cleanly(): void
    {
        $t = $this->makeTranslation();
        $report = ['publishable' => true, 'checks' => ['structure' => 'passed'],
                   'diagnostic_manifest' => ['pages' => [1]]];
        // CORRECT path: assign the array; the cast encodes once.
        $t->forceFill(['qa_report' => $report])->save();

        $fresh = Translation::find($t->id);
        $decoded = $fresh->decodeQaReport();
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['publishable']);
        $this->assertSame('passed', $decoded['checks']['structure']);
        $this->assertArrayHasKey('diagnostic_manifest', $decoded, 'no diagnostic loss');
    }

    public function test_legacy_double_encoded_report_still_decodes(): void
    {
        $t = $this->makeTranslation();
        $report = ['publishable' => false, 'checks' => ['fit' => 'failed'],
                   'issues' => [['code' => 'OVERFLOW']]];
        // SIMULATE the legacy bug: json_encode INTO the array-cast column => the cast
        // then encodes AGAIN, so the DB holds a double-encoded JSON string.
        $doubleEncoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        DB::table('translations')->where('id', $t->id)
            ->update(['qa_report' => json_encode($doubleEncoded, JSON_UNESCAPED_UNICODE)]);

        $fresh = Translation::find($t->id);
        // the raw cast would hand back a STRING here; the decoder must still recover it
        $decoded = $fresh->decodeQaReport();
        $this->assertIsArray($decoded, 'legacy double-encoded report recovered');
        $this->assertFalse($decoded['publishable']);
        $this->assertSame('failed', $decoded['checks']['fit']);
        $this->assertSame('OVERFLOW', $decoded['issues'][0]['code']);
    }

    public function test_canBePublished_reads_decoded_report(): void
    {
        $t = $this->makeTranslation();
        // legacy double-encoded, publishable=false — canBePublished must honour it
        $report = ['publishable' => false];
        $doubleEncoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        DB::table('translations')->where('id', $t->id)
            ->update(['qa_report' => json_encode($doubleEncoded, JSON_UNESCAPED_UNICODE)]);

        $this->assertFalse(Translation::find($t->id)->canBePublished(),
            'double-encoded publishable=false must block publish (no diagnostic loss)');
    }

    public function test_fingerprint_fields_persist(): void
    {
        $t = $this->makeTranslation();
        $t->forceFill([
            'render_fingerprint' => str_repeat('a', 64),
            'narration_fingerprint' => str_repeat('b', 64),
        ])->save();
        $fresh = Translation::find($t->id);
        $this->assertSame(str_repeat('a', 64), $fresh->render_fingerprint);
        $this->assertSame(str_repeat('b', 64), $fresh->narration_fingerprint);
    }
}
