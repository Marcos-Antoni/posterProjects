<?php

namespace App\Models;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use Carbon\CarbonInterface;
use Database\Factories\HabitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property HabitType $habit_type
 * @property string|null $unit
 * @property int|null $daily_target
 * @property RecurrenceType $recurrence_type
 * @property list<int>|null $weekdays
 * @property int|null $times_per_week
 * @property string|null $planned_time
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'name',
    'habit_type',
    'unit',
    'daily_target',
    'recurrence_type',
    'weekdays',
    'times_per_week',
    'planned_time',
    'archived_at',
])]
class Habit extends Model
{
    /** @use HasFactory<HabitFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * `weekdays` holds ISO-8601 weekday numbers (1 = Monday .. 7 = Sunday),
     * matching the feature's Monday-based week.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'habit_type' => HabitType::class,
            'recurrence_type' => RecurrenceType::class,
            'weekdays' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<HabitEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(HabitEntry::class);
    }

    /**
     * @return HasMany<HabitDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(HabitDay::class);
    }

    /**
     * Record a partial entry now and transactionally roll it into the
     * habit-day of the current UTC-6 day: accumulated amount, the real
     * completion percent (kept as recorded — under or over 100), the
     * completed flag, and — for the first entry of the day of a habit
     * with a planned time — the planned-vs-actual delta in minutes.
     *
     * The day row is claimed with `INSERT ... ON CONFLICT DO NOTHING`
     * and then re-read under `lockForUpdate`, so concurrent entries
     * against the same day serialize instead of losing increments
     * (same locking pattern as `Project::allocateNextIssueNumber()`).
     */
    public function recordEntry(int $amount): HabitEntry
    {
        return DB::transaction(function () use ($amount): HabitEntry {
            $entry = $this->entries()->create([
                'amount' => $amount,
                'logged_at' => now(),
            ]);

            $localTime = $entry->logged_at->clone()->setTimezone(Config::string('habits.timezone'));
            $entryDate = $localTime->toDateString();

            $claimedDay = HabitDay::query()->insertOrIgnore([
                'habit_id' => $this->id,
                'entry_date' => $entryDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            /** @var HabitDay $day */
            $day = $this->days()
                ->where('entry_date', $entryDate)
                ->lockForUpdate()
                ->firstOrFail();

            $target = $this->habit_type === HabitType::Quantitative
                ? max(1, (int) $this->daily_target)
                : 1;

            $accumulated = $day->accumulated_amount + $amount;

            $day->accumulated_amount = $accumulated;
            $day->completion_percent = (int) round($accumulated / $target * 100);
            $day->completed = $accumulated >= $target;

            if ($claimedDay && $this->planned_time !== null) {
                $plannedTime = $localTime->clone()->setTimeFromTimeString($this->planned_time);

                $day->planned_delta_minutes = (int) round($plannedTime->diffInMinutes($localTime));
            }

            $day->save();

            return $entry;
        });
    }

    /**
     * Whether this habit is expected on the given date. Daily and
     * times-per-week habits accept any day (the weekly quota is checked
     * per week, not per day); specific-weekdays habits only count their
     * scheduled ISO weekdays.
     */
    public function isScheduledOn(CarbonInterface $date): bool
    {
        if ($this->recurrence_type !== RecurrenceType::SpecificWeekdays) {
            return true;
        }

        return in_array($date->dayOfWeekIso, $this->weekdays ?? [], true);
    }

    /**
     * The current streak, computed on read (never cached), in days:
     *
     * - daily: consecutive completed days ending today (a still-pending
     *   today doesn't break the run).
     * - specific weekdays: same, but only scheduled days count — the
     *   days in between are skipped without breaking.
     * - times per week: every recorded day adds one, with weekly
     *   forgiveness — the run only resets when a week closes under
     *   quota (the in-progress week can never break it).
     */
    public function currentStreak(): int
    {
        $days = $this->daysByDate();

        if ($days->isEmpty()) {
            return 0;
        }

        if ($this->recurrence_type === RecurrenceType::TimesPerWeek) {
            return $this->timesPerWeekStreaks($days)['current'];
        }

        $today = $this->todayLocalDate();
        $earliest = Carbon::parse((string) $days->keys()->first());
        $streak = 0;

        for ($cursor = $today->clone(); $cursor->gte($earliest); $cursor->subDay()) {
            if (! $this->isScheduledOn($cursor)) {
                continue;
            }

            $day = $days->get($cursor->toDateString());

            if ($day !== null && $day->completed) {
                $streak++;

                continue;
            }

            if ($cursor->isSameDay($today)) {
                continue;
            }

            break;
        }

        return $streak;
    }

    /**
     * The best streak across the habit's whole history, under the same
     * rules as `currentStreak()`.
     */
    public function bestStreak(): int
    {
        $days = $this->daysByDate();

        if ($days->isEmpty()) {
            return 0;
        }

        if ($this->recurrence_type === RecurrenceType::TimesPerWeek) {
            return $this->timesPerWeekStreaks($days)['best'];
        }

        $today = $this->todayLocalDate();
        $earliest = Carbon::parse((string) $days->keys()->first());
        $best = 0;
        $run = 0;

        for ($cursor = $earliest->clone(); $cursor->lte($today); $cursor->addDay()) {
            if (! $this->isScheduledOn($cursor)) {
                continue;
            }

            $day = $days->get($cursor->toDateString());

            if ($day !== null && $day->completed) {
                $run++;
                $best = max($best, $run);

                continue;
            }

            if (! $cursor->isSameDay($today)) {
                $run = 0;
            }
        }

        return $best;
    }

    /**
     * The completion percentage for a date range (inclusive, UTC-6
     * dates), computed on read: achieved days over expected days.
     * Expected days depend on the recurrence — every day for daily,
     * scheduled days for specific weekdays, and a pro-rated
     * `times_per_week / 7` per day for weekly quotas (where any
     * recorded day counts as achieved, completed or not).
     */
    public function completionForPeriod(CarbonInterface $from, CarbonInterface $to): int
    {
        $days = $this->daysByDate();

        $expected = 0.0;
        $achieved = 0;

        for ($cursor = Carbon::parse($from->toDateString()); $cursor->lte($to); $cursor->addDay()) {
            $day = $days->get($cursor->toDateString());

            if ($this->recurrence_type === RecurrenceType::TimesPerWeek) {
                $expected += max(1, (int) $this->times_per_week) / 7;
                $achieved += $day !== null ? 1 : 0;

                continue;
            }

            if (! $this->isScheduledOn($cursor)) {
                continue;
            }

            $expected += 1;
            $achieved += ($day !== null && $day->completed) ? 1 : 0;
        }

        if ($expected <= 0) {
            return 0;
        }

        return (int) round($achieved / $expected * 100);
    }

    /**
     * Today's date in the feature's fixed UTC-6 zone, normalized to a
     * plain (UTC-midnight) Carbon so date comparisons never drift on
     * timezone offsets.
     */
    public function todayLocalDate(): Carbon
    {
        return Carbon::parse(now()->setTimezone(Config::string('habits.timezone'))->toDateString());
    }

    /**
     * @return Collection<string, HabitDay>
     */
    private function daysByDate(): Collection
    {
        return $this->days()
            ->orderBy('entry_date')
            ->get()
            ->keyBy(fn (HabitDay $day): string => $day->entry_date->toDateString());
    }

    /**
     * Day-by-day chronological walk implementing the weekly-forgiveness
     * streak: every recorded day adds one immediately, and the run only
     * resets when a Monday-based week closes (its Sunday is strictly in
     * the past) with fewer recorded days than the quota.
     *
     * @param  Collection<string, HabitDay>  $days
     * @return array{current: int, best: int}
     */
    private function timesPerWeekStreaks(Collection $days): array
    {
        $quota = max(1, (int) $this->times_per_week);
        $today = $this->todayLocalDate();
        $start = Carbon::parse((string) $days->keys()->first())->startOfWeek(CarbonInterface::MONDAY);

        $streak = 0;
        $best = 0;
        $recordedThisWeek = 0;

        for ($cursor = $start->clone(); $cursor->lte($today); $cursor->addDay()) {
            if ($days->has($cursor->toDateString())) {
                $streak++;
                $recordedThisWeek++;
                $best = max($best, $streak);
            }

            if ($cursor->dayOfWeekIso === 7) {
                if ($cursor->lt($today) && $recordedThisWeek < $quota) {
                    $streak = 0;
                }

                $recordedThisWeek = 0;
            }
        }

        return ['current' => $streak, 'best' => $best];
    }
}
