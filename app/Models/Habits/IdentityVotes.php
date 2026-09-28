<?php

namespace App\Models\Habits;

use App\Models\Habit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Identity votes per identity statement (habits spec): every active habit
 * votes for its own statement, or its objective's when it has none. Groups
 * keep the order the statements were first written (oldest habit first):
 * never ranked by performance, never combined into a score.
 */
class IdentityVotes
{
    /**
     * @return list<IdentityVoteGroup>
     */
    public function forUser(User $user, ?CarbonInterface $today = null): array
    {
        $today = Carbon::parse(($today ?? Habit::todayLocalDate())->toDateString());

        $habits = $user->habits()
            ->notArchived()
            ->with(['days', 'schedulePeriods', 'objective'])
            ->orderBy('id')
            ->get();

        /** @var array<string, list<Habit>> $byStatement */
        $byStatement = [];

        foreach ($habits as $habit) {
            $statement = $habit->effectiveIdentityStatement();

            if ($statement !== null) {
                $byStatement[$statement][] = $habit;
            }
        }

        $groups = [];

        foreach ($byStatement as $statement => $members) {
            $groups[] = $this->group((string) $statement, $members, $today);
        }

        return $groups;
    }

    /**
     * @param  list<Habit>  $habits
     */
    private function group(string $statement, array $habits, Carbon $today): IdentityVoteGroup
    {
        $last7 = VoteTally::none();
        $last30 = VoteTally::none();
        $marks7 = [];
        $marks30 = [];
        $inheriting = null;
        $allInherit = true;

        foreach ($habits as $habit) {
            $history = $habit->history($today);
            $last7 = $last7->plus($history->votes(7));
            $last30 = $last30->plus($history->votes(30));
            $marks7 = $this->combine($marks7, $history->voteMarks($today->clone()->subDays(6), $today));
            $marks30 = $this->combine($marks30, $history->voteMarks($today->clone()->subDays(29), $today));

            if (trim((string) $habit->identity_statement) !== '') {
                $allInherit = false;
            } else {
                $inheriting ??= $habit;
            }
        }

        return new IdentityVoteGroup(
            statement: $statement,
            source: $allInherit ? 'objective' : 'habit',
            objective: $allInherit ? $inheriting?->objective : null,
            habits: $habits,
            last7: $last7,
            last30: $last30,
            marks7: $marks7,
            marks30: $marks30,
        );
    }

    /**
     * Combine per-date marks of several habits: a vote wins over a 2-minute
     * vote, which wins over a missed opportunity, which wins over none.
     *
     * @param  array<string, string>  $into
     * @param  array<string, string>  $marks
     * @return array<string, string>
     */
    private function combine(array $into, array $marks): array
    {
        $rank = ['n' => 0, 'm' => 1, 'v2' => 2, 'v' => 3];

        foreach ($marks as $date => $mark) {
            if (! isset($into[$date]) || $rank[$mark] > $rank[$into[$date]]) {
                $into[$date] = $mark;
            }
        }

        return $into;
    }
}
