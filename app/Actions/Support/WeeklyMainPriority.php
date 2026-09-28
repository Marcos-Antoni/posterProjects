<?php

namespace App\Actions\Support;

use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/**
 * The owner's main priority for the current ISO week (UTC-6), an objective
 * or a plan (reviews spec "The Weekly Priority Is One Main Priority…"). It
 * drives the Now suggestion (now-focus, design D6). Phase 7 owns
 * `weekly_priorities` and rebinds this contract in AppServiceProvider; until
 * then `NoWeeklyMainPriority` answers "none".
 */
interface WeeklyMainPriority
{
    public function currentFor(User $owner): Objective|Plan|null;
}
