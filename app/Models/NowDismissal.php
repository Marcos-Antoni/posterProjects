<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Cerrar por hoy" (Now screen): the item is not suggested again on
 * `local_date` (America/Guatemala day). Starting it explicitly still works.
 *
 * @property int $id
 * @property int $user_id
 * @property int $item_id
 * @property Carbon $local_date
 */
#[Fillable(['user_id', 'item_id', 'local_date'])]
class NowDismissal extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['local_date' => 'date'];
    }

    /**
     * Record that the owner closed this item for today (idempotent).
     */
    public static function recordToday(User $owner, Item $item): void
    {
        self::query()->insertOrIgnore([
            'user_id' => $owner->id,
            'item_id' => $item->id,
            'local_date' => Habit::todayLocalDate()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Whether the owner closed anything for today.
     */
    public static function anyToday(User $owner): bool
    {
        return self::query()
            ->where('user_id', $owner->id)
            ->where('local_date', Habit::todayLocalDate()->toDateString())
            ->exists();
    }
}
