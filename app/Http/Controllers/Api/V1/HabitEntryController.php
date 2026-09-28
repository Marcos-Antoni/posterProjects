<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Habits\DecrementHabitEntry;
use App\Actions\Habits\LogHabitEntry;
use App\Actions\Habits\LogTwoMinute;
use App\Actions\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Resources\HabitTodayResource;
use App\Models\Habit;
use Illuminate\Http\Request;

class HabitEntryController extends Controller
{
    /**
     * Tap "sumar": add 1 to today's aggregate for `{habit}`. The phone
     * never sends an amount — every tap is a fixed +1.
     */
    public function increment(Request $request, int $habit, LogHabitEntry $logEntry): HabitTodayResource
    {
        $resolvedHabit = $this->resolveHabit($request, $habit);

        $logEntry(Actor::ownerApi($request->user()), $resolvedHabit, 1);

        return $this->respond($resolvedHabit);
    }

    /**
     * Tap "restar": subtract 1 from today's aggregate for `{habit}`,
     * without descumpling an already-completed day and never below zero.
     * Throws a `422` when today has no accumulated amount to correct.
     */
    public function decrement(Request $request, int $habit, DecrementHabitEntry $decrement): HabitTodayResource
    {
        $resolvedHabit = $this->resolveHabit($request, $habit);

        $decrement(Actor::ownerApi($request->user()), $resolvedHabit);

        return $this->respond($resolvedHabit);
    }

    /**
     * Log the 2-minute version today (UTC-6): shown-up for the tolerant
     * streak and identity votes, never `completed`. Idempotent within the
     * day — a second call answers the same element. Same non-disclosing 404
     * set as increment/decrement.
     */
    public function twoMinute(Request $request, int $habit, LogTwoMinute $logTwoMinute): HabitTodayResource
    {
        $resolvedHabit = $this->resolveHabit($request, $habit);

        $logTwoMinute(Actor::ownerApi($request->user()), $resolvedHabit);

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
            ->notArchived()
            ->whereKey($habit)
            ->firstOrFail();
    }

    /**
     * Build the response through the same assembler the "today" list uses
     * (design D-4), after loading the habit's days (the tolerant streak reads
     * the history) and objective.
     */
    private function respond(Habit $habit): HabitTodayResource
    {
        $habit->load(['days', 'schedulePeriods', 'objective']);

        return HabitTodayResource::forHabit($habit, Habit::todayLocalDate());
    }
}
