<?php

namespace App\Corpus;

use App\Models\CorpusBook;
use App\Services\PdfService;
use Illuminate\Http\UploadedFile;

/**
 * Finds the golden-corpus PDFs listed in config/corpus.php and imports each one as a book
 * through the normal upload path (task 8.1). Importing runs no paid AI. A book is imported
 * once and re-imported only when its source file changes (the old import is kept).
 */
class CorpusRegistry
{
    public function __construct(private PdfService $pdfs) {}

    /** @return list<array{key: string, file: string, source: string, features: list<string>, outside: bool}> */
    public function entries(): array
    {
        return array_map(fn (array $b) => $b + ['features' => [], 'outside' => false], config('corpus.books', []));
    }

    /** Absolute path of a corpus PDF, or null when it is not on this machine. */
    public function locate(string $file): ?string
    {
        $repoParent = realpath(dirname(base_path()));
        foreach (config('corpus.roots', []) as $root) {
            if (! is_dir($root)) {
                continue;
            }
            if (is_file($root.DIRECTORY_SEPARATOR.$file)) {
                return realpath($root.DIRECTORY_SEPARATOR.$file);
            }
            if (realpath($root) === $repoParent) {
                continue; // the repo's parent holds the repo itself: top level only
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $found) {
                if ($found->getFilename() === $file) {
                    return $found->getRealPath();
                }
            }
        }

        return null;
    }

    /**
     * Import every corpus book that is new or whose PDF changed.
     *
     * @return list<array{key: string, status: string, book_id: ?int, pages: ?int}>
     *   status: imported | reimported | unchanged | missing
     */
    public function register(?string $onlyKey = null): array
    {
        $report = [];
        foreach ($this->entries() as $entry) {
            if ($onlyKey !== null && $entry['key'] !== $onlyKey) {
                continue;
            }
            $row = CorpusBook::firstOrNew(['key' => $entry['key']]);
            $row->fill(['file' => $entry['file'], 'source' => $entry['source'], 'features' => $entry['features']]);

            $path = $this->locate($entry['file']);
            if ($path === null) {
                $row->save();
                $report[] = ['key' => $entry['key'], 'status' => 'missing', 'book_id' => $row->book_id, 'pages' => $row->book?->page_count];

                continue;
            }

            $sha = hash_file('sha256', $path);
            if ($row->book && $row->source_sha256 === $sha) {
                $row->save();
                $report[] = ['key' => $entry['key'], 'status' => 'unchanged', 'book_id' => $row->book_id, 'pages' => $row->book->page_count];

                continue;
            }

            $status = $row->book ? 'reimported' : 'imported';
            $book = $this->pdfs->processUpload(new UploadedFile($path, $entry['file'], 'application/pdf', null, true));
            $row->fill(['book_id' => $book->id, 'source_sha256' => $sha, 'imported_at' => now()])->save();
            $report[] = ['key' => $entry['key'], 'status' => $status, 'book_id' => $book->id, 'pages' => $book->page_count];
        }

        return $report;
    }

    /**
     * Req 26.2 coverage: outside books registered, and which hard-case features they cover.
     *
     * @return array{outside_books: int, required: int, features: list<string>, ok: bool}
     */
    public function coverage(): array
    {
        $hard = config('corpus.hard_case_features', []);
        $outside = array_filter($this->entries(), fn ($e) => $e['outside'] && array_intersect($e['features'], $hard));
        $features = array_values(array_unique(array_merge([], ...array_map(fn ($e) => array_values(array_intersect($e['features'], $hard)), $outside ?: [['features' => []]]))));

        return ['outside_books' => count($outside), 'required' => 3, 'features' => $features, 'ok' => count($outside) >= 3];
    }
}
