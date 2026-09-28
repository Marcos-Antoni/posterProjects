<?php

namespace App\Http\Resources;

use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\Habits\HabitHistory;
use App\Models\Habits\IdentityVoteGroup;
use App\Models\Habits\VoteTally;
use App\Models\Objective;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The read models of the habit screens (18 today, 19 manage, 20 detail/form,
 * 21 identity votes). Every derived number comes from `HabitHistory`; the
 * habits must arrive with `days` (and `objective`, `plan`) eager-loaded so a
 * list costs a constant number of queries.
 */
class HabitPresenter
{
    /**
     * The fields every habit row shares: what, when, its 2-minute version,
     * where it hangs, its level and its tolerant streak.
     *
     * @return array<string, mixed>
     */
    public static function summary(Habit $habit, HabitHistory $history): array
    {
        $streak = $history->streak();
        $ladder = $habit->level_ladder ?? [];

        return [
            'id' => $habit->id,
            'name' => $habit->name,
            'two_minute_version' => (string) $habit->two_minute_version,
            'identity_statement' => $habit->identity_statement,
            'habit_type' => $habit->habit_type->value,
            'unit' => $habit->unit,
            'target' => $habit->targetAmount(),
            'daily_target' => $habit->daily_target,
            'recurrence_type' => $habit->recurrence_type->value,
            'weekdays' => $habit->weekdays,
            'times_per_week' => $habit->times_per_week,
            'planned_time' => $habit->planned_time !== null ? substr($habit->planned_time, 0, 5) : null,
            'objective' => $habit->objective !== null
                ? ['key' => $habit->objective->key, 'title' => $habit->objective->title, 'state' => $habit->objective->state->value]
                : null,
            'plan' => $habit->plan !== null ? ['id' => $habit->plan->id, 'title' => $habit->plan->title] : null,
            'level' => $ladder !== [] && $habit->level !== null
                ? ['current' => $habit->level, 'total' => count($ladder), 'label' => (string) ($ladder[$habit->level - 1]['label'] ?? '')]
                : null,
            'streak' => [
                'current' => $streak->current,
                'best' => $streak->best,
                'state' => $streak->state->value,
                'closed_run' => $streak->closedRun,
                'two_minute_returns' => $streak->twoMinuteReturns,
                'since' => $streak->currentSince,
            ],
            'archived_at' => $habit->archived_at?->toIso8601String(),
        ];
    }

    /**
     * A row of the today screen: the summary plus today's progress and the
     * last 14 days as marks (never a missed-day count).
     *
     * @return array<string, mixed>
     */
    public static function todayRow(Habit $habit, CarbonInterface $today): array
    {
        $history = $habit->history(Carbon::parse($today->toDateString()));
        $date = $today->toDateString();
        $day = $history->dayOn($date);

        return [
            ...self::summary($habit, $history),
            'today' => [
                'accumulated_amount' => $day === null ? 0 : $day->accumulated_amount,
                'completion_percent' => $day === null ? 0 : $day->completion_percent,
                'peak_amount' => $day === null ? 0 : $day->peak_amount,
                'completed' => $day !== null && $day->completed,
                'two_minute_logged' => $day !== null && $day->two_minute_logged,
                'shown_up' => $day !== null && $day->isShownUp(),
            ],
            'week_recorded_days' => $habit->recurrence_type === RecurrenceType::TimesPerWeek
                ? $history->recordedDaysThisWeek()
                : null,
            'next_date' => $history->nextScheduledDate()?->toDateString(),
            'strip' => $history->marks(Carbon::parse($date)->subDays(13), $date),
        ];
    }

