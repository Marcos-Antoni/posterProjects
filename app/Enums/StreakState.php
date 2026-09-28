<?php

namespace App\Enums;

/**
 * The tolerant streak's state (design D5): `restart` when the two most recent
 * opportunities were missed (the run closed and is kept as history),
 * `at_risk` when exactly the most recent one was missed ("hoy toca volver"),
 * `ok` otherwise. Never a debt: no state counts missed days.
 */
enum StreakState: string
{
    case Ok = 'ok';
    case AtRisk = 'at_risk';
    case Restart = 'restart';
}
