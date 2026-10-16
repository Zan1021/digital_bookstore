<?php

namespace App\Corpus;

use App\Ai\SpendingLimitReached;
use App\Assets\AssetStorage;
use App\Jobs\TranslateEditionJob;
use App\Models\AiLedgerEntry;
use App\Models\CorpusBook;
use App\Models\JobRun;
use App\Models\Language;
use App\Models\Translation;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * Runs the pipeline on every golden-corpus book for one target language and stores the
 * evidence per run (task 8.2, Req 26.3, design §22):
 *   storage/app/corpus/{run_id}/summary.json
 *   storage/app/corpus/{run_id}/{key}/report.json   gate report, timings, cost
 *   storage/app/corpus/{run_id}/{key}/contact.png   source | translated, flagged pages framed
 *
 * The pipeline is the real queued job (TranslateEditionJob) run in-process, so timings
 * and gates are exactly what production does. Only the V8 engine exists today; the Scene
 * Engine joins with tasks 9+.
 */
class CorpusRunner
{
    public const ENGINES = ['v8'];

    /** Corpus books ready to run (imported), optionally limited to some keys. */
    public function books(array $keys = []): \Illuminate\Support\Collection
    {
        return CorpusBook::with('book')->whereNotNull('book_id')
            ->when($keys !== [], fn ($q) => $q->whereIn('key', $keys))
            ->orderBy('id')->get()
            ->filter(fn (CorpusBook $c) => $c->book !== null)->values();
    }

    /**
     * Rough AI cost of a run in rand, from what past runs of this language cost per page.
     * Null when there is no history yet.
     */
    public function estimateZar(string $lang, int $pages): ?float
    {
        $editions = Translation::where('language_code', $lang)->pluck('id');
        $spent = AiLedgerEntry::whereIn('translation_id', $editions)->where('cache_hit', false)->sum('cost_zar');
        $pagesDone = Translation::whereIn('id', AiLedgerEntry::whereIn('translation_id', $editions)->select('translation_id'))
            ->with('book:id,page_count')->get()->sum(fn ($t) => (int) $t->book?->page_count);

        return $pagesDone > 0 ? round($spent / $pagesDone * $pages, 2) : null;
    }

    /**
     * @param  callable(string): void|null  $progress  receives one line per book
     * @return array the run summary (also written to summary.json)
     */
    public function run(string $lang, string $engine = 'v8', array $keys = [], ?callable $progress = null): array
    {
        if (! in_array($engine, self::ENGINES, true)) {
            throw new \InvalidArgumentException("Engine '{$engine}' is not available yet (available: ".implode(', ', self::ENGINES).').');
        }
        $languageName = Language::targetNameOrFail($lang, 'en');

        $runId = now()->format('Ymd-His').'-'.$engine.'-'.$lang;
        $dir = config('corpus.output_dir').DIRECTORY_SEPARATOR.$runId;
        @mkdir($dir, 0775, true);

        $summary = ['run_id' => $runId, 'engine' => $engine, 'language' => $lang,
            'started_at' => now()->toIso8601String(), 'books' => [], 'stopped' => null];

        foreach ($this->books($keys) as $corpus) {
            $result = $this->runBook($corpus, $lang, $languageName, $dir);
            $summary['books'][] = $result;
            if ($progress) {
                $progress(sprintf('%-15s %-8s %s', $corpus->key, $result['status'], $result['render_status'] ?? $result['error'] ?? ''));
            }
            if ($result['status'] === 'paused') {
                $summary['stopped'] = 'spending_limit';
                break; // every further book would hit the same limit
            }
        }

        $summary['finished_at'] = now()->toIso8601String();
        $summary['totals'] = $this->totals($summary['books']);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $summary;
    }

    /** The newest earlier run of the same engine and language, for comparison. */
    public function previousRun(array $current): ?array
    {
        $runs = glob(config('corpus.output_dir').DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'summary.json') ?: [];
        rsort($runs);
        foreach ($runs as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && $data['run_id'] < $current['run_id']
                && $data['engine'] === $current['engine'] && $data['language'] === $current['language']) {
                return $data;
            }
        }

