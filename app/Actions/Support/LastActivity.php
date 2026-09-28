<?php

namespace App\Actions\Support;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * When the owner last did something that counts as showing up (now-focus
 * "No Punishment…": a day without activity is followed by a restart offer).
 * The default reads checked items, habit entries and started tasks; Phase 4
 * extends it at merge with habit days whose 2-minute version was logged.
 */
interface LastActivity
{
    public function lastActivityAt(User $owner): ?CarbonInterface;
}
