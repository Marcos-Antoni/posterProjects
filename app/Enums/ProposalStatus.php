<?php

namespace App\Enums;

/**
 * The lifecycle of an AI proposal (ai-operations spec, trimmed slice):
 * `Pending` until Marco decides, then `Accepted` (applied exactly as
 * proposed) or `Rejected` (never applied).
 */
enum ProposalStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Accepted => 'Aceptada',
            self::Rejected => 'Rechazada',
        };
    }
}
