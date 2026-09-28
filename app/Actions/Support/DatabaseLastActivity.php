<?php

namespace App\Actions\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The latest of: an item completed, a habit entry logged, a focus session
 * started. One query.
 */
class DatabaseLastActivity implements LastActivity
{
    public function lastActivityAt(User $owner): ?CarbonInterface
    {
        $last = DB::selectOne(<<<'SQL'
            SELECT GREATEST(
                (SELECT MAX(completed_at) FROM items WHERE user_id = ?),
                (SELECT MAX(habit_entries.logged_at) FROM habit_entries INNER JOIN habits ON habits.id = habit_entries.habit_id WHERE habits.user_id = ?),
                (SELECT MAX(focus_sessions.started_at) FROM focus_sessions INNER JOIN items ON items.id = focus_sessions.item_id WHERE items.user_id = ?)
            ) AS last_activity
            SQL, [$owner->id, $owner->id, $owner->id])?->last_activity;

        return $last === null ? null : Carbon::parse($last, 'UTC');
    }
}