        return null;
    }

    private function runBook(CorpusBook $corpus, string $lang, string $languageName, string $dir): array
    {
        $book = $corpus->book;
        $edition = Translation::firstOrCreate(['book_id' => $book->id, 'language_code' => $lang],
            ['language_name' => $languageName, 'status' => 'draft']);
        $started = Carbon::now()->subSecond(); // job runs and ledger rows are stamped to the second

        $status = 'ok';
        $error = null;
        try {
            dispatch_sync(new TranslateEditionJob($edition->id));
        } catch (SpendingLimitReached $e) {
            [$status, $error] = ['paused', $e->getMessage()];
        } catch (\Throwable $e) {
            [$status, $error] = ['error', $e->getMessage()];
        }
        $edition->refresh();
        $qa = $edition->decodeQaReport() ?? [];
        if ($status === 'ok' && ($qa['paused'] ?? null) === 'spending_limit') {
            [$status, $error] = ['paused', $qa['error'] ?? 'spending limit reached'];
        }

        $timings = JobRun::where('book_id', $book->id)->where('started_at', '>=', $started)
            ->get()->groupBy('stage')
            ->map(fn ($runs) => round($runs->sum('duration_ms') / 1000, 1))->sortKeys()->all();
        $ledger = AiLedgerEntry::where('translation_id', $edition->id)->where('at', '>=', $started)->get();
        $flagged = array_values(array_unique(array_map('intval', array_merge(
            (array) ($qa['review_pages'] ?? []),
            (array) ($qa['visual_qa']['flagged_pages'] ?? []),
        ))));
        sort($flagged);
        $seconds = $timings['translate.edition'] ?? null;
        $budget = (float) config('corpus.budget_translate_seconds') * max(1, (int) $book->page_count) / 16;

        $bookDir = $dir.DIRECTORY_SEPARATOR.$corpus->key;
        @mkdir($bookDir, 0775, true);
        $contact = $this->contactSheet($book, $edition, $flagged, $bookDir);

        $result = [
            'key' => $corpus->key,
            'book_id' => $book->id,
            'translation_id' => $edition->id,
            'pages' => (int) $book->page_count,
            'status' => $status,
            'error' => $error,
            'render_status' => $edition->render_status,
            'publishable' => (bool) ($qa['publishable'] ?? false),
            'flagged_pages' => $flagged,
            'issue_codes' => array_values(array_unique(array_filter(array_map(fn ($i) => $i['code'] ?? null, (array) ($qa['issues'] ?? []))))),
            'timings' => $timings,
            'translate_seconds' => $seconds,
            'budget_seconds' => round($budget),
            'within_budget' => $seconds !== null ? $seconds <= $budget : null,
            'cost_zar' => round((float) $ledger->sum('cost_zar'), 2),
            'ai_calls' => $ledger->count(),
            'cache_hits' => $ledger->where('cache_hit', true)->count(),
            'contact_sheet' => $contact ? $corpus->key.'/contact.png' : null,
        ];
        file_put_contents($bookDir.DIRECTORY_SEPARATOR.'report.json',
            json_encode($result + ['qa_report' => $qa], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    private function contactSheet($book, Translation $edition, array $flagged, string $bookDir): bool
    {
        $disk = AssetStorage::disk();
        if (! $book->pdf_path || ! $disk->exists($book->pdf_path)) {
            return false;
        }
        $args = ['python', base_path('scripts/contact_sheet.py'), '--source', $disk->path($book->pdf_path),
            '--out', $bookDir.DIRECTORY_SEPARATOR.'contact.png',
            '--title', "{$book->title} ({$edition->language_code}) — {$edition->render_status}",
            '--flagged', implode(',', $flagged)];
        if ($edition->rendered_pdf_path && $disk->exists($edition->rendered_pdf_path)) {
            array_push($args, '--translated', $disk->path($edition->rendered_pdf_path));
        }
        $proc = new Process($args);
        $proc->setTimeout(120);
        $proc->run();

        return $proc->isSuccessful();
    }

    private function totals(array $books): array
    {
        $col = fn (string $k) => array_column($books, $k);

        return [
            'books' => count($books),
            'ok' => count(array_filter($books, fn ($b) => $b['status'] === 'ok')),
            'publishable' => count(array_filter($col('publishable'))),
            'pages' => array_sum($col('pages')),
            'flagged_pages' => array_sum(array_map('count', $col('flagged_pages'))),
            'over_budget' => count(array_filter($books, fn ($b) => $b['within_budget'] === false)),
            'translate_seconds' => round(array_sum(array_filter($col('translate_seconds'))), 1),
            'cost_zar' => round(array_sum($col('cost_zar')), 2),
        ];
    }
}
