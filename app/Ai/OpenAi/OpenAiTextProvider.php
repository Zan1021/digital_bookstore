<?php

namespace App\Ai\OpenAi;

use App\Ai\Contracts\TranslationProvider;
use App\Ai\Contracts\VisionProvider;
use App\Ai\TextResult;
use App\Ai\Usage;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * OpenAI chat completions behind TranslationProvider and VisionProvider (task 7.1).
 * One call, no retry: retry and model fallback stay with the callers until task 7.4.
 */
class OpenAiTextProvider implements TranslationProvider, VisionProvider
{
    public const DEFAULT_MODEL = 'gpt-4o';

    public function complete(array $messages, array $options = []): TextResult
    {
        return $this->chat($messages, $options, 'complete');
    }

    public function analyze(string $system, string $prompt, array $imagePaths, array $options = []): TextResult
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($imagePaths as $path) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => self::dataUrl($path)]];
        }

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $content],
        ], $options, 'analyze');
    }

    private function chat(array $messages, array $options, string $defaultOperation): TextResult
    {
        $model = (string) ($options['model'] ?? self::DEFAULT_MODEL);
        $params = ['model' => $model, 'messages' => $messages];
        if (array_key_exists('temperature', $options)) {
            $params['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $params['max_tokens'] = $options['max_tokens'];
        }
        if (! empty($options['json'])) {
            $params['response_format'] = ['type' => 'json_object'];
        }

        $started = hrtime(true);
        $response = OpenAI::chat()->create($params);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        return new TextResult(
            trim((string) ($response->choices[0]->message->content ?? '')),
            new Usage('openai', $response->model ?: $model, (string) ($options['operation'] ?? $defaultOperation), [
                'prompt_tokens' => (int) ($response->usage?->promptTokens ?? 0),
                'completion_tokens' => (int) ($response->usage?->completionTokens ?? 0),
            ], $ms),
        );
    }

    private static function dataUrl(string $path): string
    {
        $mime = @mime_content_type($path) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode((string) file_get_contents($path));
    }
}
