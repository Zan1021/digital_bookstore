<?php

namespace Tests\Feature;

use App\Ai\SpendingLimitReached;
use App\Corpus\CorpusRegistry;
use App\Corpus\CorpusRunner;
use App\Models\AiLedgerEntry;
use App\Models\Book;
use App\Models\CorpusBook;
use App\Models\JobRun;
use App\Models\Translation;
use App\Services\PdfService;
use App\Services\PdfTranslationService;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 8: golden-corpus registration and the corpus run. The real pipeline is replaced by
 * stand-ins so no PDF is rendered and no AI is called.
 */
class CorpusTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'corpus-test-'.uniqid();
        $this->out = $this->root.DIRECTORY_SEPARATOR.'runs';
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'nested');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'Top Book.pdf', 'pdf-one');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'Deep Book.pdf', 'pdf-two');

        config([
            'corpus.roots' => [$this->root],
            'corpus.output_dir' => $this->out,
            'corpus.books' => [
                ['key' => 'top', 'file' => 'Top Book.pdf', 'source' => 'Studio A'],
                ['key' => 'deep', 'file' => 'Deep Book.pdf', 'source' => 'Studio B', 'features' => ['table'], 'outside' => true],
                ['key' => 'gone', 'file' => 'Not Here.pdf', 'source' => 'Studio C'],
            ],
        ]);

        // Importing a corpus book must not render, fetch fonts or call AI in tests.
        $this->app->instance(PdfService::class, new class extends PdfService
        {
            public int $imports = 0;

            public function processUpload(UploadedFile $file): Book
            {
                $this->imports++;

                return Book::create(['title' => $file->getClientOriginalName(), 'page_count' => 16,
                    'pdf_path' => 'books/pdfs/'.$this->imports.'.pdf', 'original_language' => 'en', 'status' => 'ready']);
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_register_imports_once_and_again_only_when_the_pdf_changes(): void
    {
        $registry = app(CorpusRegistry::class);

        $first = collect($registry->register())->keyBy('key');
        $this->assertSame(['imported', 'imported', 'missing'], [$first['top']['status'], $first['deep']['status'], $first['gone']['status']]);
        $this->assertSame(2, Book::count());

        $again = collect($registry->register())->keyBy('key');
        $this->assertSame('unchanged', $again['top']['status']);

        file_put_contents($this->root.DIRECTORY_SEPARATOR.'Top Book.pdf', 'pdf-one-v2');
        $changed = collect($registry->register('top'))->keyBy('key');
        $this->assertSame('reimported', $changed['top']['status']);
        $this->assertNotSame($first['top']['book_id'], $changed['top']['book_id']);
        $this->assertSame(3, Book::count(), 'the earlier import is kept');
        $this->assertSame(['table'], CorpusBook::where('key', 'deep')->value('features'));
    }

    public function test_coverage_counts_outside_books_with_hard_cases(): void
    {
        $cov = app(CorpusRegistry::class)->coverage();

        $this->assertSame(['outside_books' => 1, 'required' => 3, 'features' => ['table'], 'ok' => false], $cov);
    }

    public function test_register_command_reports_missing_books_and_coverage(): void
    {
        $this->artisan('corpus:register')
            ->expectsOutputToContain('Missing PDF for gone')
            ->expectsOutputToContain('Req 26.2: 1 of 3 outside books registered (covers: table)')
            ->assertFailed();
    }

    private function fakePipeline(?\Throwable $fail = null): void
    {
        $this->app->instance(TranslationService::class, new class($fail) extends TranslationService
        {
            public function __construct(private ?\Throwable $fail) {}

            public function translateWithManifest(Book $book, string $languageCode): Translation
            {
                if ($this->fail) {
                    throw $this->fail;
                }
                $t = Translation::where('book_id', $book->id)->where('language_code', $languageCode)->first();
                AiLedgerEntry::create(['provider' => 'openai', 'model' => 'gpt-4o', 'operation' => 'translate',
                    'book_id' => $book->id, 'translation_id' => $t->id, 'units' => [], 'cost_zar' => 1.25, 'at' => now()]);
                JobRun::create(['book_id' => $book->id, 'stage' => 'translate.text', 'status' => 'succeeded',
                    'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 4200]);

                return $t;
            }
        });
        $this->app->instance(PdfTranslationService::class, new class extends PdfTranslationService
        {
            public function createTranslatedPdf(Book $book, Translation $translation): string
            {
                $translation->forceFill(['render_status' => Translation::STATE_NEEDS_LAYOUT_REVIEW, 'qa_report' => [
                    'publishable' => false, 'review_pages' => [7, 3], 'visual_qa' => ['flagged_pages' => [3, 12]],
                    'issues' => [['code' => 'VISUAL_QA_FLAGGED'], ['code' => 'VISUAL_QA_FLAGGED']],
                ]])->save();

                return 'books/translated/x.pdf';
            }
        });
    }

    public function test_a_run_stores_gates_timings_and_cost_per_book(): void
    {
        app(CorpusRegistry::class)->register();
        $this->fakePipeline();

        $summary = app(CorpusRunner::class)->run('af');

        $this->assertCount(2, $summary['books']);
        $top = $summary['books'][0];
        $this->assertSame('ok', $top['status']);
        $this->assertSame(Translation::STATE_NEEDS_LAYOUT_REVIEW, $top['render_status']);
        $this->assertSame([3, 7, 12], $top['flagged_pages']);
        $this->assertSame(['VISUAL_QA_FLAGGED'], $top['issue_codes']);
        $this->assertSame(4.2, $top['timings']['translate.text']);
        $this->assertArrayHasKey('translate.edition', $top['timings']);
        $this->assertTrue($top['within_budget']);
        $this->assertSame(1.25, $top['cost_zar']);
        $this->assertSame(['books' => 2, 'ok' => 2, 'publishable' => 0, 'pages' => 32, 'flagged_pages' => 6, 'over_budget' => 0],
            array_intersect_key($summary['totals'], array_flip(['books', 'ok', 'publishable', 'pages', 'flagged_pages', 'over_budget'])));

        $dir = $this->out.DIRECTORY_SEPARATOR.$summary['run_id'];
        $this->assertEquals($summary, json_decode(file_get_contents($dir.'/summary.json'), true));
        $report = json_decode(file_get_contents($dir.'/top/report.json'), true);
        $this->assertSame([7, 3], $report['qa_report']['review_pages']);
    }

    public function test_a_spending_limit_stops_the_run(): void
    {
        app(CorpusRegistry::class)->register();
        $this->fakePipeline(new SpendingLimitReached(null, 10, 10));

        $summary = app(CorpusRunner::class)->run('af');

        $this->assertCount(1, $summary['books'], 'no point trying the next book');
        $this->assertSame('paused', $summary['books'][0]['status']);
        $this->assertSame('spending_limit', $summary['stopped']);
    }

    public function test_a_run_where_nothing_ran_is_not_used_for_comparison(): void
    {
        app(CorpusRegistry::class)->register();
        $this->fakePipeline(new \RuntimeException('Request rate limit has been exceeded.'));
        $runner = app(CorpusRunner::class);
        $failed = $runner->run('af', 'v8', ['top']);
        $this->assertSame(0, $failed['totals']['ok']);

        $this->travel(2)->seconds();
        $this->fakePipeline();
        $good = $runner->run('af', 'v8', ['top']);
        $this->assertNull($runner->previousRun($good), 'the failed run is no baseline');

        $this->travel(2)->seconds();
        $next = $runner->run('af', 'v8', ['top']);
        $this->assertSame($good['run_id'], $runner->previousRun($next)['run_id']);
    }

    public function test_only_available_engines_and_languages_run(): void
    {
        $this->expectExceptionMessage("Engine 'scene' is not available yet");
        app(CorpusRunner::class)->run('af', 'scene');
    }

    public function test_estimate_comes_from_past_cost_per_page(): void
    {
        $runner = app(CorpusRunner::class);
        $this->assertNull($runner->estimateZar('af', 32));

        $book = Book::create(['title' => 'B', 'page_count' => 16, 'pdf_path' => 'b.pdf']);
        $t = Translation::create(['book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
        AiLedgerEntry::create(['provider' => 'openai', 'model' => 'gpt-4o', 'operation' => 'translate', 'translation_id' => $t->id,
            'units' => [], 'cost_zar' => 8.00, 'at' => now()]);
        AiLedgerEntry::create(['provider' => 'openai', 'model' => 'gpt-4o', 'operation' => 'translate', 'translation_id' => $t->id,
            'units' => [], 'cost_zar' => 0, 'cache_hit' => true, 'at' => now()]);

        $this->assertSame(16.0, $runner->estimateZar('af', 32)); // R8 per 16 pages
    }

    public function test_one_unusual_edition_does_not_swing_the_estimate(): void
    {
        foreach ([1.60, 1.60, 1.70, 39.47] as $i => $cost) { // the last: a run before a cost fix
            $book = Book::create(['title' => "B{$i}", 'page_count' => 16, 'pdf_path' => "b{$i}.pdf"]);
            $t = Translation::create(['book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);
            AiLedgerEntry::create(['provider' => 'openai', 'model' => 'gpt-4o', 'operation' => 'translate', 'translation_id' => $t->id,
                'units' => [], 'cost_zar' => $cost, 'at' => now()]);
        }

        $this->assertSame(1.65, app(CorpusRunner::class)->estimateZar('af', 16)); // median, not the R11 average
    }

    public function test_the_command_asks_before_spending_and_compares_with_the_last_run(): void
    {
        app(CorpusRegistry::class)->register();
        $this->fakePipeline();

        $this->artisan('corpus:run', ['--lang' => 'af'])
            ->expectsConfirmation('Spend AI credit on this run?', 'no')
            ->assertFailed();
        $this->assertSame(0, Translation::count());

        $this->artisan('corpus:run', ['--lang' => 'af', '--book' => ['top'], '--yes' => true])
            ->expectsOutputToContain('No previous run to compare with.')
            ->assertSuccessful();
        $this->travel(2)->seconds();
        $this->artisan('corpus:run', ['--lang' => 'af', '--book' => ['top'], '--yes' => true])
            ->expectsOutputToContain('Compared with ')
            ->assertSuccessful();
    }
}
