<?php

use App\Enums\IssuePriority;

test('issue priority exposes exactly the five expected cases', function () {
    expect(IssuePriority::cases())->toHaveCount(5);
});

test('issue priority values increase from highest to lowest urgency', function () {
    expect(IssuePriority::Highest->value)->toBeLessThan(IssuePriority::High->value)
        ->and(IssuePriority::High->value)->toBeLessThan(IssuePriority::Medium->value)
        ->and(IssuePriority::Medium->value)->toBeLessThan(IssuePriority::Low->value)
        ->and(IssuePriority::Low->value)->toBeLessThan(IssuePriority::Lowest->value);
});

test('sorting priority values ascending yields highest-to-lowest order', function () {
    $shuffled = [
        IssuePriority::Lowest,
        IssuePriority::Medium,
        IssuePriority::Highest,
        IssuePriority::Low,
        IssuePriority::High,
    ];

    usort($shuffled, fn (IssuePriority $a, IssuePriority $b): int => $a->value <=> $b->value);

    expect(array_map(fn (IssuePriority $priority): string => $priority->name, $shuffled))
        ->toBe(['Highest', 'High', 'Medium', 'Low', 'Lowest']);
});
