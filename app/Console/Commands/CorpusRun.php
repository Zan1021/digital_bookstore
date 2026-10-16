<?php

namespace App\Console\Commands;

use App\Corpus\CorpusRunner;
use Illuminate\Console\Command;

/**
 * Task 8.2 (Req 26.3, design §22): run the pipeline on the golden corpus and compare with
 * the previous run. This spends AI money, so it shows an estimate and asks first (Req 22.2)
 * unless --yes is given; spending limits still apply.
 */
class CorpusRun extends Command
{
    protected $signature = 'corpus:run
                            {--engine=v8 : Engine to run (v8 today; scene from task 9)}
                            {--lang=af : Target language code}
                            {--book=* : Only these corpus keys}
                            {--yes : Do not ask before spending}';

    protected $description = 'Run the pipeline on the golden corpus and store gates, contact sheets, timings and cost';

    public function handle(CorpusRunner $runner): int
    {
        ini_set('memory_limit', '1024M');
        $lang = (string) $this->option('lang');
        $books = $runner->books((array) $this->option('book'));
        if ($books->isEmpty()) {
            $this->error('No corpus books imported. Run php artisan corpus:register first.');

            return self::FAILURE;
        }

        $pages = $books->sum(fn ($c) => (int) $c->book->page_count);
        $estimate = $runner->estimateZar($lang, $pages);
        $this->info(sprintf('%d books, %d pages, engine %s, %s. Estimated AI cost: %s (answers already cached cost nothing).',
            $books->count(), $pages, $this->option('engine'), $lang,
            $estimate === null ? 'unknown — no cost history yet' : 'R'.number_format($estimate, 2)));
        if (! $this->option('yes') && ! $this->confirm('Spend AI credit on this run?')) {
            return self::FAILURE;
        }

        try {
            $summary = $runner->run($lang, (string) $this->option('engine'), (array) $this->option('book'),
                fn (string $line) => $this->line($line));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printSummary($summary, $runner->previousRun($summary));
        $this->line('Evidence: '.config('corpus.output_dir').DIRECTORY_SEPARATOR.$summary['run_id']);
        if ($summary['stopped'] === 'spending_limit') {
            $this->warn('Stopped: a spending limit was reached. Raise it or wait for next month, then run again (paid answers are reused).');
        }

        return $summary['totals']['ok'] === $summary['totals']['books'] ? self::SUCCESS : self::FAILURE;
    }

    private function printSummary(array $summary, ?array $previous): void
    {
        $before = collect($previous['books'] ?? [])->where('status', 'ok')->keyBy('key');
        $delta = fn ($now, $was) => $was === null || $now === null ? '' : sprintf(' (%+.1f)', $now - $was);

        $rows = array_map(function ($b) use ($before, $delta) {
            $p = $before->get($b['key']);

            return [
                $b['key'],
                $b['status'],
                $b['render_status'] ?? '—',
                count($b['flagged_pages']).($p ? sprintf(' (%+d)', count($b['flagged_pages']) - count($p['flagged_pages'])) : ''),
                ($b['translate_seconds'] ?? '—').$delta($b['translate_seconds'], $p['translate_seconds'] ?? null)
                    .($b['within_budget'] === false ? ' OVER' : ''),
                'R'.number_format($b['cost_zar'], 2),
            ];
        }, $summary['books']);

        $this->table(['Book', 'Run', 'Gate result', 'Flagged pages', 'Translate s (budget)', 'AI cost'], $rows);
        $t = $summary['totals'];
        $this->info(sprintf('%d/%d ran, %d publishable, %d flagged pages, %d over budget, %.1f s, R%.2f.',
            $t['ok'], $t['books'], $t['publishable'], $t['flagged_pages'], $t['over_budget'], $t['translate_seconds'], $t['cost_zar']));
        $this->line($previous ? "Compared with {$previous['run_id']}." : 'No previous run to compare with.');
    }
}
