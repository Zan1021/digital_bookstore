<?php

namespace App\Ai;

use App\Ai\Contracts\ImageEditProvider;
use App\Ai\Contracts\TranslationProvider;
use App\Ai\Contracts\TtsProvider;
use App\Ai\Contracts\VisionProvider;
use App\Assets\AssetStorage;
use App\Models\AiCacheEntry;
use App\Models\AiLedgerEntry;
use App\Models\AiSpendingLimit;

/**
 * The decorator every AI call passes through (task 7.2, design §18):
 *  - reuses a saved result when the same input was answered before (Req 3.5),
 *  - refuses the call when a monthly spending limit is used up (Req 22.5),
 *  - writes one ai_ledger row per call or cache hit, priced in rand (Req 22.3).
 *
 * Extra $options read here (never sent to the provider's cache key):
 *  book_id, translation_id: who the cost belongs to; fresh (bool): skip the cache lookup
 *  and ask the provider again (the new answer replaces the saved one).
 */
class MeteredProvider implements ImageEditProvider, TranslationProvider, TtsProvider, VisionProvider
{
    /** Options that describe bookkeeping, not the question asked. */
    private const LEDGER_OPTIONS = ['operation', 'book_id', 'translation_id', 'fresh'];

    public function __construct(
        private readonly TranslationProvider $text,
        private readonly VisionProvider $vision,
        private readonly ImageEditProvider $images,
        private readonly TtsProvider $tts,
    ) {}

    public function complete(array $messages, array $options = []): TextResult
    {
        return $this->metered('text', 'complete', $options,
            ['messages' => $messages],
            fn () => $this->text->complete($messages, $options));
    }

    public function analyze(string $system, string $prompt, array $imagePaths, array $options = []): TextResult
    {
        return $this->metered('text', 'analyze', $options,
            ['system' => $system, 'prompt' => $prompt, 'images' => array_map(self::fileHash(...), $imagePaths)],
            fn () => $this->vision->analyze($system, $prompt, $imagePaths, $options));
    }

    public function edit(string $imagePath, string $maskPath, string $prompt, array $options = []): ImageResult
    {
        return $this->metered('image', 'image_edit', $options,
            ['image' => self::fileHash($imagePath), 'mask' => self::fileHash($maskPath), 'prompt' => $prompt],
            fn () => $this->images->edit($imagePath, $maskPath, $prompt, $options));
    }

    /** The voice list is free: no ledger row, no limit. */
    public function voices(): array
    {
        return $this->tts->voices();
    }

    public function speak(string $text, string $voiceId, array $settings = [], array $options = []): SpeechResult
    {
        return $this->metered('speech', 'speak', $options,
            ['text' => $text, 'voice' => $voiceId, 'settings' => $settings],
            fn () => $this->tts->speak($text, $voiceId, $settings, $options));
    }

    private function metered(string $kind, string $defaultOperation, array $options, array $input, \Closure $call): TextResult|ImageResult|SpeechResult
    {
        $operation = (string) ($options['operation'] ?? $defaultOperation);
        $hash = $this->inputHash($kind, $defaultOperation, $input, $options);

        if (empty($options['fresh']) && ($hit = $this->fromCache($kind, $hash))) {
            $this->record($hit->usage, $operation, $options, $hash, 0.0);

            return $hit;
        }

        $this->ensureWithinLimits($operation);

        $result = $call();
        $this->record($result->usage, $operation, $options, $hash, Pricing::costZar($result->usage));
        $this->saveToCache($kind, $hash, $result);

        return $result;
    }

    private function inputHash(string $kind, string $method, array $input, array $options): string
    {
        $settings = array_diff_key($options, array_flip(self::LEDGER_OPTIONS));
        ksort($settings);

        return hash('sha256', json_encode([$kind, $method, $input, $settings], JSON_UNESCAPED_UNICODE));
    }

    private static function fileHash(string $path): string
    {
        return (string) hash_file('sha256', $path);
    }

    /** Refuse the call when the monthly total or this operation's limit is used up. */
    private function ensureWithinLimits(string $operation): void
    {
        $limits = AiSpendingLimit::query()
            ->where(fn ($q) => $q->whereNull('operation')->orWhere('operation', $operation))
            ->get();

        foreach ($limits as $limit) {
            $spent = AiLedgerEntry::spentThisMonth($limit->operation);
            if ($spent >= $limit->monthly_limit_zar) {
                throw new SpendingLimitReached($limit->operation, $limit->monthly_limit_zar, $spent);
            }
        }
    }

    private function record(Usage $usage, string $operation, array $options, string $hash, float $costZar): void
    {
        AiLedgerEntry::create([
            'provider' => $usage->provider,
            'model' => $usage->model,
            'operation' => $operation,
            'book_id' => $options['book_id'] ?? null,
            'translation_id' => $options['translation_id'] ?? null,
            'units' => $usage->units,
            'cost_zar' => $costZar,
            'duration_ms' => $usage->durationMs,
            'cache_hit' => $usage->cached,
            'input_hash' => $hash,
            'at' => now(),
        ]);
    }

    private function fromCache(string $kind, string $hash): TextResult|ImageResult|SpeechResult|null
    {
        $started = hrtime(true);
        $entry = AiCacheEntry::query()->where('input_hash', $hash)->where('kind', $kind)->first();
        if (! $entry) {
            return null;
        }

        $bytes = null;
        if ($entry->blob_path !== null) {
            $disk = AssetStorage::disk();
            if (! $disk->exists($entry->blob_path)) {
                return null; // saved file is gone: ask the provider again
            }
            $bytes = $disk->get($entry->blob_path);
        }

        $usage = new Usage($entry->provider, $entry->model, '', $entry->units ?? [],
            (int) ((hrtime(true) - $started) / 1_000_000), cached: true);

        return match ($kind) {
            'text' => new TextResult((string) $entry->text, $usage),
            'image' => new ImageResult($bytes, null, $usage),
            'speech' => new SpeechResult((string) $bytes, $entry->alignment, $usage),
        };
    }

    private function saveToCache(string $kind, string $hash, TextResult|ImageResult|SpeechResult $result): void
    {
        $blob = match (true) {
            $result instanceof ImageResult => $result->bytes,
            $result instanceof SpeechResult => $result->audio,
            default => null,
        };
        // A link-only image is not saved: provider links expire.
        if ($result instanceof ImageResult && $blob === null) {
            return;
        }

        $blobPath = null;
        if ($blob !== null) {
            $blobPath = "ai-cache/{$hash}.".($kind === 'image' ? 'png' : 'mp3');
            AssetStorage::disk()->put($blobPath, $blob);
        }

        AiCacheEntry::updateOrCreate(['input_hash' => $hash], [
            'kind' => $kind,
            'provider' => $result->usage->provider,
            'model' => $result->usage->model,
            'units' => $result->usage->units,
            'text' => $result instanceof TextResult ? $result->text : null,
            'alignment' => $result instanceof SpeechResult ? $result->alignment : null,
            'blob_path' => $blobPath,
        ]);
    }
}
