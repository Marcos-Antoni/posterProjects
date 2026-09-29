<?php

namespace App\Mcp\Support;

use App\Models\Habit;
use App\Models\Habits\HabitHistory;
use App\Models\User;

/**
 * The habit shape shared by the MCP habit tools: the web fields plus the
 * 2-minute version, identity, objective link, level ladder and tolerant
 * streak (computed by `HabitHistory`, same as the web). Expects `days` and
 * `objective` loaded.
 */
trait PresentsHabits
{
    /**
     * @return array<string, mixed>
     */
    protected function habitPayload(Habit $habit, HabitHistory $history, ResourceLinker $links): array
    {
        $streak = $history->streak();

        return [
            'id' => $habit->id,
            'name' => $habit->name,
            'two_minute_version' => (string) $habit->two_minute_version,
            'identity_statement' => $habit->identity_statement,
            'effective_identity_statement' => $habit->effectiveIdentityStatement(),
            'habit_type' => $habit->habit_type,
            'unit' => $habit->unit,
            'daily_target' => $habit->daily_target,
            'recurrence_type' => $habit->recurrence_type,
            'weekdays' => $habit->weekdays,
            'times_per_week' => $habit->times_per_week,
            'planned_time' => $habit->planned_time,
            'objective_key' => $habit->objective?->key,
            'plan_id' => $habit->plan_id,
            'level' => $habit->level,
            'level_ladder' => $habit->level_ladder,
            'streak_current' => $streak->current,
            'streak_best' => $streak->best,
            'streak_state' => $streak->state->value,
            'retired_at' => $habit->retired_at?->toIso8601String(),
            'url' => $links->habit($habit),
        ];
    }

    /**
     * The caller's habit by id with its history loaded, or null (another
     * user's habit is simply not found).
     */
    protected function ownedHabit(User $user, mixed $habitId): ?Habit
    {
        return $user->habits()->with(['days', 'schedulePeriods', 'objective'])->whereKey($habitId)->first();
    }
}
