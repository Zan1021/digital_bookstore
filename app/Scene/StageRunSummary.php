<?php

namespace App\Scene;

/** Which pages a stage ran and which it skipped because their inputs had not changed. */
final class StageRunSummary
{
    /**
     * @param  list<int>  $ran  page numbers that were processed
     * @param  list<int>  $skipped  page numbers whose inputs were unchanged
     */
    public function __construct(
        public readonly string $stage,
        public readonly array $ran,
        public readonly array $skipped,
    ) {}

    /** For a stage's jobs_runs meta (Req 3.3). */
    public function toMeta(): array
    {
        return ['stage' => $this->stage, 'ran' => count($this->ran), 'skipped' => count($this->skipped), 'pages' => $this->ran];
    }
}
