<?php

use App\Models\Issue;
use App\Models\Label;
use Illuminate\Database\QueryException;

test('a label can be attached to an issue', function () {
    $issue = Issue::factory()->create();
    $label = Label::factory()->for($issue->project)->create();

    $issue->labels()->attach($label);

    expect($issue->labels)->toHaveCount(1)
        ->and($issue->labels->first()->id)->toBe($label->id);
});

test('an issue exposes all of its labels', function () {
    $issue = Issue::factory()->create();
    $labels = Label::factory()->for($issue->project)->count(3)->create();

    $issue->labels()->attach($labels);

    expect($issue->labels)->toHaveCount(3)
        ->and($issue->labels->pluck('id')->sort()->values()->all())
        ->toBe($labels->pluck('id')->sort()->values()->all());
});

test('a label exposes all issues it is attached to', function () {
    $label = Label::factory()->create();
    $issues = Issue::factory()->for($label->project)->count(2)->create();

    foreach ($issues as $issue) {
        $issue->labels()->attach($label);
    }

    expect($label->issues)->toHaveCount(2)
        ->and($label->issues->pluck('id')->sort()->values()->all())
        ->toBe($issues->pluck('id')->sort()->values()->all());
});

test('the same label cannot be attached to an issue twice', function () {
    $issue = Issue::factory()->create();
    $label = Label::factory()->for($issue->project)->create();

    $issue->labels()->attach($label);

    expect(fn () => $issue->labels()->attach($label))
        ->toThrow(QueryException::class);
});
