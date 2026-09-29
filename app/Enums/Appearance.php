<?php

namespace App\Enums;

/**
 * The owner's color theme preference, stored per user. `System` follows
 * the operating system's `prefers-color-scheme` and is the default.
 */
enum Appearance: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    /**
     * Resolve a client-supplied value (e.g. the guest cookie), falling back
     * to `System` for anything outside the allowlist.
     */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::System) : self::System;
    }
}
