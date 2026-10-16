<?php

namespace Tests\Feature;

use App\Ai\AiModels;
use App\Ai\MeteredProvider;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/** Task 7.3: AI model names come from config/ai.php per operation, not from code. */
class AiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_operation_the_services_use_has_a_model(): void
    {
        foreach (['translate', 'metadata', 'visual_qa', 'locate_art_text', 'art_repair', 'narrate'] as $operation) {
            $this->assertNotSame('', AiModels::for($operation), $operation);
        }
        $this->assertSame(['gpt-4o', 'gpt-4o-mini'], AiModels::chain('translate'));
    }

    public function test_an_unconfigured_operation_is_an_error_not_a_guess(): void
    {
        $this->expectExceptionMessage("No AI model configured for operation 'poetry'");
        AiModels::for('poetry');
    }

    public function test_resolve_prefers_explicit_then_operation_then_default(): void
    {
        config(['ai.operations.visual_qa.model' => 'vision-x', 'ai.defaults.vision' => 'vision-default']);

        $this->assertSame('pinned', AiModels::resolve(['model' => 'pinned', 'operation' => 'visual_qa'], 'vision'));
        $this->assertSame('vision-x', AiModels::resolve(['operation' => 'visual_qa'], 'vision'));
        $this->assertSame('vision-default', AiModels::resolve(['operation' => 'unlisted'], 'vision'));
        $this->assertSame('vision-default', AiModels::resolve([], 'vision'));
    }

    public function test_a_fallback_equal_to_the_main_model_is_not_tried_twice(): void
    {
        config(['ai.operations.translate.fallback' => 'gpt-4o']);
        $this->assertSame(['gpt-4o'], AiModels::chain('translate'));

        config(['ai.operations.translate.fallback' => null]);
        $this->assertSame(['gpt-4o'], AiModels::chain('translate'));
    }

    public function test_translation_uses_the_configured_model(): void
    {
        config(['ai.operations.translate.model' => 'gpt-test-translate']);
        OpenAI::fake([CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hallo'], 'finish_reason' => 'stop']]])]);

        $chat = new \ReflectionMethod(TranslationService::class, 'chatWithRetry');
        $this->assertSame('Hallo', $chat->invoke(app(TranslationService::class), ['messages' => [['role' => 'user', 'content' => 'Hello']]]));

        OpenAI::assertSent(Chat::class, fn ($m, array $p) => $p['model'] === 'gpt-test-translate');
    }

    public function test_changing_an_operations_model_does_not_reuse_the_old_models_answer(): void
    {
        Storage::fake(config('assets.disk'));
        $fake = new FakeAi;
        $ai = new MeteredProvider($fake, $fake, $fake, $fake);
        $ask = fn () => $ai->complete([['role' => 'user', 'content' => 'Hello']], ['operation' => 'translate']);

        $ask();
        $ask();
        $this->assertSame(1, $fake->calls);

        config(['ai.operations.translate.model' => 'gpt-4o-mini']);
        $ask();
        $this->assertSame(2, $fake->calls);
    }

    public function test_no_model_name_is_hard_coded_in_the_services(): void
    {
        $offenders = [];
        foreach (glob(app_path('Services/{,*/}*.php'), GLOB_BRACE) as $file) {
            foreach (file($file) as $n => $line) {
                $code = preg_replace('~(//|#|\*).*$~', '', $line); // ignore comments
                if (preg_match("~['\"](gpt-[\\w.-]+|dall-e-\\d|eleven_[\\w]+|o\\d-[\\w-]+)['\"]~", $code, $m)) {
                    $offenders[] = basename($file).':'.($n + 1).' '.$m[1];
                }
            }
        }

        $this->assertSame([], $offenders, 'model names belong in config/ai.php');
    }
}
