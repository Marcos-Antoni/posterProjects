<?php

namespace App\Actions\Support;

use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use App\Models\WeeklyPriority;

/**
 * Phase 7's `WeeklyMainPriority`: the main priority the owner set for the
 * current ISO week (UTC-6) in the weekly review, an objective or a plan.
 * Answers "none" once the main priority no longer resolves (e.g. its
 * objective was retired since), the same as before Phase 7 existed.
 */
class WeeklyPriorityReader implements WeeklyMainPriority
{
    public function currentFor(User $owner): Objective|Plan|null
    {
        $week = WeeklyPriority::currentWeek();

        $priority = WeeklyPriority::query()
            ->where('user_id', $owner->id)
            ->where('iso_year', $week['year'])
            ->where('iso_week', $week['week'])
            ->first();

        if ($priority === null) {
            return null;
        }

        $main = $priority->main;

        return match (true) {
            $main instanceof Objective && $main->user_id === $owner->id => $main,
            $main instanceof Plan && $main->objective->user_id === $owner->id => $main,
            default => null,
        };
    }
}
