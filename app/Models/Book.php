<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'publisher_id',
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
        'series_id',
        'series_position',
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

    /**
     * The book's typography policy (world-class-render-engine spec Req 4), stored on
     * metadata['typography_policy']. Shape:
     *   { version, publisher_default:{body,title,artwork_label},
     *     roles:{body:{font_asset_id,weight}, ...},
     *     language_overrides:{af:{body:{font_asset_id}}}, unit_overrides:{id:{...}} }
     * Returns [] when none is set (engine then uses source > house font). Book-agnostic.
     */
    public function getTypographyPolicy(): array
    {
        $meta = $this->metadata ?? [];
        $policy = $meta['typography_policy'] ?? null;
        return is_array($policy) ? $policy : [];
    }

    /**
     * Every distinct font family this book explicitly REQUESTS through its typography
     * policy — the families whose on-disk file must genuinely BE that family (checked by
     * the font-integrity preflight, engine-wiring-and-activation R-W3). Collected from
     * publisher_default, per-role, per-language and per-unit overrides. Returns [] when
     * the book sets no policy (nothing publisher-specific to verify; the engine then uses
     * source/house fonts which ship approved). Book-agnostic — no title/language literals.
     *
     * @return string[] de-duplicated family stems (e.g. ["PlaypenSans","PatrickHand"]).
     */
    public function requestedFontFamilies(): array
    {
        $policy = $this->getTypographyPolicy();
        if (empty($policy)) {
            return [];
        }

        $families = [];
        $collect = function ($bucket) use (&$families) {
            if (!is_array($bucket)) {
                return;
            }
            // A bucket may be a role map ({body:{font_asset_id}, ...}) or a single
            // role entry ({font_asset_id, weight}). Handle both shapes.
            if (isset($bucket['font_asset_id']) && is_string($bucket['font_asset_id'])) {
                $families[] = $bucket['font_asset_id'];
                return;
            }
            foreach ($bucket as $entry) {
                if (is_array($entry) && isset($entry['font_asset_id']) && is_string($entry['font_asset_id'])) {
                    $families[] = $entry['font_asset_id'];
                } elseif (is_string($entry) && $entry !== '') {
                    // publisher_default:{body:"PlaypenSans",...} shape (plain stem values).
                    $families[] = $entry;
                }
            }
        };

        $collect($policy['publisher_default'] ?? []);
        $collect($policy['roles'] ?? []);
        foreach (($policy['language_overrides'] ?? []) as $langBucket) {
            $collect($langBucket);
        }
        foreach (($policy['unit_overrides'] ?? []) as $unitBucket) {
            $collect($unitBucket);
        }

        // De-dupe, preserve order, drop empties.
        return array_values(array_unique(array_filter($families, fn ($f) => is_string($f) && $f !== '')));
    }

    /**
     * Set a role's font for the book policy, validated against the APPROVED fonts dir.
     * A font asset id is a family stem (e.g. "PlaypenSans") that MUST resolve to a file
     * in storage/app/fonts — client-supplied absolute paths are rejected (spec Req 4.6).
     * Pass $fontAssetId = null to clear that role. Optional per-language scope.
     *
     * @throws \InvalidArgumentException when the asset id is not an approved family.
     */
    public function setRoleFont(string $role, ?string $fontAssetId, ?int $weight = null, ?string $language = null): void
    {
        $role = in_array($role, ['body', 'title', 'artwork_label'], true) ? $role : 'body';
        $policy = $this->getTypographyPolicy();
        $policy['version'] = $policy['version'] ?? 1;

        if ($fontAssetId !== null && $fontAssetId !== '') {
            // Reject anything path-like; only a bare validated family stem is allowed.
            if (preg_match('#[\\\\/]#', $fontAssetId) || str_contains($fontAssetId, '..')) {
                throw new \InvalidArgumentException('Font asset id must be a family name, not a path.');
            }
            if (! self::isApprovedFontAsset($fontAssetId)) {
                throw new \InvalidArgumentException("Font '{$fontAssetId}' is not an approved font.");
            }
        }

        if ($language) {
            $bucket = $policy['language_overrides'][$language] ?? [];
            if ($fontAssetId) {
                $bucket[$role] = array_filter(['font_asset_id' => $fontAssetId, 'weight' => $weight]);
            } else {
                unset($bucket[$role]);
            }
            $policy['language_overrides'][$language] = $bucket;
        } else {
            if ($fontAssetId) {
                $policy['roles'][$role] = array_filter(['font_asset_id' => $fontAssetId, 'weight' => $weight]);
            } else {
                unset($policy['roles'][$role]);
            }
        }

        $meta = $this->metadata ?? [];
        $meta['typography_policy'] = $policy;
        $this->metadata = $meta;
        $this->save();
    }

    /**
     * Approved font family stems derived from the fonts directory (the approved set).
     * Book-agnostic: whatever ships in storage/app/fonts is approved.
     *
     * @return array<string> family stems, e.g. ["PlaypenSans", "ComicSansMS", ...]
     */
    public static function approvedFontAssets(): array
    {
        $dir = storage_path('app/fonts');
        if (! is_dir($dir)) {
            return [];
        }
        $families = [];
        foreach (glob($dir . '/*.{ttf,otf}', GLOB_BRACE) ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            foreach (['-Regular', '-Bold', '-SemiBold', '-Medium', '-Light', '-Italic',
                      'Regular', 'Bold', 'SemiBold', 'Medium', 'Light', 'Italic'] as $suf) {
                $name = str_replace($suf, '', $name);
            }
            $name = rtrim($name, '-_ ');
            if ($name !== '') {
                $families[$name] = true;
            }
        }
        $out = array_keys($families);
        sort($out);
        return $out;
    }

    public static function isApprovedFontAsset(string $fontAssetId): bool
    {
        $norm = fn ($s) => strtolower(str_replace(['-', '_', ' '], '', $s));
        $want = $norm($fontAssetId);
        foreach (self::approvedFontAssets() as $fam) {
            if ($norm($fam) === $want) {
                return true;
            }
        }
        return false;
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class)->orderBy('page_number');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class, 'book_contributor')
            ->withPivot(['role', 'position'])->withTimestamps();
    }

    /** Link this book's author/illustrator columns to contributor records (task 6.2). */
    public function syncContributorsFromColumns(): void
    {
        foreach (['author', 'illustrator'] as $role) {
            $this->contributors()->wherePivot('role', $role)->detach();
            $name = trim((string) $this->getAttribute($role));
            if ($name !== '') {
                $contributor = Contributor::firstOrCreate(['name' => $name]);
                $this->contributors()->attach($contributor->id, ['role' => $role, 'position' => 0]);
            }
        }
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
        if ($this->series_id === null || $this->series_position === null) {
            return null;
        }
        $laterVolumes = static::where('series_id', $this->series_id)
            ->where('series_position', '>', $this->series_position)
            ->orderBy('series_position')
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

    /**
     * Whether this book is live on the customer storefront. Mirrors the catalogue gate
     * (Services\Discovery\BookQuery::base): the book is published AND has at least one
     * store-visible published edition. Keeps the "is it live?" answer in one place.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->translations
                ->contains(fn (Translation $t) => $t->publication_status === 'published' && $t->isStoreVisible());
    }

    /**
     * Materialise the trusted source-language edition as a Translation row when the work
     * is English-original and none exists yet. On upload the English original lives only
     * on book.pdf_path (no edition row), but the storefront catalogue and the publish
     * readiness gate both reason over editions. Creating the 'en' edition (pointing at the
     * proofed original PDF) lets the trusted source be published and counted without a
     * machine render. No-op for non-English originals or when an 'en' edition is present.
     */
    public function ensureSourceEdition(): void
    {
        if ($this->original_language !== 'en') {
            return;
        }
        if ($this->translations()->where('language_code', 'en')->exists()) {
            return;
        }
        $this->translations()->create([
            'language_code' => 'en',
            'language_name' => 'English',
            'status' => 'draft',
            'rendered_pdf_path' => $this->pdf_path, // the proofed original
        ]);
        $this->load('translations');
    }

    /**
     * Publish the book to the storefront (completes the upload→review→publish flow that
     * previously had no final step, so uploaded books could never leave 'draft').
     *
     * The storefront requires BOTH book.status='published' AND a published, store-visible
     * edition. The English source is publisher-proofed and always trusted to publish
     * (Translation::publishEdition / isSourceLanguage), but it is not persisted as a
     * Translation row on upload (the original PDF lives on book.pdf_path). So we ensure a
     * source 'en' edition exists, publish every edition that is render-ready, and only
     * flip the book to published when at least one edition actually went live.
     *
     * Translated editions that haven't passed the readiness gate are left unpublished
     * rather than force-shown — a failed QA edition must never reach the public catalogue.
     *
     * @return bool true when the book is now live; false when nothing was publishable.
     */
    public function publishBook(): bool
    {
        // Ensure the trusted English source edition exists so the catalogue's
        // whereHas('translations', publication_status='published') can match.
        $this->ensureSourceEdition();

        $publishedAny = false;
        foreach ($this->translations as $edition) {
            if ($edition->publishEdition()) { // gated: source trusted, translated must be ready
                $publishedAny = true;
            }
        }

        if (! $publishedAny) {
            return false;
        }

        $this->status = 'published';
        $this->save();

        return true;
    }

    /**
     * Pull the book from the storefront. Reverses publishBook: the book returns to 'draft'
     * and every edition's publication_status is reset so the catalogue gate excludes it.
     * Non-destructive — renders, translations and files are untouched.
     */
    public function unpublish(): void
    {
        $this->translations()->update(['publication_status' => 'draft']);
        $this->status = 'draft';
        $this->save();
    }

    protected static function booted(): void
    {
        static::creating(function (Book $book) {
            // Single-publisher launch (task 6.1, Req 25.2): a book that names no publisher
            // belongs to the default one.
            if (! $book->publisher_id) {
                $book->publisher_id = Publisher::default()->id;
            }

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

        // Contributors (task 6.2): author/illustrator are still the edited fields; keep the
        // contributor records in step with them, one way, so the two never disagree.
        static::saved(function (Book $book) {
            if ($book->wasRecentlyCreated || $book->wasChanged(['author', 'illustrator'])) {
                $book->syncContributorsFromColumns();
            }
        });

        // The store cover is trimmed like the reader page; redraw it when the trim changes.
        static::updated(function (Book $book) {
            if ($book->wasChanged(['crop_enabled', 'crop_box', 'crop_percent'])) {
                app(\App\Services\CoverService::class)->generate($book);
            }
        });
    }

    /**
     * Remove every file this book owns: on the private asset disk the source PDF,
     * manifest, per-language translated PDFs, the per-book narration audio tree and
     * the engine comparison/overlay renders; on the public disk the store cover image.
     * Deletes whole directories rather than guessing individual filenames, so
     * partial/stale path columns cannot leave orphans behind.
     */
    public function deleteAssociatedFiles(): void
    {
        $disk = \App\Assets\AssetStorage::disk();

        // The cover is the only book file on the public disk (store marketing image).
        if ($this->cover_image && Storage::disk('public')->exists($this->cover_image)) {
            Storage::disk('public')->delete($this->cover_image);
        }

        // Single stored file paths on the book record.
        foreach (['pdf_path', 'manifest_path'] as $attr) {
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
