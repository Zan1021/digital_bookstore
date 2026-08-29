<?php

namespace App\Livewire\Admin;

use App\Models\Book;
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
        $this->narrationStartPage = $book->narration_start_page;
        $this->narrationEndPage = $book->narration_end_page;
        $this->cropPercent = $book->crop_percent;
        $this->cropEnabled = $book->crop_enabled;
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

    public function translate()
    {
        if (empty($this->selectedLanguage)) {
            return;
        }

        set_time_limit(600); // 10 minutes — translating page-by-page via OpenAI
        $this->translating = true;

        try {
            $service = app(TranslationService::class);
            $service->translate($this->book, $this->selectedLanguage);
            $this->book->refresh()->load(['translations.translatedPages']);

            $langName = TranslationService::SUPPORTED_LANGUAGES[$this->selectedLanguage] ?? $this->selectedLanguage;
            $pageCount = $this->book->translations->where('language_code', $this->selectedLanguage)->first()?->translatedPages->count() ?? 0;
            $reviewUrl = route('admin.review', ['book' => $this->book->id, 'language' => $this->selectedLanguage]);
            $this->dispatch('celebration', message: "🎉 {$langName} translation complete! {$pageCount} pages translated.", redirect: $reviewUrl);
        } catch (\Throwable $e) {
            session()->flash('error', 'Translation failed: ' . $e->getMessage());
        }

        $this->translating = false;
        $this->selectedLanguage = '';
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
