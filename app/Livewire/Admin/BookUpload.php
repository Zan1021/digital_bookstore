<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Services\PdfService;
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
    public string $currentStep = 'upload'; // upload, crop, fonts, translate, processing, done

    // Settings
    public bool $hasCropMarks = false;
    public int $cropPercent = 5;
    public string $previewPdfUrl = '';
    public ?array $detectedCrop = null;

    // TYPOGRAPHY POLICY (chosen in-wizard, step 'fonts'). Per-role font choices applied
    // to every book created in this batch right after creation. Empty = source/house font.
    public array $approvedFonts = [];
    public string $bodyFont = '';
    public string $titleFont = '';
    public string $artworkLabelFont = '';

    // TRANSLATE step: the language chosen in-wizard. Translation itself is a deliberate,
    // PAID action triggered AFTER the book is created (not silently during upload), so the
    // wizard records the choice and the 'done' step offers to run it. Empty = translate later.
    public string $targetLanguage = '';

    /** Supported target languages (value => label), mirrors the book Translate tab. */
    public array $languageOptions = [
        'af' => 'Afrikaans', 'zu' => 'isiZulu', 'xh' => 'isiXhosa', 'st' => 'Sesotho',
        'nso' => 'Sepedi', 'tn' => 'Setswana', 'fr' => 'French', 'de' => 'German',
        'es' => 'Spanish', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'it' => 'Italian',
        'sw' => 'Swahili', 'ar' => 'Arabic', 'zh' => 'Chinese (Simplified)',
    ];

    public function mount()
    {
        // Approved fonts = the families shipped in storage/app/fonts (validated set).
        $this->approvedFonts = Book::approvedFontAssets();
    }

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
        }
    }

    public function nextStep()
    {
        $steps = ['upload', 'crop', 'fonts', 'translate', 'processing', 'done'];
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
        $steps = ['upload', 'crop', 'fonts', 'translate', 'processing', 'done'];
        $currentIdx = array_search($this->currentStep, $steps);
        if ($currentIdx !== false && $currentIdx > 0) {
            $this->currentStep = $steps[$currentIdx - 1];
        }
    }

    public function goBack()
    {
        $this->currentStep = 'upload';
    }

    /**
     * Upload only creates the ebook + extracts text. Translation and narration are
     * chosen deliberately afterwards on the book's management page
     * (gated-translation-narration-flow Req 1). No API calls happen here.
     */
    public function startProcessing()
    {
        $this->validate([
            'files' => 'required',
            'files.*' => 'file|mimes:pdf|max:102400',
        ]);

        $this->currentStep = 'processing';
        $this->processing = true;
        $this->total = count($this->files);
        $this->processed = 0;
        $this->results = [];

        $pdfService = app(PdfService::class);

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

                // Upload & extract text — creates the draft ebook only.
                $book = $pdfService->processUpload($file);

                // Apply crop settings + default narration page range.
                $book->update([
                    'crop_enabled' => $this->hasCropMarks,
                    'crop_percent' => $this->cropPercent,
                    'crop_box' => $this->detectedCrop['crop_box'] ?? null,
                    'narration_start_page' => 3,
                    'narration_end_page' => max(3, $book->page_count - 2), // Skip last 2 pages
                    'status' => 'draft',
                ]);

                // Apply the in-wizard typography policy (step 'fonts'). Validated against
                // the approved fonts dir by setRoleFont; empty = source/house font. A bad
                // value is skipped rather than failing the whole upload.
                try {
                    $book->setRoleFont('body', $this->bodyFont ?: null);
                    $book->setRoleFont('title', $this->titleFont ?: null);
                    $book->setRoleFont('artwork_label', $this->artworkLabelFont ?: null);
                } catch (\InvalidArgumentException $e) {
                    // Non-fatal: keep the book, note the policy was not applied.
                    \Illuminate\Support\Facades\Log::warning('BookUpload: font policy not applied', [
                        'book' => $book->id, 'error' => $e->getMessage(),
                    ]);
                }

                $this->results[] = [
                    'success' => true,
                    'filename' => $file->getClientOriginalName(),
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'pages' => $book->page_count,
                    'target_language' => $this->targetLanguage,
                    'translation_started' => false,
                ];
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

    /**
     * Deliberate, PAID action from the 'done' step: dispatch translation for the books
     * just created, into the language chosen in the wizard. Mirrors BookManager::translate
     * (creates/updates the Translation edition + dispatches TranslateEditionJob). Kept OUT
     * of startProcessing so upload itself never spends — the publisher clicks this.
     */
    public function translateCreated()
    {
        if ($this->targetLanguage === '') {
            return;
        }
        $langName = \App\Services\TranslationService::SUPPORTED_LANGUAGES[$this->targetLanguage]
            ?? $this->targetLanguage;

        foreach ($this->results as $i => $result) {
            if (empty($result['success']) || !empty($result['translation_started'])) {
                continue;
            }
            $edition = \App\Models\Translation::updateOrCreate(
                ['book_id' => $result['book_id'], 'language_code' => $this->targetLanguage],
                ['language_name' => $langName, 'status' => 'processing']
            );
            if (app()->environment('testing')) {
                \App\Jobs\TranslateEditionJob::dispatchSync($edition->id);
            } else {
                \App\Jobs\TranslateEditionJob::dispatch($edition->id);
            }
            $this->results[$i]['translation_started'] = true;
        }
        session()->flash('success', "{$langName} translation queued for the new book(s).");
    }

    public function uploadMore()
    {
        $this->reset(['files', 'results', 'processed', 'total', 'currentStep',
                      'bodyFont', 'titleFont', 'artworkLabelFont', 'targetLanguage']);
        $this->currentStep = 'upload';
    }

    public function render()
    {
        return view('livewire.admin.book-upload')->layout('layouts.admin');
    }
}
