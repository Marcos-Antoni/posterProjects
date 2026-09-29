<?php

namespace App\Enums;

/**
 * Who replaced an item's 2-minute version (`item_two_minute_history.source`).
 */
enum TwoMinuteSource: string
{
    case Owner = 'owner';
    case Ai = 'ai';
}
