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
}
