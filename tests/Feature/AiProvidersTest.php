<?php

namespace Tests\Feature;

use App\Ai\Contracts\ImageEditProvider;
use App\Ai\Contracts\TranslationProvider;
use App\Ai\Contracts\TtsProvider;
use App\Ai\Contracts\VisionProvider;
use App\Ai\ElevenLabs\ElevenLabsTtsProvider;
use App\Ai\MeteredProvider;
use App\Ai\OpenAi\OpenAiImageEditProvider;
use App\Ai\OpenAi\OpenAiTextProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Resources\Images;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Images\EditResponse;
use Tests\TestCase;

/**
 * Task 7.1: the AI provider interfaces and their OpenAI / ElevenLabs implementations.
 * Every test uses fakes; no real API is called.
 */
class AiProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function chatReply(string $content, string $model = 'gpt-4o-2024-08-06'): CreateResponse
    {
        return CreateResponse::fake([
            'model' => $model,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150],
        ]);
    }

    private function png(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ai').'.png';
        // 1x1 transparent PNG
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return $path;
    }

    public function test_the_container_wraps_each_interface_in_the_metered_provider(): void
    {
        foreach ([TranslationProvider::class, VisionProvider::class, ImageEditProvider::class, TtsProvider::class] as $contract) {
            $this->assertInstanceOf(MeteredProvider::class, app($contract));
        }
    }

    public function test_complete_sends_the_messages_and_reports_token_usage(): void
    {
        OpenAI::fake([$this->chatReply('  Hallo wêreld  ')]);

        $result = app(OpenAiTextProvider::class)->complete(
            [['role' => 'user', 'content' => 'Hello world']],
            ['model' => 'gpt-4o', 'temperature' => 0.3, 'operation' => 'translate'],
        );

        $this->assertSame('Hallo wêreld', $result->text);
        $this->assertSame('openai', $result->usage->provider);
        $this->assertSame('gpt-4o-2024-08-06', $result->usage->model);
        $this->assertSame('translate', $result->usage->operation);
        $this->assertSame(['prompt_tokens' => 120, 'completion_tokens' => 30], $result->usage->units);
        $this->assertGreaterThanOrEqual(0, $result->usage->durationMs);

        OpenAI::assertSent(Chat::class, fn (string $method, array $params) => $method === 'create'
            && $params['model'] === 'gpt-4o'
            && $params['temperature'] === 0.3
            && $params['messages'] === [['role' => 'user', 'content' => 'Hello world']]
            && ! isset($params['response_format']));
    }

    public function test_json_option_asks_for_a_json_answer(): void
    {
        OpenAI::fake([$this->chatReply('{"title":"Kolulu"}')]);

        $result = app(OpenAiTextProvider::class)->complete([['role' => 'user', 'content' => 'x']], ['json' => true]);

        $this->assertSame(['title' => 'Kolulu'], $result->json());
        OpenAI::assertSent(Chat::class, fn ($m, array $p) => $p['response_format'] === ['type' => 'json_object']
            && $p['model'] === OpenAiTextProvider::DEFAULT_MODEL);
    }

    public function test_analyze_sends_the_prompt_then_each_image_in_order(): void
    {
        OpenAI::fake([$this->chatReply('{"ok":true}')]);
        $a = $this->png();
        $b = $this->png();

        $result = app(OpenAiTextProvider::class)->analyze('You compare pages.', 'IMAGE 1 = original, IMAGE 2 = translated.', [$a, $b],
            ['temperature' => 0.0, 'json' => true, 'operation' => 'visual_qa']);

        $this->assertSame(['ok' => true], $result->json());
        $this->assertSame('visual_qa', $result->usage->operation);
        OpenAI::assertSent(Chat::class, function ($m, array $p) {
            $user = $p['messages'][1]['content'];

            return $p['messages'][0] === ['role' => 'system', 'content' => 'You compare pages.']
                && $user[0] === ['type' => 'text', 'text' => 'IMAGE 1 = original, IMAGE 2 = translated.']
                && count($user) === 3
                && str_starts_with($user[1]['image_url']['url'], 'data:image/png;base64,')
                && str_starts_with($user[2]['image_url']['url'], 'data:image/png;base64,');
        });
        @unlink($a);
        @unlink($b);
    }

    public function test_provider_errors_reach_the_caller(): void
    {
        OpenAI::fake([new \RuntimeException('Rate limit reached')]);

        $this->expectExceptionMessage('Rate limit reached');
        app(OpenAiTextProvider::class)->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function test_image_edit_returns_the_image_or_its_link(): void
    {
        $image = $this->png();
        $mask = $this->png();

        OpenAI::fake([
            EditResponse::fake(['data' => [['b64_json' => base64_encode('PNGBYTES')]]], strategy: \OpenAI\Testing\Enums\OverrideStrategy::Replace),
            EditResponse::fake(),
        ]);

        $bytes = app(OpenAiImageEditProvider::class)->edit($image, $mask, 'Rebuild the background.', ['size' => '1024x1024']);
        $this->assertSame('PNGBYTES', $bytes->bytes);
        $this->assertNull($bytes->url);
        $this->assertSame(['images' => 1, 'input_tokens' => 0, 'output_tokens' => 0], $bytes->usage->units);
        $this->assertSame('gpt-image-1', $bytes->usage->model);

        $link = app(OpenAiImageEditProvider::class)->edit($image, $mask, 'Rebuild the background.');
        $this->assertNull($link->bytes);
        $this->assertSame('https://openai.com/fake-image.png', $link->url);

        OpenAI::assertSent(Images::class, fn ($m, array $p) => $m === 'edit' && $p['prompt'] === 'Rebuild the background.'
            && $p['n'] === 1 && ($p['size'] ?? null) === '1024x1024' && is_resource($p['image']) && is_resource($p['mask']));
        @unlink($image);
        @unlink($mask);
    }

    public function test_tts_lists_voices(): void
    {
        config(['services.elevenlabs.api_key' => 'test-key']);
        Http::fake(['api.elevenlabs.io/v1/voices' => Http::response(['voices' => [
            ['voice_id' => 'v1', 'name' => 'Thandi', 'labels' => ['accent' => 'south african']],
        ]])]);

        $this->assertSame([[
            'voice_id' => 'v1', 'name' => 'Thandi', 'category' => 'premade',
            'labels' => ['accent' => 'south african'], 'preview_url' => null,
        ]], app(ElevenLabsTtsProvider::class)->voices());

        Http::assertSent(fn (Request $r) => $r->hasHeader('xi-api-key', 'test-key'));
    }

    public function test_tts_speaks_with_timestamps_and_counts_characters(): void
    {
        Http::fake(['api.elevenlabs.io/v1/text-to-speech/v1/with-timestamps' => Http::response([
            'audio_base64' => base64_encode('MP3'),
            'alignment' => ['characters' => ['H', 'i']],
        ])]);

        $settings = ['stability' => 0.1, 'similarity_boost' => 0.75, 'style' => 1.0, 'use_speaker_boost' => true];
        $speech = app(ElevenLabsTtsProvider::class)->speak('Hi', 'v1', $settings, ['operation' => 'narrate']);

        $this->assertSame('MP3', $speech->audio);
        $this->assertSame(['characters' => ['H', 'i']], $speech->alignment);
        $this->assertSame('elevenlabs', $speech->usage->provider);
        $this->assertSame('eleven_multilingual_v2', $speech->usage->model);
        $this->assertSame(['characters' => 2], $speech->usage->units);

        Http::assertSent(fn (Request $r) => $r['text'] === 'Hi' && $r['model_id'] === 'eleven_multilingual_v2'
            && $r['voice_settings'] === $settings);
    }

    public function test_tts_failure_is_an_error_not_silence(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => 'quota_exceeded'], 401)]);

        $this->expectExceptionMessage('ElevenLabs TTS failed');
        app(ElevenLabsTtsProvider::class)->speak('Hi', 'v1');
    }
}
