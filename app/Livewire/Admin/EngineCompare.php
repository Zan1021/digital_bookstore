<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class EngineCompare extends Component
{
    public Book $book;
    public ?string $v8Status = null;
    public ?string $v8PdfUrl = null;
    public ?array $v8Report = null;
    public string $selectedLanguage = 'af';
    public bool $rendering = false;

    public function mount(Book $book)
    {
        $this->book = $book;
        
        // Check if rendered PDF already exists
        $v8Path = "books/translated/{$book->id}_{$this->selectedLanguage}.pdf";
        
        if (Storage::disk('public')->exists($v8Path)) {
            $this->v8PdfUrl = Storage::disk('public')->url($v8Path);
            $this->v8Status = 'ready';
        }
    }

    public function renderV8()
    {
        $this->rendering = true;
        $this->v8Status = 'rendering...';

        $result = $this->runEngine();
        
        if ($result['success']) {
            $this->v8Status = 'ready';
            $this->v8PdfUrl = Storage::disk('public')->url($result['output_path']);
            $this->v8Report = $result['report'];
        } else {
            $this->v8Status = 'failed: ' . $result['error'];
        }
        
        $this->rendering = false;
    }

    private function runEngine(): array
    {
        $book = $this->book;
        $lang = $this->selectedLanguage;

        // Get translation
        $translation = Translation::where('book_id', $book->id)
            ->where('language_code', $lang)
            ->first();

        if (!$translation) {
            return ['success' => false, 'error' => "No {$lang} translation found"];
        }

        // Build translations JSON
        $translatedPages = $translation->translatedPages()->orderBy('page_number')->get();
        $translations = ['pages' => []];
        foreach ($translatedPages as $tp) {
            if ($tp->translated_text) {
                $translations['pages'][] = [
                    'page_number' => $tp->page_number,
                    'translated_text' => $tp->translated_text,
                ];
            }
        }

        // Write temp translations JSON
        $tempJsonPath = storage_path("app/temp/render_{$book->id}_{$lang}.json");
        $tempDir = dirname($tempJsonPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($tempJsonPath, json_encode($translations, JSON_UNESCAPED_UNICODE));

        // Paths
        $inputPdf = Storage::disk('public')->path($book->pdf_path);
        $outputRelative = "books/translated/{$book->id}_{$lang}.pdf";
        $outputPdf = Storage::disk('public')->path($outputRelative);
        $fontsDir = storage_path('app/fonts');

        // Ensure output directory exists
        $outputDir = dirname($outputPdf);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Run V8 engine
        $scriptPath = base_path('scripts/pdf_translate_v8.py');

        $command = sprintf(
            'python "%s" replace --input "%s" --output "%s" --translations "%s" --fonts-dir "%s"',
            $scriptPath,
            $inputPdf,
            $outputPdf,
            $tempJsonPath,
            $fontsDir
        );

        $process = Process::timeout(120)->run($command);

        // Cleanup temp file
        @unlink($tempJsonPath);

        if ($process->successful()) {
            // Parse report from stderr
            $report = null;
            $stderr = $process->errorOutput();
            if ($stderr) {
                $jsonMatch = preg_match('/\{.*\}/s', $stderr, $matches);
                if ($jsonMatch) {
                    $report = json_decode($matches[0], true);
                }
            }

            return [
                'success' => true,
                'output_path' => $outputRelative,
                'report' => $report,
            ];
        }

        return [
            'success' => false,
            'error' => $process->errorOutput() ?: 'Unknown error',
        ];
    }

    public function render()
    {
        return view('livewire.admin.engine-compare')->layout('layouts.admin');
    }
}
