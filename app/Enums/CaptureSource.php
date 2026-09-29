<?php

namespace App\Enums;

/**
 * Where a capture came from (capture-inbox spec): the web quick-entry
 * overlay, the mobile app, or the AI noting something down while it works.
 */
enum CaptureSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Desde la web',
            self::Mobile => 'Desde el teléfono',
            self::Ai => 'Anotada por la IA',
        };
    }
}
