<?php

namespace App\Scene\Contracts;

use App\Models\SourcePage;
use App\Models\Translation;

/**
 * The work of one pipeline stage for one page (tasks 10.4–19). Registered per stage in
 * config/scene.php ('handler'); RunPageStage calls it through PageStages, so a page whose
 * inputs are unchanged is skipped (task 9.4). $edition is null for book stages.
 */
interface PageStageHandler
{
    /** Everything this stage's result for the page depends on (hashed to decide skip or run). */
    public function inputs(SourcePage $page, ?Translation $edition): array;

    /** Produce the stage's result for the page. Throw to mark the page failed (fail closed). */
    public function handle(SourcePage $page, ?Translation $edition): void;
}
