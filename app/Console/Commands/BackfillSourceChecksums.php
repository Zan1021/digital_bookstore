<?php

namespace App\Console\Commands;

use App\Assets\AssetStorage;
use App\Models\Book;
use Illuminate\Console\Command;

/** Task 10.2: give books uploaded before checksums existed their SHA-256, so duplicates of them are caught (Req 2.6). */
class BackfillSourceChecksums extends Command
{
    protected $signature = 'books:backfill-checksums';

    protected $description = 'Record the SHA-256 of each book\'s stored original where it is missing';

    public function handle(): int
    {
        $disk = AssetStorage::disk();
        $done = $missing = 0;
        Book::whereNull('source_checksum')->whereNotNull('pdf_path')->each(function (Book $book) use ($disk, &$done, &$missing) {
            if (! $disk->exists($book->pdf_path)) {
                $missing++;

                return;
            }
            $book->forceFill(['source_checksum' => hash_file('sha256', $disk->path($book->pdf_path))])->saveQuietly();
            $done++;
        });
        $this->info("Checksums recorded: {$done}; originals not found: {$missing}.");

        return self::SUCCESS;
    }
}
