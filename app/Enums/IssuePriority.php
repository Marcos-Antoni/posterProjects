<?php

namespace App\Enums;

/**
 * The urgency of an issue, backed by an integer ordered from Highest to
 * Lowest so `ORDER BY priority` sorts the most urgent issues first.
 */
enum IssuePriority: int
{
    case Highest = 1;
    case High = 2;
    case Medium = 3;
    case Low = 4;
    case Lowest = 5;
}
