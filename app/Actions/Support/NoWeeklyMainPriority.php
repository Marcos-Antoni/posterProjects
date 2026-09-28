<?php

namespace App\Actions\Support;

use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/**
 * The default `WeeklyMainPriority` while weekly reviews do not exist
 * (Phase 7): there is never a main priority, so Now falls back to the first
 * active objective.
 */
class NoWeeklyMainPriority implements WeeklyMainPriority
{
    public function currentFor(User $owner): Objective|Plan|null
    {
        return null;
    }
}
