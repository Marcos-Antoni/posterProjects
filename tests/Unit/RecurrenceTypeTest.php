<?php

use App\Enums\RecurrenceType;

test('recurrence type exposes exactly the three expected cases', function () {
    expect(RecurrenceType::cases())->toHaveCount(3);
});

test('recurrence type cases carry the expected string values', function () {
    expect(RecurrenceType::Daily->value)->toBe('daily')
        ->and(RecurrenceType::SpecificWeekdays->value)->toBe('specific_weekdays')
        ->and(RecurrenceType::TimesPerWeek->value)->toBe('times_per_week');
});

test('recurrence type can be resolved back from its string value', function () {
    expect(RecurrenceType::from('daily'))->toBe(RecurrenceType::Daily)
        ->and(RecurrenceType::from('specific_weekdays'))->toBe(RecurrenceType::SpecificWeekdays)
        ->and(RecurrenceType::from('times_per_week'))->toBe(RecurrenceType::TimesPerWeek);
});
