<?php

namespace App\Enums;

/**
 * What a review closes the loop on (reviews spec): the weekly priority
 * review, or an objective's learning review at close time (the milestone
 * summit's evidence lives in `milestone_evidence`, not here).
 */
enum ReviewKind: string
{
    case Weekly = 'weekly';
    case Objective = 'objective';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Revisión semanal',
            self::Objective => 'Cierre de objetivo',
        };
    }
}
