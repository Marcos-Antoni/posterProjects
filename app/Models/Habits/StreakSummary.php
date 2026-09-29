<?php

namespace App\Models\Habits;

use App\Enums\StreakState;

/**
 * The tolerant streak of one habit (design D5), in opportunities (days,
 * scheduled weekdays or weeks). `closedRun` is the length of the run the
 * most recent pair of consecutive misses closed (kept as history, shown as
 * "quedó guardado"), null when that pair closed no run. `twoMinuteReturns`
 * counts the opportunities of the current run shown up only with the
 * 2-minute version; `currentSince` is the UTC-6 date the current run
 * started (null when there is no current run).
 */
final readonly class StreakSummary
{
    public function __construct(
        public int $current,
        public int $best,
        public StreakState $state,
        public ?int $closedRun,
        public int $twoMinuteReturns,
        public ?string $currentSince = null,
    ) {}
}