    /**
     * The identity votes of one statement for the sidebars and the identity
     * screen: tallies for 7 and 30 days, the per-opportunity row of the last
     * 7 days, the per-date marks and the 30-day calendar weeks.
     *
     * @return array<string, mixed>
     */
    public static function identity(IdentityVoteGroup $group, CarbonInterface $today): array
    {
        $today = Carbon::parse($today->toDateString());

        return [
            'statement' => $group->statement,
            'source' => $group->source,
            'objective' => $group->objective !== null ? ['key' => $group->objective->key, 'title' => $group->objective->title] : null,
            'habits' => array_map(fn (Habit $habit): array => [
                'id' => $habit->id,
                'name' => $habit->name,
                'recurrence_type' => $habit->recurrence_type->value,
                'weekdays' => $habit->weekdays,
                'times_per_week' => $habit->times_per_week,
            ], $group->habits),
            'last7' => [
                ...self::tally($group->last7),
                'row' => self::voteRow($group->habits, $today),
                'items' => self::voteItems($group->habits, $today),
            ],
            'last30' => [
                ...self::tally($group->last30),
                'weeks' => self::weeks($group->marks30, $today->clone()->subDays(29), $today),
            ],
        ];
    }

    /**
     * The objectives a habit may hang from (the owner's draft and active
     * ones, plus the one it already hangs from), each with its non-retired
     * plans.
     *
     * @return list<array{id: int, key: string, title: string, plans: list<array{id: int, title: string}>}>
     */
    public static function objectiveOptions(User $user, ?Habit $habit = null): array
    {
        $objectives = Objective::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->whereIn('state', [ObjectiveState::Active, ObjectiveState::Draft])
                ->when($habit?->objective_id !== null, fn ($inner) => $inner->orWhere('id', $habit?->objective_id)))
            ->with(['plans' => fn ($query) => $query->where('state', '!=', PlanState::Retired)])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $options = [];

        foreach ($objectives as $objective) {
            $plans = [];

            foreach ($objective->plans as $plan) {
                $plans[] = ['id' => $plan->id, 'title' => $plan->title];
            }

            $options[] = ['id' => $objective->id, 'key' => $objective->key, 'title' => $objective->title, 'plans' => $plans];
        }

        return $options;
    }

    /**
     * @return array{cast: int, possible: int, two_minute: int}
     */
    public static function tally(VoteTally $tally): array
    {
        return ['cast' => $tally->cast, 'possible' => $tally->possible, 'two_minute' => $tally->twoMinute];
    }

    /**
     * One item per opportunity of the last `$days` days across the habits,
     * oldest first (weekly-quota misses, which have no day, go first): `v`
     * a vote, `v2` a vote with only the 2-minute version, `m` an opportunity
     * without one. Always as many items as `possible` and as many votes as
     * `cast` of the tally.
     *
     * @param  list<Habit>  $habits
     * @return list<array{date: string|null, mark: string, habit_id: int}>
     */
    public static function voteItems(array $habits, Carbon $today, int $days = 7): array
    {
        $items = [];

        foreach ($habits as $habit) {
            foreach ($habit->history($today)->voteItems($days) as $item) {
                $items[] = [...$item, 'habit_id' => $habit->id];
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']) ?: $a['habit_id'] <=> $b['habit_id']);

        return $items;
    }

    /**
     * The small `.votes-row` marks: `v` per vote, `n` per opportunity
     * without one, in the order of `voteItems()`.
     *
     * @param  list<Habit>  $habits
     * @return list<string>
     */
    public static function voteRow(array $habits, Carbon $today, int $days = 7): array
    {
        return array_map(fn (array $item): string => $item['mark'] === 'm' ? 'n' : 'v', self::voteItems($habits, $today, $days));
    }

    /**
     * Monday-based calendar weeks covering the window; days outside it are
     * `o` (drawn as blanks).
     *
     * @param  array<string, string>  $marks
     * @return list<array{week_start: string, days: list<array{date: string, mark: string}>}>
     */
    private static function weeks(array $marks, Carbon $from, Carbon $to): array
    {
        $weeks = [];

        for ($week = $from->clone()->startOfWeek(CarbonInterface::MONDAY); $week->lte($to); $week->addWeek()) {
            $days = [];

            for ($offset = 0; $offset < 7; $offset++) {
                $date = $week->clone()->addDays($offset);
                $iso = $date->toDateString();
                $days[] = ['date' => $iso, 'mark' => $date->lt($from) || $date->gt($to) ? 'o' : ($marks[$iso] ?? 'n')];
            }

            $weeks[] = ['week_start' => $week->toDateString(), 'days' => $days];
        }

        return $weeks;
    }
}
