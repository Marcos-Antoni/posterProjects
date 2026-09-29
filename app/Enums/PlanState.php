<?php

namespace App\Enums;

/**
 * The lifecycle of a plan (plans spec). `Done` is never set by hand: it is
 * reached automatically when every non-retired item of the plan is done,
 * and unchecking an item returns the plan to `Active`.
 */
enum PlanState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Done = 'done';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Active => 'Activo',
            self::Done => 'Hecho',
            self::Retired => 'Retirado',
        };
    }
}
