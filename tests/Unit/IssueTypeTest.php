<?php

use App\Enums\IssueType;

test('issue type exposes exactly the four expected cases', function () {
    expect(IssueType::cases())->toHaveCount(4);
});

test('issue type cases carry the expected string values', function () {
    expect(IssueType::Epic->value)->toBe('epic')
        ->and(IssueType::Story->value)->toBe('story')
        ->and(IssueType::Task->value)->toBe('task')
        ->and(IssueType::Bug->value)->toBe('bug');
});

test('issue type can be resolved back from its string value', function () {
    expect(IssueType::from('bug'))->toBe(IssueType::Bug)
        ->and(IssueType::from('epic'))->toBe(IssueType::Epic);
});
