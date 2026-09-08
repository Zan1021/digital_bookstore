<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'author',
        'illustrator',
        'sku',
        'original_language',
        'description',
        'category',
        'age_group',
        'page_count',
        'cover_image',
        'pdf_path',
        'manifest_path',
        'render_engine',
        'status',
        'metadata',
        'narration_start_page',
        'narration_end_page',
        'crop_percent',
        'crop_enabled',
        'crop_box',
        // Classification/discovery (book-classification-discovery spec)
        'classification_status',
        'book_type',
        'age_min',
        'age_max',
        'audience_range_id',
        'series',
        'series_volume',
    ];

    protected $casts = [
        'metadata' => 'array',
        'crop_enabled' => 'boolean',
        'crop_box' => 'array',
    ];

    /**
     * Normalized per-edge crop fractions of the MediaBox.
     * Always returns 4 clamped edges; zero-crop (display as-is) when undetected.
     * This is the single source of truth the reader consumes — book-agnostic,
     * no hardcoded percentages.
     *
     * @return array{left: float, top: float, right: float, bottom: float}
     */
    public function getCropBoxFractions(): array
    {
        $zero = ['left' => 0.0, 'top' => 0.0, 'right' => 0.0, 'bottom' => 0.0];

        if (! $this->crop_enabled || empty($this->crop_box)) {
            return $zero;
        }

        $clamp = fn ($v) => max(0.0, min(0.45, (float) $v));

        return [
            'left' => $clamp($this->crop_box['left'] ?? 0),
            'top' => $clamp($this->crop_box['top'] ?? 0),
            'right' => $clamp($this->crop_box['right'] ?? 0),
            'bottom' => $clamp($this->crop_box['bottom'] ?? 0),
        ];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class)->orderBy('page_number');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public function narrations(): HasMany
    {
        return $this->hasMany(Narration::class);
    }

    public function processingJobs(): HasMany
    {
        return $this->hasMany(ProcessingJob::class);
    }

    // ---- Classification / discovery relationships (book-classification-discovery) ----

    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_categories')
            ->withPivot(['is_primary', 'source', 'confidence', 'approved_at', 'approved_by'])
            ->withTimestamps();
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'book_tags')
            ->withPivot(['source', 'confidence', 'approved_at', 'approved_by'])
            ->withTimestamps();
    }

    public function contentAdvisories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ContentAdvisory::class, 'book_content_advisories')
            ->withPivot(['source', 'approved_at'])->withTimestamps();
    }

    public function audienceRange(): BelongsTo
    {
        return $this->belongsTo(AudienceRange::class);
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(BookDescription::class);
    }

    public function classificationSuggestions(): HasMany
    {
        return $this->hasMany(ClassificationSuggestion::class);
    }

    /** The primary (approved) category for this book, if any. */
    public function primaryCategory(): ?Category
    {
        return $this->categories()->wherePivot('is_primary', true)->first();
    }

    /** Approved description for a language (falls back to English, then any approved). */
    public function approvedDescription(string $lang = 'en'): ?BookDescription
    {
        return $this->descriptions()->approved()->where('language_code', $lang)->first()
            ?? $this->descriptions()->approved()->where('language_code', 'en')->first()
            ?? $this->descriptions()->approved()->first();
    }

    /**
     * Kids-domain series progression (Req 8.5): the next book in the same series by
     * volume, preferring one at a comparable reading level to a given edition. Returns
     * null when there is no series or no later volume. Book-agnostic.
     */
    public function nextInSeries(?int $readingLevelRank = null): ?Book
    {
        if (empty($this->series) || $this->series_volume === null) {
            return null;
        }
        $laterVolumes = static::where('series', $this->series)
            ->where('series_volume', '>', $this->series_volume)
            ->orderBy('series_volume')
            ->get();

        if ($laterVolumes->isEmpty()) {
            return null;
        }
        if ($readingLevelRank === null) {
            return $laterVolumes->first();
        }
        // Prefer the earliest later volume whose easiest edition is at a comparable
        // (<= +1 rank) reading level; else fall back to the next volume.
        foreach ($laterVolumes as $candidate) {
            $rank = $candidate->translations()
                ->with('readingLevel')->get()
                ->map(fn ($t) => $t->readingLevel?->rank)
                ->filter()->min();
            if ($rank !== null && $rank <= $readingLevelRank + 1) {
                return $candidate;
            }
        }
        return $laterVolumes->first();
    }

    public function getExtractedText(): string
    {
        return $this->pages()
            ->whereNotNull('extracted_text')
            ->orderBy('page_number')
            ->pluck('extracted_text')
            ->implode("\n\n---\n\n");
    }

    public function getPageText(int $pageNumber): ?string
    {
        return $this->pages()
            ->where('page_number', $pageNumber)
            ->value('extracted_text');
    }

    protected static function booted(): void
    {
        static::creating(function (Book $book) {
            if (!$book->sku) {
                $lastId = static::max('id') ?? 0;
                $book->sku = 'BK-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT);
            }
        });

        // Single source of truth for teardown: whenever a Book is deleted — via the
        // admin UI, tinker, tests, or future code — remove every associated file and
        // child record so nothing orphans on disk or in the database.
        static::deleting(function (Book $book) {
            $book->deleteAssociatedFiles();
            $book->deleteAssociatedRecords();
        });
    }

    /**
     * Remove every file this book owns on the public disk: the source PDF, cover
     * image, manifest, per-language translated PDFs, the per-book narration audio
     * tree, and the engine comparison/overlay renders. Deletes whole directories
     * rather than guessing individual filenames, so partial/stale path columns
     * cannot leave orphans behind.
     */
    public function deleteAssociatedFiles(): void
    {
        $disk = Storage::disk('public');

        // Single stored file paths on the book record.
        foreach (['pdf_path', 'cover_image', 'manifest_path'] as $attr) {
            $path = $this->getAttribute($attr);
            if ($path && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        // Per-language translated PDFs: the real column plus the legacy guessed path.
        foreach ($this->translations as $translation) {
            $candidates = [
                $translation->rendered_pdf_path,
                "books/translated/{$this->id}_{$translation->language_code}.pdf",
            ];
            foreach (array_filter($candidates) as $pdf) {
                if ($disk->exists($pdf)) {
                    $disk->delete($pdf);
                }
            }
        }

        // Manifest sibling by convention (books/manifests/{id}_manifest.json).
        $manifestByConvention = "books/manifests/{$this->id}_manifest.json";
        if ($disk->exists($manifestByConvention)) {
            $disk->delete($manifestByConvention);
        }

        // Whole directory trees keyed on the book id — audio + comparison renders.
        // deleteDirectory() is a no-op when the directory is absent.
        $disk->deleteDirectory("narrations/book-{$this->id}");

        foreach ($this->translations as $translation) {
            $disk->deleteDirectory("books/comparison/{$this->id}_{$translation->language_code}");
        }
        // Sweep any remaining comparison dirs for this book id (e.g. languages whose
        // translation row was already removed): books/comparison/{id}_*.
        foreach ($disk->directories('books/comparison') as $dir) {
            if (preg_match('#/'.preg_quote((string) $this->id, '#').'_[^/]+$#', $dir)
                || str_starts_with(basename($dir), "{$this->id}_")) {
                $disk->deleteDirectory($dir);
            }
        }
    }

    /**
     * Cascade-delete child rows. translatedPages hang off translations, so remove
     * them first, then the direct hasMany relations.
     */
    public function deleteAssociatedRecords(): void
    {
        foreach ($this->translations as $translation) {
            $translation->translatedPages()->delete();
        }
        $this->narrations()->delete();
        $this->translations()->delete();
        $this->pages()->delete();
        $this->processingJobs()->delete();
    }
}
