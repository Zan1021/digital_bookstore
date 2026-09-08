<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Narration;
use App\Models\Translation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class NarrationService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.elevenlabs.io/v1';
    private float $currentStability = 0.10;
    private float $currentStyle = 1.0;
    private int $currentSpeed = 30;

    public function __construct()
    {
        $this->apiKey = config('services.elevenlabs.api_key', '');
    }

    /**
     * Get available voices from ElevenLabs.
     */
    public function getVoices(): array
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->get("{$this->baseUrl}/voices");

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch ElevenLabs voices: ' . $response->body());
        }

        $voices = $response->json('voices', []);

        return collect($voices)->map(function ($voice) {
            return [
                'voice_id' => $voice['voice_id'],
                'name' => $voice['name'],
                'category' => $voice['category'] ?? 'premade',
                'labels' => $voice['labels'] ?? [],
                'preview_url' => $voice['preview_url'] ?? null,
            ];
        })->toArray();
    }

    /**
     * Generate narration for a book in a specific language.
     */
    public function narrate(Book $book, string $languageCode, string $voiceId, string $voiceName, int $dramaLevel = 90, int $speedLevel = 30): Narration
    {
        // Independent narration gate (spec Req 5.4): a translated edition must be
        // APPROVED before narration. Re-checked here so the service is safe even if
        // called outside the UI. The source language is always allowed.
        if ($languageCode !== 'en' && $languageCode !== $book->original_language) {
            $edition = $book->translations()
                ->where('language_code', $languageCode)->first();
            if (! $edition || ! $edition->isApprovedForNarration()) {
                throw new \RuntimeException(
                    "Edition '{$languageCode}' is not approved for narration. "
                    . 'Review and approve the translation first.'
                );
            }
        }

        $narration = Narration::updateOrCreate(
            ['book_id' => $book->id, 'language_code' => $languageCode],
            [
                'language_name' => TranslationService::SUPPORTED_LANGUAGES[$languageCode]
                    ?? ($languageCode === $book->original_language ? 'English' : $languageCode),
                'voice_id' => $voiceId,
                'voice_name' => $voiceName,
                'status' => 'processing',
                'is_outdated' => false,
            ]
        );

        // Get text: use translation if available, otherwise original
        $pages = $this->getPagesText($book, $languageCode);

        if (empty($pages)) {
            $narration->update(['status' => 'failed']);
            throw new \RuntimeException('No text available for narration');
        }

        // Calculate voice settings from drama/speed levels
        $this->currentStability = max(0.05, 1.0 - ($dramaLevel / 100)); // High drama = low stability
        $this->currentStyle = $dramaLevel / 100; // High drama = high style
        $this->currentSpeed = $speedLevel; // Used for text manipulation

        $pageAudioPaths = [];

        foreach ($pages as $pageNumber => $text) {
            if (empty(trim($text))) {
                continue;
            }

            $audioPath = $this->generateAudio($text, $voiceId, $book->id, $languageCode, $pageNumber);
            $pageAudioPaths[$pageNumber] = $audioPath;
        }

        // Use the first story page audio as the "main" audio
        $mainAudio = !empty($pageAudioPaths) ? reset($pageAudioPaths) : null;

        $narration->update([
            'status' => 'completed',
            'audio_path' => $mainAudio,
            'page_audio_paths' => $pageAudioPaths,
        ]);

        return $narration->fresh();
    }

    /**
     * Generate audio for text using ElevenLabs WITH word timestamps.
     */
    private function generateAudio(string $text, string $voiceId, int $bookId, string $langCode, string|int $identifier): string
    {
        // Clean text: remove page numbers (standalone digits at start of text)
        $text = trim($text);
        $text = preg_replace('/^\d+\s*\n?/', '', $text); // Remove leading page number
        $text = preg_replace('/\n\d+\s*$/', '', $text); // Remove trailing page number
        $text = trim($text);

        if (empty($text)) {
            throw new \RuntimeException('No text to narrate after cleaning');
        }

        // Slow down for kids: add pauses based on speed setting
        if ($this->currentSpeed < 50) {
            $text = preg_replace('/\.\s+/', '... ', $text);  // Period → dramatic pause
            $text = preg_replace('/,\s+/', ',... ', $text);   // Comma → slight pause
            $text = preg_replace('/!\s+/', '!... ', $text);   // Exclamation → pause
        }

        // Limit text per request
        $text = mb_substr($text, 0, 2000);

        // Use the WITH TIMESTAMPS endpoint for word-level sync
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$this->baseUrl}/text-to-speech/{$voiceId}/with-timestamps", [
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => $this->currentStability,
                'similarity_boost' => 0.75,
                'style' => $this->currentStyle,
                'use_speaker_boost' => true,
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('ElevenLabs TTS failed: ' . $response->body());
        }

        $data = $response->json();

        // Extract audio (base64 encoded)
        $audioBase64 = $data['audio_base64'] ?? null;
        if (!$audioBase64) {
            throw new \RuntimeException('No audio data in response');
        }

        $audioContent = base64_decode($audioBase64);
        $path = "narrations/book-{$bookId}/{$langCode}/page-{$identifier}.mp3";
        Storage::disk('public')->put($path, $audioContent);

        // Save word timestamps if available
        $alignment = $data['alignment'] ?? null;
        if ($alignment) {
            $timingPath = "narrations/book-{$bookId}/{$langCode}/page-{$identifier}-timing.json";
            Storage::disk('public')->put($timingPath, json_encode($alignment));
        }

        return $path;
    }

    /**
     * Get text for each page (translated or original).
     */
    private function getPagesText(Book $book, string $languageCode): array
    {
        $pages = [];

        if ($languageCode === $book->original_language) {
            // Use original extracted text
            foreach ($book->pages as $page) {
                if (!empty($page->extracted_text)) {
                    $pages[$page->page_number] = $page->extracted_text;
                }
            }
        } else {
            // Use translated text
            $translation = $book->translations()
                ->where('language_code', $languageCode)
                ->first();

            if ($translation) {
                foreach ($translation->translatedPages as $tp) {
                    $pages[$tp->page_number] = $tp->translated_text;
                }
            }
        }

        return $pages;
    }
}
