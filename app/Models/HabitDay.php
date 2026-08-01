<?php

namespace App\Models;

use Database\Factories\HabitDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The persisted daily aggregate for a habit: accumulated amount,
 * completion percent (kept as recorded — may be under or over 100),
 * and the planned-vs-actual delta of the first entry of the day.
 * `entry_date` is the day in the feature's fixed UTC-6 zone.
 *
 * `peak_amount` is the day's high-water mark: it never drops, even when
 * `accumulated_amount` is corrected downward by a decrement. `completed`
 * is sticky once `peak_amount` reaches the habit's target and MUST NOT be
 * recomputed from `accumulated_amount` alone — see the `habits` spec's
 * "Entries Accumulate With A Persisted Real Percent" requirement.
 *
 * @property int $id
 * @property int $habit_id
 * @property Carbon $entry_date
 * @property int $accumulated_amount
 * @property int $peak_amount
 * @property int $completion_percent
 * @property bool $completed
 * @property int|null $planned_delta_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'habit_id',
    'entry_date',
    'accumulated_amount',
    'peak_amount',
    'completion_percent',
    'completed',
    'planned_delta_minutes',
])]
class HabitDay extends Model
{
    /** @use HasFactory<HabitDayFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'completed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Habit, $this>
     */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
