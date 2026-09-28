<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HabitTodayCollection;
use App\Http\Resources\HabitTodayResource;
use App\Models\Habit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HabitController extends Controller
{
    /**
     * List the authenticated user's active habits scheduled for the
     * current UTC-6 day, each with its progress for the day. Same data
     * as the web "Today" view and the `TodayHabits` MCP tool, assembled
     * through the shared `HabitTodayResource` (design D-4) so this read
     * shape and both write responses can never drift apart.
     *
     * `days` (the whole history: the tolerant streak reads it) and
     * `objective` are eager-loaded once, so neither `week_recorded_days`,
     * `streak_*` nor `objective_key` issues a query per habit.
     */
    public function today(Request $request): HabitTodayCollection
    {
        Gate::authorize('viewAny', Habit::class);

        $today = Habit::todayLocalDate();

        $habits = $request->user()
            ->habits()
            ->notArchived()
            ->with(['days', 'schedulePeriods', 'objective'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Habit $habit): bool => $habit->isScheduledOn($today))
            ->values()
            ->map(fn (Habit $habit): HabitTodayResource => HabitTodayResource::forHabit($habit, $today));

        return new HabitTodayCollection($habits);
    }
}
