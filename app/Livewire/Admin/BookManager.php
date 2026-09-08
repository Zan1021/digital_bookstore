<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\Translation;
use App\Services\NarrationService;
use App\Services\PdfService;
use App\Services\PdfTranslationService;
use App\Services\TranslationService;
use Livewire\Component;

class BookManager extends Component
{
    public Book $book;
    public string $selectedLanguage = '';
    public string $selectedVoice = '';
    public array $availableVoices = [];
    public bool $translating = false;
    public bool $narrating = false;
    public ?array $suggestedMetadata = null;
    public string $activeTab = 'overview';

    // Reading settings
    public int $narrationStartPage = 3;
    public ?int $narrationEndPage = null;
    public int $cropPercent = 0;
    public bool $cropEnabled = false;

    public function mount(Book $book)
    {
        $this->book = $book->load(['pages', 'translations.translatedPages', 'narrations']);
        $this->narrationStartPage = $book->narration_start_page ?? 3;
        $this->narrationEndPage = $book->narration_end_page ?? max(3, $book->page_count - 2);
        $this->cropPercent = $book->crop_percent ?? 0;
        $this->cropEnabled = (bool) $book->crop_enabled;
    }

    public function switchTab(string $tab)
    {
        $this->activeTab = $tab;
    }

    public function suggestMetadata()
    {
        $service = app(PdfService::class);
        $this->suggestedMetadata = $service->suggestMetadata($this->book);
    }

    public function applyMetadata()
    {
        if (!$this->suggestedMetadata) {
            return;
        }

        $this->book->update([
            'title' => $this->suggestedMetadata['title'] ?? $this->book->title,
            'author' => $this->suggestedMetadata['author'] ?? $this->book->author,
            'description' => $this->suggestedMetadata['description'] ?? $this->book->description,
            'category' => $this->suggestedMetadata['category'] ?? $this->book->category,
            'age_group' => $this->suggestedMetadata['age_group'] ?? $this->book->age_group,
            'metadata' => $this->suggestedMetadata,
        ]);

        $this->book->refresh();
        $this->suggestedMetadata = null;
    }

    /**
     * Dispatch a queued translation for the chosen language and return immediately
     * (spec Req 3.1). The edition + ProcessingJob drive the live status shown via
     * wire:poll. A demo fallback runs synchronously when TRANSLATION_SYNC=true or no
     * queue worker is expected (spec Req 3 / Task 12).
     */
    public function translate()
    {
        if (empty($this->selectedLanguage)) {
            return;
        }

        $langName = TranslationService::SUPPORTED_LANGUAGES[$this->selectedLanguage] ?? $this->selectedLanguage;

        // Create/reuse the edition immediately so the UI can show it as TRANSLATING.
        $edition = Translation::updateOrCreate(
            ['book_id' => $this->book->id, 'language_code' => $this->selectedLanguage],
            ['language_name' => $langName, 'status' => 'processing',
             'render_status' => Translation::STATE_TRANSLATING],
        );

        if (config('bookstore.translation_sync', env('TRANSLATION_SYNC', false))) {
            // Synchronous demo fallback — runs in-request.
            \App\Jobs\TranslateEditionJob::dispatchSync($edition->id);
        } else {
            \App\Jobs\TranslateEditionJob::dispatch($edition->id);
        }

        $this->book->refresh()->load(['translations.translatedPages']);
        $this->selectedLanguage = '';
        session()->flash('success', "{$langName} translation queued. This page updates as it progresses.");
    }

    /**
     * Re-dispatch translation for an edition that failed or needs a fresh run
     * (spec Req 3.4) — no re-upload required.
     */
    public function retryTranslation(int $translationId)
    {
        $edition = $this->book->translations()->findOrFail($translationId);
        $edition->forceFill(['render_status' => Translation::STATE_TRANSLATING])->save();

        if (config('bookstore.translation_sync', env('TRANSLATION_SYNC', false))) {
            \App\Jobs\TranslateEditionJob::dispatchSync($edition->id);
        } else {
            \App\Jobs\TranslateEditionJob::dispatch($edition->id);
        }

        $this->book->refresh()->load(['translations.translatedPages']);
        session()->flash('success', "{$edition->language_name} translation re-queued.");
    }

    /**
     * True while any edition is mid-flight — the view polls with wire:poll only when
     * this is set, so a settled page does not poll forever.
     */
    public function getIsAnyTranslatingProperty(): bool
    {
        return $this->book->translations->contains(function ($t) {
            return in_array($t->render_status, [
                Translation::STATE_TRANSLATING,
                Translation::STATE_RENDERING,
                Translation::STATE_AUTOMATED_QA,
            ], true);
        });
    }

