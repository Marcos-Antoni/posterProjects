<?php

namespace App\Models;

use Database\Factories\HabitEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single log against a habit. `logged_at` is the automatic UTC
 * timestamp of the moment the entry was recorded; the habit-day it
 * belongs to is derived from it in the feature's fixed UTC-6 zone.
 *
 * @property int $id
 * @property int $habit_id
 * @property int $amount
 * @property Carbon $logged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['habit_id', 'amount', 'logged_at'])]
class HabitEntry extends Model
{
    /** @use HasFactory<HabitEntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
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
