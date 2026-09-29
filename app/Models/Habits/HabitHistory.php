<?php

namespace App\Models\Habits;

use App\Enums\RecurrenceType;
use App\Enums\StreakState;
use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\HabitSchedulePeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * The single place where a habit's derived state is computed on read (design
 * D5): its past **opportunities** (UTC-6 days, scheduled weekdays, or ISO
 * weeks), the tolerant streak ("never miss twice"), the per-day marks the
 * screens draw, identity votes over a window and the level suggestion.
 *
 * No debt, never rewrite the past:
 * - opportunities start on the habit's first UTC-6 day (its creation date, or
 *   an earlier recorded day); that first day and today count only once shown
 *   up — they can never be missed;
 * - each past day is evaluated with the schedule in force that day
 *   (`habit_schedule_periods`, effective-dated), never with today's;
 * - for times-per-week an opportunity is a closed ISO week; a week the
 *   schedule covers only partly (the first week, or a schedule change) has
 *   its quota pro-rated to ceil(N × days / 7); the week in progress counts
 *   only once its quota is reached.
 *
 * Reads the habit's `days` and `schedulePeriods` relations: callers listing
 * many habits eager-load them once, so no query is issued per habit.
 */
final class HabitHistory
{
    /** @var array<string, HabitDay> */
    private array $days = [];

    private Carbon $today;

    private Carbon $start;

    /** @var list<array{until: Carbon, recurrence: RecurrenceType, weekdays: list<int>, times: int}> */
    private array $schedules = [];

    /** @var list<array{start: string, end: string, shown: bool, two_minute_only: bool}>|null */
    private ?array $opportunities = null;

    /** @var list<array{from: Carbon, to: Carbon, quota: int, span: int, closed: bool}> */
    private array $weeks = [];

    /** @var array{summary: StreakSummary, run_after: list<int>}|null */
    private ?array $walked = null;

    /** @var array<string, int> date => opportunity index (day-based schedules only) */
    private array $opportunityByDate = [];

    private bool $currentWeekMet = false;

    public function __construct(private readonly Habit $habit, CarbonInterface $today)
    {
        $this->today = Carbon::parse($today->toDateString());

        foreach ($habit->days as $day) {
            $this->days[$day->entry_date->toDateString()] = $day;
        }

        ksort($this->days);

        /** @var HabitSchedulePeriod $period */
        foreach ($habit->schedulePeriods->sortBy([['valid_until', 'asc'], ['id', 'asc']]) as $period) {
            $this->schedules[] = [
                'until' => Carbon::parse($period->valid_until->toDateString()),
                'recurrence' => $period->recurrence_type,
                'weekdays' => array_map('intval', $period->weekdays ?? []),
                'times' => max(1, (int) $period->times_per_week),
            ];
        }

        $this->start = $this->firstDay();
    }

    /**
     * The habit's first UTC-6 day: its creation date or an earlier recorded
     * day, never after today.
     */
    public function startDate(): Carbon
    {
        return $this->start->clone();
    }

    /**
     * Whether the given UTC-6 date was shown up (completed or 2-minute).
     */
    public function isShownUpOn(string $date): bool
    {
        return isset($this->days[$date]) && $this->days[$date]->isShownUp();
    }

    public function dayOn(string $date): ?HabitDay
    {
        return $this->days[$date] ?? null;
    }

    /**
     * The schedule in force on a UTC-6 date: the oldest stored period that
     * still covered it, else the habit's current recurrence.
     *
     * @return array{key: int, recurrence: RecurrenceType, weekdays: list<int>, times: int}
     */
    public function scheduleOn(CarbonInterface $date): array
    {
        foreach ($this->schedules as $index => $schedule) {
            if ($date->lte($schedule['until'])) {
                return ['key' => $index, 'recurrence' => $schedule['recurrence'], 'weekdays' => $schedule['weekdays'], 'times' => $schedule['times']];
            }
        }

        return [
            'key' => -1,
            'recurrence' => $this->habit->recurrence_type,
            'weekdays' => array_map('intval', $this->habit->weekdays ?? []),
            'times' => max(1, (int) $this->habit->times_per_week),
        ];
    }

