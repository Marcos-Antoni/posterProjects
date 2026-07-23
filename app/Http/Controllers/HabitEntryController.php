<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHabitEntryRequest;
use App\Models\Habit;
use Illuminate\Http\RedirectResponse;

class HabitEntryController extends Controller
{
    /**
     * Record an entry against a habit and fold it into the current
     * UTC-6 day's aggregate — see `Habit::recordEntry()`. Yes/no
     * habits log a fixed amount of 1 per check-in.
     */
    public function store(StoreHabitEntryRequest $request, Habit $habit): RedirectResponse
    {
        $amount = $request->validated('amount');

        $habit->recordEntry(is_int($amount) ? $amount : 1);

        return back();
    }
}
