<?php

namespace App\Console\Commands;

use App\Assets\AssetStorage;
use App\Models\Book;
use App\Services\CoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Task 3.3 (Req 20.1): move existing book files from the public disk to the private
 * asset disk, then create store covers for books that have none.
 *
 * Paths stored in the database are relative (books/pdfs/x.pdf, narrations/...), and
 * stay the same: each file keeps its relative path on the private disk. The one change
 * is temp-previews/ → books/temp-previews/, which nothing stores. Every copy is verified
 * by SHA-256 before the original is deleted; an existing different file at the target is
 * reported and left alone. Safe to run again.
 */
class MoveAssetsToPrivate extends Command
{
    protected $signature = 'assets:move-to-private
                            {--dry-run : Show what would move without changing anything}
                            {--keep-originals : Copy and verify, but leave the public files}
                            {--no-covers : Do not create missing store covers}';

    protected $description = 'Move protected book files from the public disk to the private asset disk';

    /** public directory => private directory */
    private const MOVES = [
        'books' => 'books',
        'narrations' => 'narrations',
        'temp-previews' => 'books/temp-previews',
    ];

    public function handle(CoverService $covers): int
    {
        $public = Storage::disk('public');
        $private = AssetStorage::disk();
        $dry = (bool) $this->option('dry-run');
        $stats = ['moved' => 0, 'already' => 0, 'conflict' => 0, 'failed' => 0, 'bytes' => 0];

        foreach (self::MOVES as $from => $to) {
            foreach ($public->allFiles($from) as $source) {
                $target = $to.substr($source, strlen($from));
                $hash = hash('sha256', $public->get($source));

                if ($private->exists($target)) {
                    if (hash('sha256', $private->get($target)) !== $hash) {
                        $this->error("conflict: {$target} already exists with different content; left both in place");
                        $stats['conflict']++;

                        continue;
                    }
                    $stats['already']++;
                } elseif ($dry) {
                    $this->line("would move {$source} → private:{$target}");
                    $stats['moved']++;
                    $stats['bytes'] += $public->size($source);

                    continue;
                } else {
                    $private->writeStream($target, $public->readStream($source));
                    if (! $private->exists($target) || hash('sha256', $private->get($target)) !== $hash) {
                        $this->error("failed: copy of {$source} did not verify; original kept");
                        $stats['failed']++;

                        continue;
                    }
                    $stats['moved']++;
                    $stats['bytes'] += $public->size($source);
                }

                if (! $dry && ! $this->option('keep-originals')) {
                    $public->delete($source);
                }
            }

            if (! $dry && ! $this->option('keep-originals')) {
                $this->removeEmptyDirectories($from);
            }
        }

        $this->info(sprintf('%s %d file(s) (%.1f MB); %d already on the private disk; %d conflict(s); %d failure(s).',
            $dry ? 'Would move' : 'Moved', $stats['moved'], $stats['bytes'] / 1048576,
            $stats['already'], $stats['conflict'], $stats['failed']));

        if (! $this->option('no-covers')) {
            $this->createMissingCovers($covers, $dry);
        }

        return $stats['conflict'] || $stats['failed'] ? self::FAILURE : self::SUCCESS;
    }

    private function createMissingCovers(CoverService $covers, bool $dry): void
    {
        $missing = Book::all()->filter(fn (Book $book) => ! $book->cover_image
            || ! Storage::disk('public')->exists($book->cover_image));

        foreach ($missing as $book) {
            if ($dry) {
                $this->line("would create cover for book {$book->id}");

                continue;
            }
            $ok = $covers->generate($book);
            $ok ? $this->line("cover created for book {$book->id}")
                : $this->warn("no cover for book {$book->id} (source PDF missing or unreadable)");
        }
    }

    /** Remove directories left empty under $root on the public disk, deepest first. */
    private function removeEmptyDirectories(string $root): void
    {
        $public = Storage::disk('public');
        $dirs = $public->allDirectories($root);
        usort($dirs, fn ($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ([...$dirs, $root] as $dir) {
            if ($public->exists($dir) && $public->allFiles($dir) === [] && $public->allDirectories($dir) === []) {
                $public->deleteDirectory($dir);
            }
        }
    }
}
