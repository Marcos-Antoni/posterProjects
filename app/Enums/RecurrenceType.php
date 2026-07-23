<?php

namespace App\Enums;

/**
 * Which days a habit is expected to be performed: every day, a fixed set
 * of weekdays, or a weekly quota of any X days.
 */
enum RecurrenceType: string
{
    case Daily = 'daily';
    case SpecificWeekdays = 'specific_weekdays';
    case TimesPerWeek = 'times_per_week';
}
