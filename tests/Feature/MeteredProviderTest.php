<?php

namespace Tests\Feature;

use App\Ai\Contracts\ImageEditProvider;
use App\Ai\Contracts\TranslationProvider;
use App\Ai\Contracts\TtsProvider;
use App\Ai\Contracts\VisionProvider;
use App\Ai\ImageResult;
use App\Ai\MeteredProvider;
use App\Ai\Pricing;
use App\Ai\SpeechResult;
use App\Ai\SpendingLimitReached;
use App\Ai\TextResult;
use App\Ai\Usage;
use App\Models\AiLedgerEntry;
use App\Models\AiSpendingLimit;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 7.2: the metered decorator writes the ledger, reuses cached results and enforces
 * monthly spending limits. The real providers are replaced by counting stand-ins.
 */
class MeteredProviderTest extends TestCase
{
    use RefreshDatabase;

    private FakeAi $fake;

    private MeteredProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
        config(['ai.usd_to_zar' => 20.0]);
        $this->fake = new FakeAi;
        $this->ai = new MeteredProvider($this->fake, $this->fake, $this->fake, $this->fake);
    }

    private function ask(string $q = 'Hello', array $options = []): TextResult
    {
        return $this->ai->complete([['role' => 'user', 'content' => $q]], $options + ['operation' => 'translate']);
    }

    public function test_each_call_writes_a_priced_ledger_row(): void
    {
        $book = Book::create(['title' => 'Kolulu', 'page_count' => 1, 'pdf_path' => 'books/pdfs/k.pdf']);

        $result = $this->ask('Hello', ['book_id' => $book->id]);

        $this->assertSame('answer:Hello', $result->text);
        $row = AiLedgerEntry::sole();
        $this->assertSame(['openai', 'gpt-4o-2024-08-06', 'translate', $book->id, false],
            [$row->provider, $row->model, $row->operation, $row->book_id, $row->cache_hit]);
        $this->assertSame(['prompt_tokens' => 1000, 'completion_tokens' => 500], $row->units);
        // gpt-4o: 1000 × $2.50/M + 500 × $10/M = $0.0075 → R0.15 at R20/$
        $this->assertEqualsWithDelta(0.15, $row->cost_zar, 0.0001);
        $this->assertSame(64, strlen($row->input_hash));
    }

    public function test_the_same_input_is_answered_from_the_cache_for_free(): void
    {
        $first = $this->ask();
        $second = $this->ask();

        $this->assertSame(1, $this->fake->calls);
        $this->assertSame($first->text, $second->text);
        $this->assertTrue($second->usage->cached);
        $rows = AiLedgerEntry::orderBy('id')->get();
        $this->assertSame([false, true], $rows->pluck('cache_hit')->all());
        $this->assertSame(0.0, $rows[1]->cost_zar);
        $this->assertSame($rows[0]->input_hash, $rows[1]->input_hash);
    }

    public function test_bookkeeping_options_do_not_change_the_cache_key_but_settings_do(): void
    {
        $this->ask('Hello', ['book_id' => null]);
        $this->ask('Hello', ['translation_id' => null, 'operation' => 'transcreate']);
        $this->assertSame(1, $this->fake->calls, 'who pays is not part of the question');

        $this->ask('Hello', ['temperature' => 0.7]);
        $this->ask('Hello', ['model' => 'gpt-4o-mini']);
        $this->ask('Hello there');
        $this->assertSame(4, $this->fake->calls);
    }

    public function test_fresh_skips_the_cache_and_replaces_the_saved_answer(): void
    {
        $this->ask();
        $this->fake->suffix = ' (v2)';
        $this->assertSame('answer:Hello (v2)', $this->ask('Hello', ['fresh' => true])->text);
        $this->assertSame('answer:Hello (v2)', $this->ask()->text);
        $this->assertSame(2, $this->fake->calls);
    }

    public function test_images_are_keyed_by_their_content_not_their_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qa');
        file_put_contents($path, 'page-v1');
        $this->ai->analyze('sys', 'Compare.', [$path]);
        $this->ai->analyze('sys', 'Compare.', [$path]);
        file_put_contents($path, 'page-v2');
        $this->ai->analyze('sys', 'Compare.', [$path]);
        @unlink($path);

        $this->assertSame(2, $this->fake->calls);
    }

    public function test_edited_images_and_speech_are_cached_with_their_bytes(): void
    {
        $img = tempnam(sys_get_temp_dir(), 'im');
        file_put_contents($img, 'art');

        $this->ai->edit($img, $img, 'Rebuild.');
        $again = $this->ai->edit($img, $img, 'Rebuild.');
        $this->assertSame('PNG:Rebuild.', $again->bytes);

        $this->ai->speak('Hi', 'v1', ['stability' => 0.1]);
        $voice = $this->ai->speak('Hi', 'v1', ['stability' => 0.1]);
        $this->assertSame('MP3:Hi', $voice->audio);
        $this->assertSame(['characters' => ['H', 'i']], $voice->alignment);
        @unlink($img);

        $this->assertSame(2, $this->fake->calls);
        $this->assertCount(2, Storage::disk(config('assets.disk'))->files('ai-cache'));
    }

    public function test_a_missing_cached_file_means_asking_again(): void
    {
        $this->ai->speak('Hi', 'v1');
        Storage::disk(config('assets.disk'))->deleteDirectory('ai-cache');

        $this->assertSame('MP3:Hi', $this->ai->speak('Hi', 'v1')->audio);
        $this->assertSame(2, $this->fake->calls);
    }

    public function test_the_monthly_total_limit_stops_new_calls(): void
    {
        AiSpendingLimit::create(['operation' => null, 'monthly_limit_zar' => 0.30]);
        $this->ask('one');   // R0.15
        $this->ask('two');   // R0.30: limit now used up

        try {
            $this->ask('three');
            $this->fail('the limit should have stopped the call');
        } catch (SpendingLimitReached $e) {
            $this->assertNull($e->limitOperation);
            $this->assertEqualsWithDelta(0.30, $e->spentZar, 0.0001);
            $this->assertStringContainsString('R0.30', $e->getMessage());
        }
        $this->assertSame(2, $this->fake->calls);
        $this->assertSame(2, AiLedgerEntry::count());

        // Saved answers are free, so they are still served.
        $this->assertSame('answer:one', $this->ask('one')->text);
    }

    public function test_an_operation_limit_stops_only_that_operation(): void
    {
        AiSpendingLimit::create(['operation' => 'translate', 'monthly_limit_zar' => 0.10]);
        $this->ask('one');

        $this->assertSame('answer:other', $this->ask('other', ['operation' => 'metadata'])->text);
        $this->expectException(SpendingLimitReached::class);
        $this->ask('two');
    }

    public function test_spend_from_last_month_does_not_count(): void
    {
        AiSpendingLimit::create(['operation' => null, 'monthly_limit_zar' => 1.00]);
        AiLedgerEntry::create(['provider' => 'openai', 'model' => 'gpt-4o', 'operation' => 'translate', 'units' => [],
            'cost_zar' => 500, 'at' => now()->subMonthNoOverflow()->endOfMonth()]);

        $this->assertSame('answer:Hello', $this->ask()->text);
    }

    public function test_a_failed_call_records_nothing(): void
    {
        $this->fake->fail = true;
        try {
            $this->ask();
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, AiLedgerEntry::count());
        $this->fake->fail = false;
        $this->ask();
        $this->assertSame(2, $this->fake->calls, 'a failure is not cached');
    }

    public function test_the_voice_list_is_not_metered(): void
    {
        $this->assertSame([], $this->ai->voices());
        $this->assertSame(0, AiLedgerEntry::count());
    }

    public function test_pricing_per_provider(): void
    {
        $usage = fn ($p, $m, $u) => new Usage($p, $m, 'x', $u, 0);

        $this->assertEqualsWithDelta(0.60 * 20, Pricing::costZar($usage('openai', 'gpt-4o-mini', ['prompt_tokens' => 0, 'completion_tokens' => 1_000_000])), 0.0001);
        $this->assertEqualsWithDelta(0.30 * 20, Pricing::costZar($usage('elevenlabs', 'eleven_multilingual_v2', ['characters' => 1000])), 0.0001);
        $this->assertEqualsWithDelta(0.17 * 20, Pricing::costZar($usage('openai', 'gpt-image-1', ['images' => 1, 'input_tokens' => 0, 'output_tokens' => 0])), 0.0001);
        $this->assertEqualsWithDelta(40 * 20 / 1000, Pricing::costZar($usage('openai', 'gpt-image-1', ['images' => 1, 'input_tokens' => 0, 'output_tokens' => 1000])), 0.0001);
        // unknown model: priced at the provider default, never free
        $this->assertGreaterThan(0, Pricing::costZar($usage('openai', 'some-new-model', ['prompt_tokens' => 1000])));
    }
}

