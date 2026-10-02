<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class FontManager extends Component
{
    use WithFileUploads;

    public Book $book;
    public array $sourceFonts = [];
    public array $resolvedFonts = [];
    public array $availableFonts = [];
    public $fontUpload = null;
    public string $uploadTarget = '';
    public bool $loading = false;

    // TYPOGRAPHY POLICY (world-class-render-engine spec Req 4). Per-role font choices
    // (body/title/artwork_label) persisted on the book. Empty = source > house font.
    public array $approvedFonts = [];
    public string $bodyFont = '';
    public string $titleFont = '';
    public string $artworkLabelFont = '';

    public function mount(Book $book)
    {
        $this->book = $book;
        $this->loadFontInfo();
        $this->loadTypographyPolicy();
    }

    private function loadTypographyPolicy(): void
    {
        $this->approvedFonts = Book::approvedFontAssets();
        $policy = $this->book->getTypographyPolicy();
        $roles = $policy['roles'] ?? [];
        $this->bodyFont = $roles['body']['font_asset_id'] ?? '';
        $this->titleFont = $roles['title']['font_asset_id'] ?? '';
        $this->artworkLabelFont = $roles['artwork_label']['font_asset_id'] ?? '';
    }

    /**
     * Persist the chosen per-role fonts onto the book's typography_policy. Validated
     * against the approved fonts dir by Book::setRoleFont (rejects paths/unknown fonts).
     * An empty selection clears that role (reverts to source > house font).
     */
    public function saveTypographyPolicy(): void
    {
        try {
            $this->book->setRoleFont('body', $this->bodyFont ?: null);
            $this->book->setRoleFont('title', $this->titleFont ?: null);
            $this->book->setRoleFont('artwork_label', $this->artworkLabelFont ?: null);
            $this->book->refresh();
            $this->loadTypographyPolicy();
            session()->flash('success', 'Typography policy saved. Re-render editions to apply.');
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function clearTypographyPolicy(): void
    {
        $meta = $this->book->metadata ?? [];
        unset($meta['typography_policy']);
        $this->book->metadata = $meta;
        $this->book->save();
        $this->book->refresh();
        $this->loadTypographyPolicy();
        session()->flash('success', 'Typography policy cleared (reverts to source/house font).');
    }

    private function loadFontInfo()
    {
        // Get fonts from the source PDF
        $pdfPath = Storage::disk('public')->path($this->book->pdf_path);
        $fontsDir = storage_path('app/fonts');

        // List available local fonts
        $this->availableFonts = [];
        if (is_dir($fontsDir)) {
            foreach (glob($fontsDir . '/*.{ttf,otf}', GLOB_BRACE) as $file) {
                $this->availableFonts[] = [
                    'filename' => basename($file),
                    'path' => $file,
                    'size' => round(filesize($file) / 1024, 1) . ' KB',
                ];
            }
        }

        // Run font verification on the PDF
        $scriptPath = base_path('scripts/v8_advanced.py');
        if (file_exists($scriptPath) && file_exists($pdfPath)) {
            $process = Process::timeout(30)->run([
                'python', $scriptPath, 'verify-fonts', '--input', $pdfPath
            ]);

            if ($process->successful()) {
                $result = json_decode($process->output(), true);
                if ($result && isset($result['fonts'])) {
                    $this->sourceFonts = $result['fonts'];
                }
            }
        }

        // Run font resolver to show mappings
        $resolverScript = base_path('scripts/font_resolver.py');
        if (file_exists($resolverScript) && file_exists($pdfPath)) {
            $process = Process::timeout(30)->run([
                'python', $resolverScript, 'resolve-all', $pdfPath, '--output', $fontsDir
            ]);

            if ($process->successful()) {
                $resolved = json_decode($process->output(), true);
                if (is_array($resolved)) {
                    $this->resolvedFonts = $resolved;
                }
            }
        }
    }

    public function uploadFont()
    {
        if (!$this->fontUpload) return;

        $this->validate(['fontUpload' => 'file|mimes:ttf,otf|max:10240']);

        $fontsDir = storage_path('app/fonts');
        if (!is_dir($fontsDir)) {
            mkdir($fontsDir, 0755, true);
        }

        $filename = $this->fontUpload->getClientOriginalName();
        $this->fontUpload->storeAs('fonts', $filename, 'local');

        // Copy to the fonts directory
        copy(storage_path("app/fonts/{$filename}"), "{$fontsDir}/{$filename}");

        $this->fontUpload = null;
        $this->loadFontInfo();

        session()->flash('success', "Font '{$filename}' uploaded successfully.");
    }

    public function render()
    {
        return view('livewire.admin.font-manager')->layout('layouts.admin');
    }
}
