<?php

namespace App\Scene\Stages;

use App\Assets\AssetStorage;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\BlockStore;
use App\Scene\Contracts\PageStageHandler;
use Symfony\Component\Process\Process;

/**
 * Pipeline stage "extract" (task 11.1, design §6 stage 2): the text blocks of one source page,
 * from scripts/scene_extract.py, saved with stable IDs by BlockStore. Depends only on the
 * source file and the page number (plus the stage version in config/scene.php).
 */
class ExtractStage implements PageStageHandler
{
    public function __construct(private BlockStore $store) {}

    public function inputs(SourcePage $page, ?Translation $edition): array
    {
        $book = $page->book;

        return ['source' => $book->source_checksum ?? $book->pdf_path, 'page' => (int) $page->number];
    }

    public function handle(SourcePage $page, ?Translation $edition): void
    {
        $path = AssetStorage::disk()->path($page->book->pdf_path);
        $process = new Process(['python', base_path('scripts/scene_extract.py'), '--input', $path, '--page', (string) $page->number],
            null, ['PYTHONIOENCODING' => 'utf-8', 'PYTHONUTF8' => '1']);
        $process->setTimeout(120);
        $process->run();

        $output = trim($process->getOutput());
        $result = json_decode($output, true);
        if (! $process->isSuccessful() || ! isset($result['pages'][0]['blocks'])) {
            // Fail closed: a page that could not be read keeps nothing half-done and is marked failed.
            $why = $result['error'] ?? (trim($process->getErrorOutput()) ?: ($output === '' ? 'no output'
                : 'unreadable output ('.json_last_error_msg().')'));
            throw new \RuntimeException("Text extraction failed for page {$page->number} (exit {$process->getExitCode()}): {$why}");
        }

        $this->store->replacePage($page, $result['pages'][0]['blocks']);
    }
}
