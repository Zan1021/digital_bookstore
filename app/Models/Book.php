<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    }
}
