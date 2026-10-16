<?php

namespace App\Scene;

use App\Models\PageStageState;
use App\Models\SourcePage;
use App\Models\Translation;

/**
 * Runs a pipeline stage only on the pages whose inputs changed (task 9.4, Req 3.6, design §6).
 *
 * Each stage says what its inputs are for a page (the page's blocks, the edition's texts,
 * the font profile, …); their hash, together with the stage's version from config/scene.php,
 * is stored when the page completes. Next time, a page with the same hash is skipped. A
 * page whose work throws is not recorded, so it runs again on the next attempt.
 *
 *   $stages->run('layout', $book->sourcePages, $edition,
 *       inputs: fn ($page) => [$stages->pageBlocks($page), $stages->pageTexts($page, $edition), $profileHash],
 *       work:   fn ($page) => $layoutService->layOut($page, $edition));
 */
class PageStages
{
    public function __construct(private SceneCompiler $compiler) {}

    /**
     * @param  iterable<SourcePage>  $pages
     * @param  callable(SourcePage): array  $inputs  everything the stage's output depends on
     * @param  callable(SourcePage): void  $work
     * @param  bool  $force  run every page regardless of its inputs (e.g. a reviewer asked for it)
     */
    public function run(string $stage, iterable $pages, ?Translation $edition, callable $inputs, callable $work, bool $force = false): StageRunSummary
    {
        $this->checkScope($stage, $edition);
        $ran = $skipped = [];

        foreach ($pages as $page) {
            $hash = $this->inputsHash($stage, $inputs($page));
            if (! $force && $this->recordedHash($stage, $page, $edition) === $hash) {
                $skipped[] = (int) $page->number;

                continue;
            }

            $work($page);

            PageStageState::updateOrCreate(
                ['page_id' => $page->id, 'edition_key' => self::editionKey($edition), 'stage' => $stage],
                ['translation_id' => $edition?->id, 'inputs_hash' => $hash, 'completed_at' => now()],
            );
            $ran[] = (int) $page->number;
        }

        return new StageRunSummary($stage, $ran, $skipped);
    }

    /** Would the stage skip this page right now? */
    public function isCurrent(string $stage, SourcePage $page, ?Translation $edition, array $inputs): bool
    {
        $this->checkScope($stage, $edition);

        return $this->recordedHash($stage, $page, $edition) === $this->inputsHash($stage, $inputs);
    }

    /** Forget results so the next run redoes them: one page or all of an edition/book, one stage or all. */
    public function invalidate(?SourcePage $page = null, ?string $stage = null, ?Translation $edition = null): int
    {
        return PageStageState::query()
            ->when($page, fn ($q) => $q->where('page_id', $page->id))
            ->when($stage, fn ($q) => $q->where('stage', $stage))
            ->when($edition, fn ($q) => $q->where('translation_id', $edition->id))
            ->delete();
    }

    /** Standard input: the page as the scene describes it (size, plates, blocks, treatments, styles). */
    public function pageBlocks(SourcePage $page): array
    {
        return $this->compiler->pagePayload($page);
    }

    /** Standard input: the edition's current text for each block on the page. */
    public function pageTexts(SourcePage $page, Translation $edition): array
    {
        return $edition->blockTranslations()
            ->whereIn('block_id', $page->blocks()->select('id'))
            ->orderBy('block_id')
            ->get(['block_id', 'text', 'alt_short_text', 'status'])
            ->map(fn ($bt) => [$bt->block_id, $bt->text, $bt->alt_short_text, $bt->status])
            ->all();
    }

    public function inputsHash(string $stage, array $inputs): string
    {
        $version = (int) config("scene.stages.{$stage}.version", 0);

        return hash('sha256', json_encode([$stage, $version, self::canonical($inputs)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function recordedHash(string $stage, SourcePage $page, ?Translation $edition): ?string
    {
        return PageStageState::query()->where('page_id', $page->id)->where('edition_key', self::editionKey($edition))
            ->where('stage', $stage)->value('inputs_hash');
    }

    /** Book stages take no edition; language stages need one (fail closed on a mix-up). */
    private function checkScope(string $stage, ?Translation $edition): void
    {
        $scope = config("scene.stages.{$stage}.scope");
        if ($scope === null) {
            throw new \InvalidArgumentException("Unknown pipeline stage '{$stage}' (config/scene.php).");
        }
        if (($scope === 'language') !== ($edition !== null)) {
            throw new \InvalidArgumentException("Stage '{$stage}' is a {$scope} stage; ".($edition ? 'it takes no edition.' : 'it needs an edition.'));
        }
    }

    private static function editionKey(?Translation $edition): string
    {
        return $edition ? (string) $edition->id : '';
    }

    /** Associative arrays sorted by key at every level, so key order never changes the hash. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
