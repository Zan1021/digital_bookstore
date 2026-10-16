<?php

namespace App\Ai\ElevenLabs;

use App\Ai\Contracts\TtsProvider;
use App\Ai\SpeechResult;
use App\Ai\Usage;
use Illuminate\Support\Facades\Http;

/**
 * ElevenLabs text-to-speech behind TtsProvider (task 7.1): the voices list and speech with
 * character timestamps, exactly as NarrationService calls them today.
 */
class ElevenLabsTtsProvider implements TtsProvider
{
    public const DEFAULT_MODEL = 'eleven_multilingual_v2';

    private const BASE_URL = 'https://api.elevenlabs.io/v1';

    public function voices(): array
    {
        $response = Http::withHeaders(['xi-api-key' => $this->apiKey()])->get(self::BASE_URL.'/voices');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch ElevenLabs voices: '.$response->body());
        }

        return collect($response->json('voices', []))->map(fn ($voice) => [
            'voice_id' => $voice['voice_id'],
            'name' => $voice['name'],
            'category' => $voice['category'] ?? 'premade',
            'labels' => $voice['labels'] ?? [],
            'preview_url' => $voice['preview_url'] ?? null,
        ])->values()->all();
    }

    public function speak(string $text, string $voiceId, array $settings = [], array $options = []): SpeechResult
    {
        $model = (string) ($options['model'] ?? self::DEFAULT_MODEL);

        $started = hrtime(true);
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey(),
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::BASE_URL."/text-to-speech/{$voiceId}/with-timestamps", [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => $settings,
        ]);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($response->failed()) {
            throw new \RuntimeException('ElevenLabs TTS failed: '.$response->body());
        }

        $audio = $response->json('audio_base64');
        if (! $audio) {
            throw new \RuntimeException('No audio data in response');
        }

        return new SpeechResult(
            base64_decode($audio),
            $response->json('alignment'),
            new Usage('elevenlabs', $model, (string) ($options['operation'] ?? 'speak'),
                ['characters' => mb_strlen($text)], $ms),
        );
    }

    private function apiKey(): string
    {
        return (string) config('services.elevenlabs.api_key');
    }
}
