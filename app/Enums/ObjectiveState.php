<?php

namespace App\Enums;

/**
 * The lifecycle of an objective (projects spec): `Draft` exists only inside
 * a tree negotiation, `Active` is the working state, `Closed` is finished
 * with a learning review and `Retired` is abandoned through the retirement
 * protocol. There is no deleted state: nothing is deleted.
 */
enum ObjectiveState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case Retired = 'retired';

    /**
     * The transitions the lifecycle allows. Closing (learning review) and
     * retiring (retirement protocol) are driven by their own flows.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Active, self::Retired], true),
            self::Active => in_array($target, [self::Closed, self::Retired], true),
            self::Closed => $target === self::Active,
            self::Retired => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Active => 'Activo',
            self::Closed => 'Cerrado',
            self::Retired => 'Retirado',
        };
    }
}
