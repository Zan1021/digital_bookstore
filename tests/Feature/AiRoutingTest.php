<?php

namespace Tests\Feature;

use App\Ai\SpendingLimitReached;
use App\Jobs\GeneratePageRepairJob;
use App\Jobs\TranslateEditionJob;
use App\Models\AiLedgerEntry;
use App\Models\AiSpendingLimit;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use App\Services\NarrationService;
use App\Services\PdfService;
use App\Services\TranslationService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * Task 7.4: every AI call in the services goes through the provider layer, is charged to
 * its book and edition in the ledger, and a used-up spending limit pauses the work.
 */
class AiRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    private Translation $edition;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
        Http::preventStrayRequests();
        $this->book = Book::create(['title' => 'Kolulu', 'original_language' => 'en', 'page_count' => 1,
            'pdf_path' => 'books/pdfs/k.pdf']);
        $this->edition = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af',
            'language_name' => 'Afrikaans', 'status' => 'draft']);
    }

    private static function reply(string $content): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]]);
    }

    private function invokePrivate(object $service, string $method, ...$args): mixed
    {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    private function png(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pg').'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return $path;
    }

    public function test_no_service_calls_a_provider_directly(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_contains($path, '/app/Ai/')) {
                continue;
            }
            $code = file_get_contents($path);
            if (preg_match('~OpenAI::|OpenAI\\\\Laravel\\\\Facades|api\.elevenlabs\.io|xi-api-key~', $code, $m)) {
                $offenders[] = basename($path).': '.$m[0];
            }
        }

        $this->assertSame([], $offenders, 'AI calls go through App\Ai (R3, task 7.4)');
    }

    public function test_translation_calls_are_charged_to_the_edition(): void
    {
        OpenAI::fake([self::reply('Hallo')]);
        $translator = app(TranslationService::class);
        $this->invokePrivate($translator, 'chargeTo', $this->edition);

        $this->assertSame('Hallo', $this->invokePrivate($translator, 'chatWithRetry',
            ['messages' => [['role' => 'user', 'content' => 'Hello']], 'temperature' => 0.2]));

        $row = AiLedgerEntry::sole();
        $this->assertSame(['translate', $this->book->id, $this->edition->id],
            [$row->operation, $row->book_id, $row->translation_id]);
    }

    public function test_a_used_up_limit_stops_translation_without_retrying(): void
    {
        AiSpendingLimit::create(['operation' => 'translate', 'monthly_limit_zar' => 0]);
        OpenAI::fake([]);

        $this->expectException(SpendingLimitReached::class);
        try {
            $this->invokePrivate(app(TranslationService::class), 'chatWithRetry', ['messages' => [['role' => 'user', 'content' => 'Hello']]]);
        } finally {
            OpenAI::assertNothingSent();
        }
    }

    public function test_rate_limit_wording_in_any_case_is_retried_but_a_used_up_quota_is_not(): void
    {
        foreach (['Request rate limit has been exceeded.', 'Rate limit reached for gpt-4o', 'The engine is currently overloaded',
            'HTTP 429 Too Many Requests', 'We are experiencing high volume'] as $busy) {
            $this->assertTrue(TranslationService::isRetryable(new \RuntimeException($busy)), $busy);
        }
        $this->assertFalse(TranslationService::isRetryable(new \RuntimeException('You exceeded your current quota, please check your plan.')));
        $this->assertFalse(TranslationService::isRetryable(new \RuntimeException('Incorrect API key provided')));
    }

    public function test_metadata_suggestion_is_metered(): void
    {
        BookPage::create(['book_id' => $this->book->id, 'page_number' => 1, 'extracted_text' => 'Kolulu goes to school.']);
        OpenAI::fake([self::reply('{"title":"Kolulu"}')]);

        $this->assertSame(['title' => 'Kolulu'], app(PdfService::class)->suggestMetadata($this->book));
        $this->assertSame(['metadata', $this->book->id], [AiLedgerEntry::sole()->operation, AiLedgerEntry::sole()->book_id]);
    }

    public function test_visual_qa_is_metered_and_charged_to_the_edition(): void
    {
        OpenAI::fake([self::reply('{"ok":false,"severity":"major","issues":["overlap"]}')]);
        $qa = app(VisualQaService::class);
        (new \ReflectionProperty($qa, 'aiContext'))->setValue($qa, ['book_id' => $this->book->id, 'translation_id' => $this->edition->id]);
        $a = $this->png();
        $b = $this->png();

        $verdict = $this->invokePrivate($qa, 'askModel', $a, $b, 3);
        @unlink($a);
        @unlink($b);

        $this->assertSame(['ok' => false, 'severity' => 'major', 'issues' => ['overlap']], $verdict);
        $row = AiLedgerEntry::sole();
        $this->assertSame(['visual_qa', $this->edition->id], [$row->operation, $row->translation_id]);
    }

    public function test_narration_audio_is_metered(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(['audio_base64' => base64_encode('MP3'), 'alignment' => ['characters' => ['H']]])]);
        $narrator = app(NarrationService::class);
        (new \ReflectionProperty($narrator, 'aiContext'))->setValue($narrator, ['book_id' => $this->book->id, 'translation_id' => null]);

        $path = $this->invokePrivate($narrator, 'generateAudio', 'Hello Kolulu.', 'v1', $this->book->id, 'en', 3);

        $this->assertSame('MP3', Storage::disk(config('assets.disk'))->get($path));
        $row = AiLedgerEntry::sole();
        $this->assertSame(['elevenlabs', 'narrate', $this->book->id], [$row->provider, $row->operation, $row->book_id]);
        Http::assertSent(fn ($r) => $r['model_id'] === 'eleven_multilingual_v2');
    }

    public function test_a_translation_job_pauses_at_the_limit_instead_of_failing(): void
    {
        $this->app->instance(TranslationService::class, new class extends TranslationService
        {
            public function translateWithManifest(Book $book, string $languageCode): Translation
            {
                throw new SpendingLimitReached(null, 100, 101);
            }
        });

        dispatch_sync(new TranslateEditionJob($this->edition->id)); // no exception: nothing to retry

        $row = ProcessingJob::where('book_id', $this->book->id)->where('type', 'translation')->sole();
        $this->assertSame('paused', $row->status);
        $this->assertStringContainsString('R100.00', $row->error_message);
        $this->edition->refresh();
        $this->assertSame(Translation::STATE_NEEDS_LANGUAGE_REVIEW, $this->edition->render_status);
        $this->assertFalse($this->edition->isPublishable());
    }

    public function test_a_page_repair_job_pauses_at_the_limit(): void
    {
        $this->app->instance(IllustrationTextService::class, new class extends IllustrationTextService
        {
            public function repairPageGenerative(Book $book, Translation $translation, int $page): array
            {
                throw new SpendingLimitReached('art_repair', 50, 50);
            }
        });

        dispatch_sync(new GeneratePageRepairJob($this->book->id, $this->edition->id, 4));

        $row = ProcessingJob::where('book_id', $this->book->id)->where('type', 'page_repair')->sole();
        $this->assertSame('paused', $row->status);
        $this->assertStringContainsString("'art_repair'", $row->error_message);
    }
}