    /**
     * Days of the current Monday-based week with a real record (an amount, a
     * check or the 2-minute version) — the legacy `week_recorded_days`.
     */
    public function recordedDaysThisWeek(): int
    {
        $count = 0;

        for ($cursor = $this->today->clone()->startOfWeek(CarbonInterface::MONDAY); $cursor->lte($this->today); $cursor->addDay()) {
            $day = $this->days[$cursor->toDateString()] ?? null;

            if ($day !== null && $day->hasRecord()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The tolerant streak: a run continues through a single missed
     * opportunity (not counted) and closes at the first pair of consecutive
     * misses. The best streak is the longest run under the same rule.
     */
    public function streak(): StreakSummary
    {
        return $this->walk()['summary'];
    }

    /**
     * One mark per UTC-6 date of the inclusive range, as the screens draw it:
     * `d` done, `t` only the 2-minute version, `r` a single missed
     * opportunity repaired by the next one, `g` a gap (unrepaired, or part of
     * a pair; `closes` flags the miss that closed a run, `closed_run` its
     * length), `p` today still pending, `o` no opportunity that day (not
     * scheduled, the unshown first day, before the habit started, or a
     * non-recorded day of a weekly quota), `f` future.
     *
     * @return list<array{date: string, mark: string, closes: bool, closed_run: int|null}>
     */
    public function marks(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $opportunities = $this->opportunities();
        $runAfter = $this->walk()['run_after'];
        $marks = [];

        for ($cursor = $this->date($from); $cursor->lte($this->date($to)); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $schedule = $this->scheduleOn($cursor);
            $mark = ['date' => $date, 'mark' => 'o', 'closes' => false, 'closed_run' => null];

            if ($cursor->gt($this->today)) {
                $mark['mark'] = 'f';
            } elseif ($cursor->lt($this->start)) {
                $mark['mark'] = 'o';
            } elseif ($this->isShownUpOn($date)) {
                $mark['mark'] = $this->days[$date]->completed ? 'd' : 't';
            } elseif ($schedule['recurrence'] === RecurrenceType::TimesPerWeek) {
                $mark['mark'] = $cursor->isSameDay($this->today) && ! $this->currentWeekMet ? 'p' : 'o';
            } elseif (! $this->scheduledIn($schedule, $cursor)) {
                $mark['mark'] = 'o';
            } elseif ($cursor->isSameDay($this->today)) {
                $mark['mark'] = 'p';
            } elseif (isset($this->opportunityByDate[$date])) {
                $index = $this->opportunityByDate[$date];
                $previousMissed = $index > 0 && ! $opportunities[$index - 1]['shown'];
                $nextShown = isset($opportunities[$index + 1]) && $opportunities[$index + 1]['shown'];
                $closedRun = $previousMissed && ! ($index > 1 && ! $opportunities[$index - 2]['shown']) && $runAfter[$index - 1] > 0
                    ? $runAfter[$index - 1]
                    : null;

                $mark['mark'] = ! $previousMissed && $nextShown ? 'r' : 'g';
                $mark['closes'] = $closedRun !== null;
                $mark['closed_run'] = $closedRun;
            }

            $marks[] = $mark;
        }

        return $marks;
    }

    /**
     * Identity votes over the rolling last `$days` UTC-6 days, today
     * included: each scheduled opportunity is a potential vote, each
     * shown-up one a vote cast. A pending today (or unshown first day) is
     * not a lost vote.
     */
    public function votes(int $days): VoteTally
    {
        $items = $this->voteItems($days);
        $cast = 0;
        $twoMinute = 0;

        foreach ($items as $item) {
            $cast += $item['mark'] === 'm' ? 0 : 1;
            $twoMinute += $item['mark'] === 'v2' ? 1 : 0;
        }

        return new VoteTally($cast, count($items), $twoMinute);
    }

    /**
     * The opportunities of the window, one item each, oldest first: `v` a
     * vote, `v2` a vote with only the 2-minute version, `m` an opportunity
     * without a vote. `date` is null for a weekly-quota opportunity that was
     * not voted (it belongs to the week, not to a day). Always consistent
     * with `votes()`.
     *
     * @return list<array{date: string|null, mark: string}>
     */
    public function voteItems(int $days): array
    {
        $from = $this->today->clone()->subDays($days - 1);

        if ($from->lt($this->start)) {
            $from = $this->start->clone();
        }

        if ($from->gt($this->today)) {
            return [];
        }

        $this->opportunities();
        $items = [];

        foreach ($this->opportunityByDate as $date => $index) {
            if ($date >= $from->toDateString()) {
                $items[] = ['date' => $date, 'mark' => $this->voteMark($date)];
            }
        }

        foreach ($this->weeks as $week) {
            if ($week['to']->lt($from)) {
                continue;
            }

            $overlapFrom = $week['from']->lt($from) ? $from->clone() : $week['from']->clone();
            $overlap = (int) $overlapFrom->diffInDays($week['to']) + 1;
            $shownDates = $this->shownDates($overlapFrom, $week['to']->lt($this->today) ? $week['to'] : $this->today);

            if ($week['closed']) {
                $possible = $overlap >= $week['span'] ? $week['quota'] : (int) ceil($week['quota'] * $overlap / $week['span']);
            } else {
                $possible = min(count($shownDates), $week['quota']);
            }

            $voted = array_slice($shownDates, 0, min(count($shownDates), $possible));

            foreach ($voted as $date) {
                $items[] = ['date' => $date, 'mark' => $this->voteMark($date)];
            }

            for ($missing = count($voted); $missing < $possible; $missing++) {
                $items[] = ['date' => null, 'mark' => 'm'];
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $items;
    }

    /**
     * One vote mark per UTC-6 date of the inclusive range (identity votes
     * screen grid): `v` vote, `v2` vote with only the 2-minute version, `m`
     * an opportunity without a vote, `n` no opportunity that day.
     *
     * @return array<string, string>
     */
    public function voteMarks(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $this->opportunities();
        $marks = [];

        for ($cursor = $this->date($from); $cursor->lte($this->date($to)); $cursor->addDay()) {
            $date = $cursor->toDateString();

            $marks[$date] = match (true) {
                $cursor->lt($this->start), $cursor->gt($this->today) => 'n',
                $this->isShownUpOn($date) => $this->voteMark($date),
                isset($this->opportunityByDate[$date]) => 'm',
                default => 'n',
            };
        }

        return $marks;
    }

    /**
     * The level suggestion, if any. Down after the tolerant streak breaks
     * (never below level 1); up when the current level is not the last one,
     * 14 days were lived at it, and at least 80 % of the opportunities of the
     * last 14 UTC-6 days were shown up.
     */
    public function levelSuggestion(): ?LevelSuggestion
    {
        $ladder = $this->habit->level_ladder ?? [];
        $level = $this->habit->level;

        if (count($ladder) < 2 || $level === null || $level < 1 || $level > count($ladder)) {
            return null;
        }

        $streak = $this->streak();

        if ($streak->state === StreakState::Restart) {
            return $level > 1
                ? new LevelSuggestion('down', $level - 1, (string) $ladder[$level - 2]['label'], 0, 0)
                : null;
        }

        if ($level >= count($ladder)) {
            return null;
        }

        $windowStart = $this->today->clone()->subDays(13);
        $levelStart = $this->habit->level_started_on;

        if ($this->start->gt($windowStart) || ($levelStart !== null && Carbon::parse($levelStart->toDateString())->gt($windowStart))) {
            return null;
        }

        $tally = $this->votes(14);

        if ($tally->possible === 0 || $tally->cast * 5 < $tally->possible * 4) {
            return null;
        }

        return new LevelSuggestion('up', $level + 1, (string) $ladder[$level]['label'], $tally->cast, $tally->possible);
    }

    /**
     * The next UTC-6 date (after today) the habit is scheduled on, or null
     * for daily habits and weekly quotas (any day works).
     */
    public function nextScheduledDate(): ?Carbon
    {
        if ($this->habit->recurrence_type !== RecurrenceType::SpecificWeekdays || ($this->habit->weekdays ?? []) === []) {
            return null;
        }

        for ($cursor = $this->today->clone()->addDay(), $i = 0; $i < 7; $cursor->addDay(), $i++) {
            if ($this->habit->isScheduledOn($cursor)) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @return list<array{start: string, end: string, shown: bool, two_minute_only: bool}>
     */
    public function opportunities(): array
    {
        if ($this->opportunities !== null) {
            return $this->opportunities;
        }

        $this->opportunities = [];

        foreach ($this->segments() as $segment) {
            if ($segment['schedule']['recurrence'] === RecurrenceType::TimesPerWeek) {
                $this->weeklyOpportunities($segment['from'], $segment['to'], $segment['schedule']['times'], $segment['last']);
            } else {
                $this->dailyOpportunities($segment['from'], $segment['to'], $segment['schedule']);
            }
        }

        return $this->opportunities;
    }

    /**
     * One chronological pass over the opportunities: the tolerant streak and,
     * per opportunity, the run length right after it (used to say how long a
     * closed run was).
     *
     * @return array{summary: StreakSummary, run_after: list<int>}
     */
    private function walk(): array
    {
        if ($this->walked !== null) {
            return $this->walked;
        }

        $opportunities = $this->opportunities();

        $run = 0;
        $best = 0;
        $misses = 0;
        $closedRun = null;
        $twoMinute = 0;
        $since = null;
        $runAfter = [];

        foreach ($opportunities as $opportunity) {
            if ($opportunity['shown']) {
                $since = $run === 0 ? $opportunity['start'] : $since;
                $run++;
                $misses = 0;
                $twoMinute += $opportunity['two_minute_only'] ? 1 : 0;
                $best = max($best, $run);
            } else {
                $misses++;

                if ($misses === 2) {
                    $closedRun = $run > 0 ? $run : $closedRun;
                    $run = 0;
                    $twoMinute = 0;
                    $since = null;
                }
            }

            $runAfter[] = $run;
        }

        $count = count($opportunities);
        $lastMissed = $count > 0 && ! $opportunities[$count - 1]['shown'];
        $previousMissed = $count > 1 && ! $opportunities[$count - 2]['shown'];

        $state = match (true) {
            $lastMissed && $previousMissed => StreakState::Restart,
            $lastMissed => StreakState::AtRisk,
            default => StreakState::Ok,
        };

        return $this->walked = [
            'summary' => new StreakSummary(
                current: $run,
                best: $best,
                state: $state,
                closedRun: $state === StreakState::Restart ? $closedRun : null,
                twoMinuteReturns: $twoMinute,
                currentSince: $run > 0 ? $since : null,
            ),
            'run_after' => $runAfter,
        ];
    }

    /**
     * Contiguous date ranges from the habit's start to today sharing one
     * schedule.
     *
     * @return list<array{from: Carbon, to: Carbon, schedule: array{key: int, recurrence: RecurrenceType, weekdays: list<int>, times: int}, last: bool}>
     */
    private function segments(): array
    {
        $segments = [];
        $from = $this->start->clone();

        while ($from->lte($this->today)) {
            $schedule = $this->scheduleOn($from);
            $to = $schedule['key'] >= 0 ? $this->schedules[$schedule['key']]['until']->clone() : $this->today->clone();

            if ($to->gt($this->today)) {
                $to = $this->today->clone();
            }

            $segments[] = ['from' => $from->clone(), 'to' => $to, 'schedule' => $schedule, 'last' => $to->gte($this->today)];
            $from = $to->clone()->addDay();
        }

        return $segments;
    }

    /**
     * @param  array{key: int, recurrence: RecurrenceType, weekdays: list<int>, times: int}  $schedule
     */
    private function dailyOpportunities(Carbon $from, Carbon $to, array $schedule): void
    {
        for ($cursor = $from->clone(); $cursor->lte($to); $cursor->addDay()) {
            if (! $this->scheduledIn($schedule, $cursor)) {
                continue;
            }

            $date = $cursor->toDateString();
            $shown = $this->isShownUpOn($date);

            if (! $shown && ($cursor->isSameDay($this->today) || $cursor->isSameDay($this->start))) {
                continue;
            }

            $this->opportunityByDate[$date] = count($this->opportunities ?? []);
            $this->opportunities[] = [
                'start' => $date,
                'end' => $date,
                'shown' => $shown,
                'two_minute_only' => $shown && ! $this->days[$date]->completed,
            ];
        }
    }

    private function weeklyOpportunities(Carbon $from, Carbon $to, int $times, bool $last): void
    {
        for ($week = $from->clone()->startOfWeek(CarbonInterface::MONDAY); $week->lte($to); $week->addWeek()) {
            $weekStart = $week->clone();
            $weekEnd = $weekStart->clone()->addDays(6);
            $spanFrom = $weekStart->lt($from) ? $from->clone() : $weekStart->clone();
            $spanTo = $last || $weekEnd->lte($to) ? $weekEnd->clone() : $to->clone();
            $span = (int) $spanFrom->diffInDays($spanTo) + 1;
            $quota = $span >= 7 ? $times : max(1, (int) ceil($times * $span / 7));
            $closed = $spanTo->lt($this->today);
            $shownDates = $this->shownDates($spanFrom, $closed ? $spanTo : $this->today);
            $shown = count($shownDates) >= $quota;

            $this->weeks[] = ['from' => $spanFrom, 'to' => $spanTo, 'quota' => $quota, 'span' => $span, 'closed' => $closed];

            if (! $closed) {
                $this->currentWeekMet = $shown;

                if (! $shown) {
                    continue;
                }
            }

            $completed = 0;

            foreach ($shownDates as $date) {
                $completed += $this->days[$date]->completed ? 1 : 0;
            }

            $this->opportunities[] = [
                'start' => $spanFrom->toDateString(),
                'end' => $spanTo->toDateString(),
                'shown' => $shown,
                'two_minute_only' => $shown && $completed === 0,
            ];
        }
    }

    /**
     * @param  array{key: int, recurrence: RecurrenceType, weekdays: list<int>, times: int}  $schedule
     */
    private function scheduledIn(array $schedule, CarbonInterface $date): bool
    {
        return match ($schedule['recurrence']) {
            RecurrenceType::SpecificWeekdays => in_array($date->dayOfWeekIso, $schedule['weekdays'], true),
            RecurrenceType::Daily => true,
            RecurrenceType::TimesPerWeek => false,
        };
    }

    private function voteMark(string $date): string
    {
        return isset($this->days[$date]) && $this->days[$date]->isShownUp()
            ? ($this->days[$date]->completed ? 'v' : 'v2')
            : 'm';
    }

    /**
     * @return list<string> shown-up UTC-6 dates in the inclusive range
     */
    private function shownDates(Carbon $from, Carbon $to): array
    {
        $dates = [];

        for ($cursor = $from->clone(); $cursor->lte($to); $cursor->addDay()) {
            if ($this->isShownUpOn($cursor->toDateString())) {
                $dates[] = $cursor->toDateString();
            }
        }

        return $dates;
    }

    private function firstDay(): Carbon
    {
        $created = $this->habit->created_at !== null
            ? Carbon::parse($this->habit->created_at->clone()->setTimezone(Config::string('habits.timezone'))->toDateString())
            : $this->today->clone();

        $firstRecorded = array_key_first($this->days);

        if ($firstRecorded !== null && Carbon::parse($firstRecorded)->lt($created)) {
            $created = Carbon::parse($firstRecorded);
        }

        return $created->gt($this->today) ? $this->today->clone() : $created;
    }

    private function date(CarbonInterface|string $value): Carbon
    {
        return Carbon::parse($value instanceof CarbonInterface ? $value->toDateString() : $value);
    }
}
