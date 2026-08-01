<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HabitTodayResource;
use App\Models\Habit;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

class HabitEntryController extends Controller
{
    /**
     * Tap "sumar": add 1 to today's aggregate for `{habit}`. The phone
     * never sends an amount — every tap is a fixed +1.
     */
    public function increment(Request $request, int $habit): HabitTodayResource
    {
        $resolvedHabit = $this->resolveHabit($request, $habit);

        $resolvedHabit->recordEntry(1);

        return $this->respond($resolvedHabit);
    }

    /**
     * Tap "restar": subtract 1 from today's aggregate for `{habit}`,
     * without descumpling an already-completed day and never below zero.
     * Throws a `422` when today has no accumulated amount to correct.
     */
    public function decrement(Request $request, int $habit): HabitTodayResource
    {
        $resolvedHabit = $this->resolveHabit($request, $habit);

        $resolvedHabit->decrementToday();

        return $this->respond($resolvedHabit);
    }

    /**
     * Resolve `{habit}` scoped to the requesting user's active habits.
     * No route-model binding (design D-5): unknown id, another user's
     * habit, and an archived habit all collapse into the same
     * `ModelNotFoundException` -> generic `404`, never disclosing which.
     */
    private function resolveHabit(Request $request, int $habit): Habit
    {
        return $request->user()
            ->habits()
            ->whereNull('archived_at')
            ->whereKey($habit)
            ->firstOrFail();
    }

    /**
     * Build the response through the same assembler the "today" list uses
     * (design D-4), after loading the current week's days so
     * `week_recorded_days` and the resolved day row are both available.
     */
    private function respond(Habit $habit): HabitTodayResource
    {
        $today = Habit::todayLocalDate();
        $weekStart = $today->clone()->startOfWeek(CarbonInterface::MONDAY);

        $habit->load(['days' => fn ($query) => $query->whereBetween(
            'entry_date',
            [$weekStart->toDateString(), $today->toDateString()],
        )]);

        return HabitTodayResource::forHabit($habit, $today);
    }
}
