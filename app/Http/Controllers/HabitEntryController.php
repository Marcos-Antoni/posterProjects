<?php

namespace App\Http\Controllers;

use App\Actions\Habits\DecrementHabitEntry;
use App\Actions\Habits\LogHabitEntry;
use App\Actions\Habits\LogTwoMinute;
use App\Actions\Habits\UndoTwoMinute;
use App\Actions\Support\Actor;
use App\Http\Requests\StoreHabitEntryRequest;
use App\Models\Habit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Logging against a habit from the web, always on the current UTC-6 day:
 * an entry ("Marcar hecho" / the stepper's +), a correction (the stepper's
 * −), and the 2-minute version ("Solo los 2 minutos", "Retomar con 2
 * minutos") with its "Deshacer".
 */
class HabitEntryController extends Controller
{
    /**
     * Record an entry and fold it into today's aggregate — see
     * `Habit::recordEntry()`. Yes/no habits log a fixed amount of 1.
     */
    public function store(StoreHabitEntryRequest $request, Habit $habit, LogHabitEntry $logEntry): RedirectResponse
    {
        $amount = $request->validated('amount');

        // The `integer` rule accepts numeric strings without casting them,
        // and real browser submissions (FormData) always send strings — so
        // the value must be cast, not type-checked with is_int().
        $logEntry(Actor::ownerWeb($request->user()), $habit, is_numeric($amount) ? (int) $amount : 1);

        return back();
    }

    /**
     * Subtract one from today's amount (never below zero, never
     * un-completing the day).
     */
    public function decrement(Request $request, Habit $habit, DecrementHabitEntry $decrement): RedirectResponse
    {
        Gate::authorize('logEntry', $habit);

        $decrement(Actor::ownerWeb($request->user()), $habit);

        return back();
    }

    /**
     * Log the 2-minute version today: shown-up, never `completed`.
     */
    public function twoMinute(Request $request, Habit $habit, LogTwoMinute $logTwoMinute): RedirectResponse
    {
        Gate::authorize('logEntry', $habit);

        $logTwoMinute(Actor::ownerWeb($request->user()), $habit);

        return back();
    }

    /**
     * Undo today's 2-minute version.
     */
    public function undoTwoMinute(Request $request, Habit $habit, UndoTwoMinute $undo): RedirectResponse
    {
        Gate::authorize('logEntry', $habit);

        $undo(Actor::ownerWeb($request->user()), $habit);

        return back();
    }
}
