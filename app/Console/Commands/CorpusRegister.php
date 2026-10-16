<?php

namespace App\Console\Commands;

use App\Corpus\CorpusRegistry;
use Illuminate\Console\Command;

/** Task 8.1: import the golden-corpus books listed in config/corpus.php. No paid AI. */
class CorpusRegister extends Command
{
    protected $signature = 'corpus:register {--book= : Only this corpus key}';

    protected $description = 'Import the golden-corpus books (config/corpus.php) as books';

    public function handle(CorpusRegistry $registry): int
    {
        // The PHP PDF text parser holds whole files in memory; big picture books exceed 128M.
        ini_set('memory_limit', '1024M');

        $report = $registry->register($this->option('book') ?: null);
        $this->table(['Corpus key', 'Status', 'Book', 'Pages'],
            array_map(fn ($r) => [$r['key'], $r['status'], $r['book_id'] ?? '—', $r['pages'] ?? '—'], $report));

        $missing = array_filter($report, fn ($r) => $r['status'] === 'missing');
        foreach ($missing as $r) {
            $this->warn("Missing PDF for {$r['key']}: put it in one of the corpus folders (config/corpus.php roots).");
        }

        $cov = $registry->coverage();
        if (! $cov['ok']) {
            $this->warn("Req 26.2: {$cov['outside_books']} of {$cov['required']} outside books registered"
                .($cov['features'] ? ' (covers: '.implode(', ', $cov['features']).')' : '').'.');
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
