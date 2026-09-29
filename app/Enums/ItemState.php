<?php

namespace App\Enums;

/**
 * The derived state of an item (issues spec, design D2). It is never
 * stored: `Item::scopeWithState()` computes it in SQL and
 * `Item::deriveState()` in PHP, with the same precedence.
 */
enum ItemState: string
{
    case Locked = 'locked';
    case Available = 'available';
    case Active = 'active';
    case Done = 'done';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Locked => 'Bloqueada',
            self::Available => 'Disponible',
            self::Active => 'Activa',
            self::Done => 'Hecha',
            self::Retired => 'Retirada',
        };
    }
}