/** Counting stand-in for all four providers. */
class FakeAi implements ImageEditProvider, TranslationProvider, TtsProvider, VisionProvider
{
    public int $calls = 0;

    public string $suffix = '';

    public bool $fail = false;

    private function usage(string $provider, string $model, array $units): Usage
    {
        $this->calls++;
        if ($this->fail) {
            throw new \RuntimeException('provider down');
        }

        return new Usage($provider, $model, 'x', $units, 5);
    }

    public function complete(array $messages, array $options = []): TextResult
    {
        $usage = $this->usage('openai', 'gpt-4o-2024-08-06', ['prompt_tokens' => 1000, 'completion_tokens' => 500]);

        return new TextResult('answer:'.end($messages)['content'].$this->suffix, $usage);
    }

    public function analyze(string $system, string $prompt, array $imagePaths, array $options = []): TextResult
    {
        return new TextResult('{"ok":true}', $this->usage('openai', 'gpt-4o', ['prompt_tokens' => 800, 'completion_tokens' => 20]));
    }

    public function edit(string $imagePath, string $maskPath, string $prompt, array $options = []): ImageResult
    {
        return new ImageResult('PNG:'.$prompt, null, $this->usage('openai', 'gpt-image-1', ['images' => 1]));
    }

    public function voices(): array
    {
        return [];
    }

    public function speak(string $text, string $voiceId, array $settings = [], array $options = []): SpeechResult
    {
        return new SpeechResult('MP3:'.$text, ['characters' => ['H', 'i']],
            $this->usage('elevenlabs', 'eleven_multilingual_v2', ['characters' => mb_strlen($text)]));
    }
}
