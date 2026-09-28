<?php

namespace App\Actions\Support;

/**
 * The AI tier of an operation (ai-operations spec): `Minor` is applied
 * directly for an AI actor, `Major` becomes a proposal unless a grant covers
 * the target.
 */
enum AiTier: string
{
    case Minor = 'minor';
    case Major = 'major';
}
