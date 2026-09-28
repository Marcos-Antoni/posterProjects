<?php

namespace App\Models;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Models\Habits\HabitHistory;
use Carbon\CarbonInterface;
use Database\Factories\HabitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $objective_id
 * @property int|null $plan_id
 * @property string $name
 * @property string|null $two_minute_version
 * @property string|null $identity_statement
 * @property HabitType $habit_type
 * @property string|null $unit
 * @property int|null $daily_target
 * @property RecurrenceType $recurrence_type
 * @property list<int>|null $weekdays
 * @property int|null $times_per_week
 * @property string|null $planned_time
 * @property int|null $level
 * @property list<array{label: string, target: int|null, two_minute_version: string}>|null $level_ladder
 * @property Carbon|null $level_started_on
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'objective_id',
    'plan_id',
    'name',
    'two_minute_version',
    'identity_statement',
    'habit_type',
    'unit',
    'daily_target',
    'recurrence_type',
    'weekdays',
    'times_per_week',
    'planned_time',
    'level',
    'level_ladder',
    'level_started_on',
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
            'level' => 'integer',
            'level_ladder' => 'array',
            'level_started_on' => 'date',
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
     * Habits that still accept entries and show in today/votes (phase 4:
     * not archived). The single place that knows how "archived" is stored,
     * so Phase 6's retirement (retired_at + NotRetired scope) changes only
     * this model.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The objective the habit hangs from, if any. The link is informational:
     * the objective's lifecycle never archives or modifies the habit.
     *
     * @return BelongsTo<Objective, $this>
     */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /**
     * The plan (of the linked objective) the habit hangs from, if any.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The schedules this habit had before its current one, oldest first
     * (effective-dated: see `HabitSchedulePeriod`).
     *
     * @return HasMany<HabitSchedulePeriod, $this>
     */
    public function schedulePeriods(): HasMany
    {
        return $this->hasMany(HabitSchedulePeriod::class)->orderBy('valid_until')->orderBy('id');
    }

    /**
     * The habit's day history read model (tolerant streak, marks, votes,
     * level suggestion), computed on read from the `days` relation. Loads
     * every day of the habit unless the relation is already loaded, so a
     * caller listing many habits eager-loads `days` once.
     */
    public function history(?Carbon $today = null): HabitHistory
    {
        return new HabitHistory($this, $today ?? self::todayLocalDate());
    }

    /**
     * The identity statement this habit votes for: its own, else its
     * objective's (habits spec: inheritance from objective).
     */
    public function effectiveIdentityStatement(): ?string
    {
        $own = trim((string) $this->identity_statement);

        if ($own !== '') {
            return $own;
        }

        $inherited = trim((string) $this->objective?->identity_statement);

        return $inherited !== '' ? $inherited : null;
    }

    /**
     * The day's target amount: `max(1, daily_target)` for quantitative
     * habits, 1 for yes/no.
     */
    public function targetAmount(): int
    {
        return $this->habit_type === HabitType::Quantitative
            ? max(1, (int) $this->daily_target)
            : 1;
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
     * (same locking pattern as `Objective::allocateNextItemNumber()`).
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
            $day->peak_amount = max($day->peak_amount, $accumulated);
            $day->completion_percent = (int) round($accumulated / $target * 100);
            $day->completed = $day->completed || $day->peak_amount >= $target;

            if ($claimedDay && $this->planned_time !== null) {
                $plannedTime = $localTime->clone()->setTimeFromTimeString($this->planned_time);

                $day->planned_delta_minutes = (int) round($plannedTime->diffInMinutes($localTime));
            }

            $day->save();

            return $entry;
        });
    }

    /**
     * Subtract one from the current UTC-6 day's aggregate. Never writes to
     * `habit_entries`: the ledger is a log of actions, not of results.
     *
     * The day is claimed by NOTHING — a missing row is a rejection, not a
     * row to create, so this is `recordEntry()`'s lock discipline minus the
     * `insertOrIgnore` claim step.
     *
     * The zero floor lives here and ONLY here: `accumulated_amount` is
     * declared `unsignedInteger` but resolves to a plain `int4` on Postgres,
     * so the database would store `-1` without complaining.
     *
     * @throws ValidationException when today has no row, or it is already 0.
     */
    public function decrementToday(): HabitDay
    {
        return DB::transaction(function (): HabitDay {
            $entryDate = self::todayLocalDate()->toDateString();

            $day = $this->days()->where('entry_date', $entryDate)->lockForUpdate()->first();

            if ($day === null || $day->accumulated_amount < 1) {
                throw ValidationException::withMessages(['habit' => 'No hay nada que descontar hoy.']);
            }

            $target = $this->habit_type === HabitType::Quantitative
                ? max(1, (int) $this->daily_target)
                : 1;
            $accumulated = $day->accumulated_amount - 1;

            $day->accumulated_amount = $accumulated;
            $day->completion_percent = (int) round($accumulated / $target * 100);
            $day->completed = $day->completed || $day->peak_amount >= $target;
            $day->save();

            return $day;
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
     * The current tolerant streak ("never miss twice", design D5), in
     * opportunities: days, scheduled weekdays or weeks. See `HabitHistory`.
     */
    public function currentStreak(): int
    {
        return $this->history()->streak()->current;
    }

    /**
     * The best tolerant streak across the habit's whole history.
     */
    public function bestStreak(): int
    {
        return $this->history()->streak()->best;
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
    public static function todayLocalDate(): Carbon
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
}
