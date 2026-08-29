<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Services\NarrationService;
use App\Services\PdfService;
use App\Services\TranslationService;
use Livewire\Component;
use Livewire\WithFileUploads;

class BookUpload extends Component
{
    use WithFileUploads;

    public $files = [];
    public array $results = [];
    public bool $processing = false;
    public int $processed = 0;
    public int $total = 0;
    public string $currentStep = 'upload'; // upload, crop, languages, voice, processing, done

    // Settings
    public bool $hasCropMarks = false;
    public int $cropPercent = 5;
    public string $previewPdfUrl = '';
    public ?array $detectedCrop = null;

    // Languages
    public bool $langEnglish = true;
    public bool $langAfrikaans = true;
    public bool $langZulu = true;

    // Narration
    public bool $enableNarration = true;
    public string $selectedVoice = '';
    public array $availableVoices = [];
    public int $dramaLevel = 90; // 0-100 (slider)
    public int $speedLevel = 30; // 0-100 (0=very slow, 100=fast)

    public function updatedFiles()
    {
        if (count($this->files) > 0) {
            // Store first file temporarily for preview
            $firstFile = $this->files[0];
            $tempPath = $firstFile->store('temp-previews', 'public');
            $this->previewPdfUrl = asset('storage/' . $tempPath);

            // Auto-detect TrimBox
            $pdfService = app(PdfService::class);
            $this->detectedCrop = $pdfService->detectCropMarks($tempPath);

            if ($this->detectedCrop && $this->detectedCrop['detected']) {
                $this->hasCropMarks = true;
                $this->cropPercent = (int) ceil($this->detectedCrop['crop_avg']);
            }

            $this->currentStep = 'crop';
            $this->loadVoices();
        }
    }

    public function loadVoices()
    {
        try {
            $service = app(NarrationService::class);
            $this->availableVoices = $service->getVoices();
            $george = collect($this->availableVoices)->first(fn($v) => stripos($v['name'], 'george') !== false);
            if ($george) {
                $this->selectedVoice = $george['voice_id'];
            } elseif (!empty($this->availableVoices)) {
                $this->selectedVoice = $this->availableVoices[0]['voice_id'];
            }
        } catch (\Throwable $e) {}
    }

    public function nextStep()
    {
        $steps = ['upload', 'crop', 'languages', 'voice', 'processing', 'done'];
        $currentIdx = array_search($this->currentStep, $steps);
        if ($currentIdx !== false && $currentIdx < count($steps) - 1) {
            $nextStep = $steps[$currentIdx + 1];
            if ($nextStep === 'processing') {
                $this->startProcessing();
            } else {
                $this->currentStep = $nextStep;
            }
        }
    }

    public function prevStep()
    {
        $steps = ['upload', 'crop', 'languages', 'voice', 'processing', 'done'];
        $currentIdx = array_search($this->currentStep, $steps);
        if ($currentIdx !== false && $currentIdx > 0) {
            $this->currentStep = $steps[$currentIdx - 1];
        }
    }

    public function goBack()
    {
        $this->currentStep = 'upload';
    }

    public function startProcessing()
    {
        $this->validate([
            'files' => 'required',
            'files.*' => 'file|mimes:pdf|max:102400',
        ]);

        // Extend execution time — narration + translation is slow (API calls)
        set_time_limit(600); // 10 minutes

        $this->currentStep = 'processing';
        $this->processing = true;
        $this->total = count($this->files);
        $this->processed = 0;
        $this->results = [];

        $pdfService = app(PdfService::class);
        $translationService = app(TranslationService::class);
        $narrationService = app(NarrationService::class);

        foreach ($this->files as $file) {
            try {
                // Check for duplicate title
                $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $title = preg_replace('/^\d+_/', '', $title); // Strip timestamp prefix
                $title = str_replace(['_', '-'], ' ', $title);
                $title = trim($title);

                $duplicate = Book::where('title', $title)->first();
                if ($duplicate) {
                    $this->results[] = [
                        'success' => false,
                        'filename' => $file->getClientOriginalName(),
                        'error' => "Duplicate: \"{$title}\" already exists (ID #{$duplicate->id}). Delete the existing book first.",
                    ];
                    $this->processed++;
                    continue;
                }

                // 1. Upload & extract text
                $book = $pdfService->processUpload($file);

                // Apply crop settings
                $book->update([
                    'crop_enabled' => $this->hasCropMarks,
                    'crop_percent' => $this->cropPercent,
                    'crop_box' => $this->detectedCrop['crop_box'] ?? null,
                    'narration_start_page' => 3,
                    'narration_end_page' => $book->page_count - 2, // Skip last 2 pages
                ]);

                $result = [
                    'success' => true,
                    'filename' => $file->getClientOriginalName(),
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'pages' => $book->page_count,
                    'translations' => [],
                    'narration' => null,
                ];

                // 2. Translate
                $languages = [];
                if ($this->langAfrikaans) $languages[] = 'af';
                if ($this->langZulu) $languages[] = 'zu';

                foreach ($languages as $lang) {
                    try {
                        $translationService->translate($book, $lang);
                        $result['translations'][] = $lang;
                    } catch (\Throwable $e) {
                        // Translation failed but continue
                    }
                }

                // 3. Narrate (DISABLED — testing font placement only)
                // if ($this->enableNarration && !empty($this->selectedVoice)) {
                //     try {
                //         $voice = collect($this->availableVoices)->firstWhere('voice_id', $this->selectedVoice);
                //         $voiceName = $voice['name'] ?? 'Unknown';
                //         $narrationService->narrate($book, 'en', $this->selectedVoice, $voiceName, $this->dramaLevel, $this->speedLevel);
                //         $result['narration'] = 'completed';
                //     } catch (\Throwable $e) {
                //         $result['narration'] = 'failed: ' . $e->getMessage();
                //     }
                // }
                $result['narration'] = 'skipped (disabled for testing)';

                $this->results[] = $result;
            } catch (\Throwable $e) {
                $this->results[] = [
                    'success' => false,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }

            $this->processed++;
        }

        $this->processing = false;
        $this->currentStep = 'done';
        $this->files = [];
    }

    public function uploadMore()
    {
        $this->reset(['files', 'results', 'processed', 'total', 'currentStep']);
        $this->currentStep = 'upload';
    }

    public function render()
    {
        return view('livewire.admin.book-upload')->layout('layouts.admin');
    }
}
