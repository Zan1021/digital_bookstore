<?php

namespace App\Ai;

/**
 * Thrown before an AI call when a monthly spending limit is used up (task 7.2, Req 22.5).
 * Jobs catch it and pause rather than fail (wired with the callers in task 7.4).
 */
class SpendingLimitReached extends \RuntimeException
{
    public function __construct(
        public readonly ?string $limitOperation,
        public readonly float $limitZar,
        public readonly float $spentZar,
    ) {
        $scope = $limitOperation === null ? 'monthly AI' : "monthly '{$limitOperation}'";
        parent::__construct(sprintf('The %s spending limit of R%.2f is reached (R%.2f spent this month).',
            $scope, $limitZar, $spentZar));
    }
}
