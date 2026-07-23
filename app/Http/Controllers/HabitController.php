<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHabitRequest;
use App\Http\Requests\UpdateHabitRequest;
use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HabitController extends Controller
{
    /**
     * Display the authenticated user's habits (active and archived — the
     * page splits them client-side).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Habit::class);

        $habits = $request->user()
            ->habits()
            ->orderBy('name')
            ->get();

        return Inertia::render('habits/index', [
            'habits' => $habits,
        ]);
    }

    /**
     * Display a habit's history: streaks, completion for the selected
     * period, and the daily series (date, percent, planned delta) the
     * evolution chart consumes. Everything is computed on read — no
     * caching. Owner only.
     */
    public function show(Request $request, Habit $habit): Response
    {
        Gate::authorize('view', $habit);

        $periodDays = min(365, max(7, $request->integer('days', 30)));

        $to = $habit->todayLocalDate();
        $from = $to->clone()->subDays($periodDays - 1);

        $dayRows = $habit->days()
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (HabitDay $day): string => $day->entry_date->toDateString());

        $series = [];

        for ($cursor = $from->clone(); $cursor->lte($to); $cursor->addDay()) {
            $row = $dayRows->get($cursor->toDateString());

            $series[] = [
                'date' => $cursor->toDateString(),
                'scheduled' => $habit->isScheduledOn($cursor),
                'completion_percent' => $row->completion_percent ?? 0,
                'completed' => $row !== null && $row->completed,
                'planned_delta_minutes' => $row?->planned_delta_minutes,
            ];
        }

        return Inertia::render('habits/show', [
            'habit' => $habit,
            'metrics' => [
                'current_streak' => $habit->currentStreak(),
                'best_streak' => $habit->bestStreak(),
                'completion_percent' => $habit->completionForPeriod($from, $to),
            ],
            'series' => $series,
            'period_days' => $periodDays,
        ]);
    }

    /**
     * Create a new habit owned by the authenticated user. Fields that
     * don't apply to the chosen type/recurrence are excluded by the
     * request's `exclude_unless` rules, so they persist as null.
     */
    public function store(StoreHabitRequest $request): RedirectResponse
    {
        Habit::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('habits.index');
    }

    /**
     * Update a habit. Owner only — enforced by
     * `UpdateHabitRequest::authorize()`. Conditional fields are reset to
     * null first so switching type or recurrence never leaves stale
     * values behind (the validated payload overrides the ones that apply).
     */
    public function update(UpdateHabitRequest $request, Habit $habit): RedirectResponse
    {
        $habit->update([
            'unit' => null,
            'daily_target' => null,
            'weekdays' => null,
            'times_per_week' => null,
            ...$request->validated(),
        ]);

        return redirect()->route('habits.index');
    }

    /**
     * Archive a habit. It keeps its full history and can be reactivated
     * at any time — there is no destroy. Owner only.
     */
    public function archive(Habit $habit): RedirectResponse
    {
        Gate::authorize('archive', $habit);

        $habit->update(['archived_at' => now()]);

        return redirect()->route('habits.index');
    }

    /**
     * Reactivate an archived habit. Owner only.
     */
    public function unarchive(Habit $habit): RedirectResponse
    {
        Gate::authorize('unarchive', $habit);

        $habit->update(['archived_at' => null]);

        return redirect()->route('habits.index');
    }
}
