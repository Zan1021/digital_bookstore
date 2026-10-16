<?php

namespace App\Console\Commands;

use App\Assets\AssetStorage;
use App\Intake\IntakePreflight;
use App\Intake\TitleChooser;
use App\Models\Book;
use Illuminate\Console\Command;

/**
 * Task 10.3: propose clean titles for books uploaded before intake chose them (Req 2.7), using
 * the same clues as new uploads (PDF title, cover, file name — here the current title stands in
 * for the lost file name). Shows the proposals; changes nothing without --apply.
 */
class CleanBookTitles extends Command
{
    protected $signature = 'books:clean-titles {--book=* : Only these book ids} {--apply : Write the proposed titles}';

    protected $description = 'Propose (and with --apply, set) clean titles for existing books';

    public function handle(IntakePreflight $preflight, TitleChooser $chooser): int
    {
        $disk = AssetStorage::disk();
        $rows = [];
        $changes = 0;
        Book::query()->when($this->option('book'), fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')
            ->each(function (Book $book) use ($disk, $preflight, $chooser, &$rows, &$changes) {
                if (! $book->pdf_path || ! $disk->exists($book->pdf_path)) {
                    $rows[] = [$book->id, $book->title, '—', 'original not found'];

                    return;
                }
                $check = $preflight->run($disk->path($book->pdf_path));
                $choice = $chooser->choose($check['meta']['title'] ?? null, $check['cover_lines'] ?? [], $book->title.'.pdf');
                $same = $choice['title'] === $book->title;
                $rows[] = [$book->id, $book->title, $same ? '(unchanged)' : $choice['title'], $choice['source']];
                if (! $same && $this->option('apply')) {
                    $book->update(['title' => $choice['title']]);
                    $changes++;
                }
            });

        $this->table(['Book', 'Current title', 'Clean title', 'From'], $rows);
        $this->info($this->option('apply') ? "Titles changed: {$changes}." : 'Nothing changed. Run again with --apply to set these titles.');

        return self::SUCCESS;
    }
}
