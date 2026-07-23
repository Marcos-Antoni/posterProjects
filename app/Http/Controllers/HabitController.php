<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHabitRequest;
use App\Http\Requests\UpdateHabitRequest;
use App\Models\Habit;
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
