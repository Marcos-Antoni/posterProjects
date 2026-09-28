<?php

namespace App\Enums;

/**
 * The three zones of a control map (control-plan spec). Only the first two
 * may become tasks; `Outside` is written down to let it go.
 */
enum ControlZone: string
{
    case Mine = 'mine';
    case Influence = 'influence';
    case Outside = 'outside';

    public function label(): string
    {
        return match ($this) {
            self::Mine => 'Depende de mí',
            self::Influence => 'Puedo influir',
            self::Outside => 'No depende de mí',
        };
    }

    public function canBecomeTask(): bool
    {
        return $this !== self::Outside;
    }
}
