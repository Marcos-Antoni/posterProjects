<?php

namespace App\Models;

use App\Enums\RecurrenceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A schedule a habit had until `valid_until` (inclusive, UTC-6 date). Kept so
 * that changing a habit's recurrence never rewrites its past: each past day
 * is evaluated with the schedule in force that day.
 *
 * @property int $id
 * @property int $habit_id
 * @property RecurrenceType $recurrence_type
 * @property list<int>|null $weekdays
 * @property int|null $times_per_week
 * @property Carbon $valid_until
 */
#[Fillable(['habit_id', 'recurrence_type', 'weekdays', 'times_per_week', 'valid_until'])]
class HabitSchedulePeriod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recurrence_type' => RecurrenceType::class,
            'weekdays' => 'array',
            'times_per_week' => 'integer',
            'valid_until' => 'date',
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