    public function renderPdf(int $translationId)
    {
        set_time_limit(300); // 5 minutes for PDF rendering

        $translation = $this->book->translations()->findOrFail($translationId);

        try {
            $service = app(PdfTranslationService::class);
            $outputPath = $service->createTranslatedPdf($this->book, $translation);

            // createTranslatedPdf already persisted render_status + qa_report from the
            // render gate. Fail-closed (§13): a page that failed the hard-constraint
            // gate must NOT be presented as a finished/approved render.
            $translation->refresh();
            $translation->update([
                'rendered_pdf_path' => $outputPath,
                'status' => $translation->isPublishable() ? 'rendered' : 'needs_review',
            ]);

            $this->book->refresh()->load(['translations.translatedPages']);

            if ($translation->isPublishable()) {
                $this->dispatch('celebration', message: "📄 {$translation->language_name} PDF rendered and passed layout QA.");
            } else {
                $pages = collect($translation->qa_report['review_pages'] ?? [])->implode(', ');
                session()->flash('error',
                    "⚠ {$translation->language_name} PDF rendered but FAILED layout QA on page(s): {$pages}. "
                    . "Marked NEEDS_LAYOUT_REVIEW — not publishable until reviewed."
                );
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'PDF rendering failed: ' . $e->getMessage());
        }
    }

    public function deleteTranslation(int $translationId)
    {
        $translation = $this->book->translations()->findOrFail($translationId);
        $langName = $translation->language_name;

        // Delete translated pages
        $translation->translatedPages()->delete();

        // Delete translated PDF if it exists
        $pdfPath = "books/translated/{$this->book->id}_{$translation->language_code}.pdf";
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($pdfPath)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($pdfPath);
        }

        $translation->delete();
        $this->book->refresh()->load(['translations.translatedPages']);
        session()->flash('success', "Deleted {$langName} translation.");
    }

    public function deleteNarration(int $narrationId)
    {
        $narration = $this->book->narrations()->findOrFail($narrationId);
        $langName = $narration->language_name;

        // Delete audio files
        if ($narration->page_audio_paths) {
            foreach ($narration->page_audio_paths as $path) {
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                }
                $timingPath = str_replace('.mp3', '-timing.json', $path);
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($timingPath)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($timingPath);
                }
            }
        }

        if ($narration->audio_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($narration->audio_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($narration->audio_path);
        }

        $narration->delete();
        $this->book->refresh()->load(['narrations']);
        session()->flash('success', "Deleted {$langName} narration.");
    }

    public function loadVoices()
    {
        try {
            $service = app(NarrationService::class);
            $this->availableVoices = $service->getVoices();
        } catch (\Throwable $e) {
            session()->flash('error', 'Failed to load voices: ' . $e->getMessage());
        }
    }

    public function narrate()
    {
        if (empty($this->selectedVoice) || empty($this->selectedLanguage)) {
            return;
        }

        // Narration gate (spec Req 2 & 5): the source language narrates freely; a
        // translated edition may only be narrated once it is APPROVED. Re-checked here
        // at execution time, not just via a hidden button.
        if ($this->selectedLanguage !== 'en') {
            $edition = $this->book->translations->where('language_code', $this->selectedLanguage)->first();
            if (! $edition || ! $edition->isApprovedForNarration()) {
                session()->flash('error',
                    'This translated edition must be reviewed and approved before it can be narrated.');
                return;
            }
        }

        set_time_limit(600); // 10 minutes — generating audio per page
        $this->narrating = true;

        try {
            $voice = collect($this->availableVoices)->firstWhere('voice_id', $this->selectedVoice);
            $voiceName = $voice['name'] ?? 'Unknown';

            $service = app(NarrationService::class);
            $service->narrate($this->book, $this->selectedLanguage, $this->selectedVoice, $voiceName);
            $this->book->refresh()->load(['narrations']);

            $langName = $this->selectedLanguage === 'en' ? 'English' : ($this->book->translations->where('language_code', $this->selectedLanguage)->first()?->language_name ?? $this->selectedLanguage);
            $this->dispatch('celebration', message: "🎙️ {$langName} narration complete! Your book now speaks with the voice of {$voiceName}.");
        } catch (\Throwable $e) {
            session()->flash('error', 'Narration failed: ' . $e->getMessage());
        }

        $this->narrating = false;
    }

    public function render()
    {
        return view('livewire.admin.book-manager', [
            'languages' => TranslationService::SUPPORTED_LANGUAGES,
        ])->layout('layouts.admin');
    }

    public function saveSettings()
    {
        $this->book->update([
            'narration_start_page' => $this->narrationStartPage,
            'narration_end_page' => $this->narrationEndPage,
            'crop_percent' => $this->cropPercent,
            'crop_enabled' => $this->cropEnabled,
        ]);

        $this->book->refresh();
        session()->flash('success', 'Settings saved.');
    }
}
