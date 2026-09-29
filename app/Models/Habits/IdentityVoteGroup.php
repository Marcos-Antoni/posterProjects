<?php

namespace App\Models\Habits;

use App\Models\Habit;
use App\Models\Objective;

/**
 * The votes of one identity statement (habits spec): the habits that vote for
 * it, whether the statement was written on the habit or inherited from an
 * objective, the tallies for the rolling last 7 and 30 UTC-6 days and the
 * per-date marks (`v`, `v2`, `m`, `n`) the identity votes screen draws.
 */
final readonly class IdentityVoteGroup
{
    /**
     * @param  'habit'|'objective'  $source
     * @param  list<Habit>  $habits
     * @param  array<string, string>  $marks7
     * @param  array<string, string>  $marks30
     */
    public function __construct(
        public string $statement,
        public string $source,
        public ?Objective $objective,
        public array $habits,
        public VoteTally $last7,
        public VoteTally $last30,
        public array $marks7,
        public array $marks30,
    ) {}
}
