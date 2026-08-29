<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Services\NarrationService;
use App\Services\PdfService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class BookOnboarding extends Component
{
    use WithFileUploads;

    // Current step
    public string $currentStep = 'upload';
    // Steps: upload → crop → font → preview → narrate → signoff

    // Step 1: Upload
    public $pdfFile = null;
    public string $previewPdfUrl = '';

    // Step 2: Crop
    public bool $hasCropMarks = false;
    public int $cropPercent = 0;
    public ?array $detectedCrop = null;

    // Step 3: Font
    public array $detectedFonts = [];
    public array $fontStatus = []; // font_name => 'resolved'|'missing'|'uploaded'
    public $fontUpload = null;
    public string $fontUploadTarget = '';

    // Step 4: Preview
    public ?Book $book = null;
    public array $extractedPages = [];
    public int $previewPage = 1;

    // Step 5: Narrate
    public bool $enableNarration = true;
    public string $selectedVoice = '';
    public array $availableVoices = [];
    public string $voiceGender = 'male';

    // Step 6: Sign-off
    public bool $signedOff = false;

    // Processing state
    public bool $processing = false;
    public string $processingMessage = '';

    public function mount()
    {
        $this->loadVoices();
    }

    // =========================================================================
    // STEP 1: Upload PDF
    // =========================================================================

    public function updatedPdfFile()
    {
        if ($this->pdfFile) {
            $this->validate(['pdfFile' => 'file|mimes:pdf|max:102400']);

            // Store temp for preview
            $tempPath = $this->pdfFile->store('temp-previews', 'public');
            $this->previewPdfUrl = asset('storage/' . $tempPath);

            // Auto-detect crop marks
            $pdfService = app(PdfService::class);
            $this->detectedCrop = $pdfService->detectCropMarks($tempPath);

            if ($this->detectedCrop && $this->detectedCrop['detected']) {
                $this->hasCropMarks = true;
                $this->cropPercent = (int) ceil($this->detectedCrop['crop_avg']);
            }

            $this->currentStep = 'crop';
        }
    }

    // =========================================================================
    // STEP 2: Crop Detection
    // =========================================================================

    public function confirmCrop()
    {
        // Detect fonts from the PDF
        $this->detectFonts();
        $this->currentStep = 'font';
    }

    public function skipCrop()
    {
        $this->hasCropMarks = false;
        $this->cropPercent = 0;
        $this->detectFonts();
        $this->currentStep = 'font';
    }

    // =========================================================================
    // STEP 3: Font Resolution
    // =========================================================================

    private function detectFonts()
    {
        $this->processing = true;
        $this->processingMessage = 'Detecting fonts...';

        try {
            $tempPath = $this->pdfFile->getRealPath();

            // Run font resolver
            $scriptPath = base_path('scripts/font_resolver.py');
            $fontsDir = storage_path('app/fonts');

            $process = new \Symfony\Component\Process\Process([
                'python', $scriptPath, 'resolve-all', $tempPath, '--output', $fontsDir,
            ]);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                $results = json_decode($process->getOutput(), true) ?? [];
                $this->detectedFonts = [];
                $this->fontStatus = [];

                foreach ($results as $result) {
                    $pdfFont = $result['pdf_font'] ?? 'Unknown';
                    $cleanName = preg_replace('/^[A-Z]{6}\+/', '', $pdfFont);
                    $matched = $result['matched_family'] ?? null;
                    $path = $result['path'] ?? null;

                    $this->detectedFonts[] = [
                        'pdf_name' => $cleanName,
                        'matched_to' => $matched,
                        'has_file' => !empty($path) && file_exists($path),
                        'source' => $result['source'] ?? 'unknown',
                    ];

                    $this->fontStatus[$cleanName] = !empty($path) && file_exists($path) ? 'resolved' : 'missing';
                }
            }
        } catch (\Throwable $e) {
            // Font detection failed — not critical, continue
        }

        $this->processing = false;
    }

    public function uploadFont()
    {
        if ($this->fontUpload && $this->fontUploadTarget) {
            $this->validate(['fontUpload' => 'file|max:10240']);

            $targetName = $this->fontUploadTarget . '.ttf';
            $path = storage_path('app/fonts/' . $targetName);
            file_put_contents($path, file_get_contents($this->fontUpload->getRealPath()));

            $this->fontStatus[$this->fontUploadTarget] = 'uploaded';
            $this->fontUpload = null;
            $this->fontUploadTarget = '';

            // Update detected fonts
            foreach ($this->detectedFonts as &$font) {
                if ($font['pdf_name'] === $this->fontUploadTarget) {
                    $font['has_file'] = true;
                    $font['source'] = 'uploaded';
                }
            }
        }
    }

    public function confirmFonts()
    {
        // Process the book
        $this->processBook();
        $this->currentStep = 'preview';
    }

    // =========================================================================
    // STEP 4: Preview (Extract & Verify)
    // =========================================================================

    private function processBook()
    {
        $this->processing = true;
        $this->processingMessage = 'Extracting text and building book structure...';

        try {
            $pdfService = app(PdfService::class);
            $this->book = $pdfService->processUpload($this->pdfFile);

            $this->book->update([
                'crop_enabled' => $this->hasCropMarks,
                'crop_percent' => $this->cropPercent,
                'crop_box' => $this->detectedCrop['crop_box'] ?? null,
                'status' => 'draft', // Not approved yet
            ]);

            // Load pages for preview
            $this->extractedPages = $this->book->pages()
                ->orderBy('page_number')
                ->get()
                ->map(fn($p) => [
                    'page_number' => $p->page_number,
                    'text' => $p->extracted_text ?? '(No text detected)',
                    'has_text' => !empty($p->extracted_text),
                ])
                ->toArray();

        } catch (\Throwable $e) {
            session()->flash('error', 'Book processing failed: ' . $e->getMessage());
        }

        $this->processing = false;
    }

    public function confirmPreview()
    {
        $this->currentStep = 'narrate';
    }

    // =========================================================================
    // STEP 5: Narration
    // =========================================================================

    private function loadVoices()
    {
        try {
            $service = app(NarrationService::class);
            $this->availableVoices = $service->getVoices();
            if (!empty($this->availableVoices)) {
                $this->selectedVoice = $this->availableVoices[0]['voice_id'];
            }
        } catch (\Throwable $e) {
            $this->availableVoices = [];
        }
    }

    public function confirmNarration()
    {
        // Generate narration for original language
        if ($this->enableNarration && $this->book && !empty($this->selectedVoice)) {
            $this->processing = true;
            $this->processingMessage = 'Generating narration...';

            try {
                $service = app(NarrationService::class);
                $voice = collect($this->availableVoices)->firstWhere('voice_id', $this->selectedVoice);
                $voiceName = $voice['name'] ?? 'Default';
                $service->narrate($this->book, 'en', $this->selectedVoice, $voiceName);
            } catch (\Throwable $e) {
                session()->flash('warning', 'Narration generation had issues: ' . $e->getMessage());
            }

            $this->processing = false;
        }

        $this->currentStep = 'signoff';
    }

    public function skipNarration()
    {
        $this->enableNarration = false;
        $this->currentStep = 'signoff';
    }

    // =========================================================================
    // STEP 6: Admin Sign-Off
    // =========================================================================

    public function signOff()
    {
        if ($this->book) {
            $this->book->update(['status' => 'approved']);
            $this->signedOff = true;
        }
    }

    // =========================================================================
    // Navigation
    // =========================================================================

    public function goToStep(string $step)
    {
        $allowed = ['upload', 'crop', 'font', 'preview', 'narrate', 'signoff'];
        if (in_array($step, $allowed)) {
            $this->currentStep = $step;
        }
    }

    public function prevStep()
    {
        $steps = ['upload', 'crop', 'font', 'preview', 'narrate', 'signoff'];
        $idx = array_search($this->currentStep, $steps);
        if ($idx > 0) {
            $this->currentStep = $steps[$idx - 1];
        }
    }

    public function render()
    {
        return view('livewire.admin.book-onboarding')->layout('layouts.admin');
    }
}
