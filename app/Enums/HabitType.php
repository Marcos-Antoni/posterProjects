<?php

namespace App\Enums;

/**
 * How a habit's daily completion is measured: a simple yes/no check or a
 * quantitative amount against a daily target (e.g. "20 pages").
 */
enum HabitType: string
{
    case YesNo = 'yes_no';
    case Quantitative = 'quantitative';
}
