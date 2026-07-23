<?php

namespace App\Models;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use Database\Factories\HabitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
}
