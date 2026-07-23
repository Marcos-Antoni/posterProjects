<?php

use App\Enums\HabitType;

test('habit type exposes exactly the two expected cases', function () {
    expect(HabitType::cases())->toHaveCount(2);
});

test('habit type cases carry the expected string values', function () {
    expect(HabitType::YesNo->value)->toBe('yes_no')
        ->and(HabitType::Quantitative->value)->toBe('quantitative');
});

test('habit type can be resolved back from its string value', function () {
    expect(HabitType::from('yes_no'))->toBe(HabitType::YesNo)
        ->and(HabitType::from('quantitative'))->toBe(HabitType::Quantitative);
});
