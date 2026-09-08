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
    public string $currentStep = 'upload'; // upload, crop, processing, done

    // Settings
    public bool $hasCropMarks = false;
    public int $cropPercent = 5;
    public string $previewPdfUrl = '';
    public ?array $detectedCrop = null;

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
        $steps = ['upload', 'crop', 'processing', 'done'];
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
        $steps = ['upload', 'crop', 'processing', 'done'];
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

                $this->results[] = [
                    'success' => true,
                    'filename' => $file->getClientOriginalName(),
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'pages' => $book->page_count,
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
