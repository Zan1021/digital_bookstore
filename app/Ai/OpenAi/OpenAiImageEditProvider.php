<?php

namespace App\Ai\OpenAi;

use App\Ai\AiModels;
use App\Ai\Contracts\ImageEditProvider;
use App\Ai\ImageResult;
use App\Ai\Usage;
use OpenAI\Laravel\Facades\OpenAI;

/** OpenAI images.edit behind ImageEditProvider (task 7.1). One call; the caller keeps its retry. */
class OpenAiImageEditProvider implements ImageEditProvider
{
    public function edit(string $imagePath, string $maskPath, string $prompt, array $options = []): ImageResult
    {
        $model = AiModels::resolve($options, 'image_edit');
        $params = [
            'model' => $model,
            // Fresh handles on every call: a consumed stream can't be re-sent on a retry.
            'image' => fopen($imagePath, 'r'),
            'mask' => fopen($maskPath, 'r'),
            'prompt' => $prompt,
            'n' => 1,
        ];
        if (isset($options['size'])) {
            $params['size'] = $options['size'];
        }

        $started = hrtime(true);
        $response = OpenAI::images()->edit($params);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        $item = $response->data[0] ?? null;
        // The SDK types these as non-nullable strings; treat empty string as absent.
        $b64 = (string) ($item->b64_json ?? '');
        $url = (string) ($item->url ?? '');

        return new ImageResult(
            $b64 !== '' ? base64_decode($b64) : null,
            $url !== '' ? $url : null,
            new Usage('openai', $model, (string) ($options['operation'] ?? 'image_edit'), [
                'images' => 1,
                'input_tokens' => (int) ($response->usage?->inputTokens ?? 0),
                'output_tokens' => (int) ($response->usage?->outputTokens ?? 0),
            ], $ms),
        );
    }
}
