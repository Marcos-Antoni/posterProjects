<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

test('project persists with expected attributes and default next issue number', function () {
    $project = Project::factory()->create(['key' => 'WEB', 'name' => 'Website Revamp']);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->owner_id)->not->toBeNull()
        ->and($project->key)->toBe('WEB')
        ->and($project->name)->toBe('Website Revamp')
        ->and($project->next_issue_number)->toBe(1);

    $this->assertModelExists($project);
});

test('project key must be unique', function () {
    Project::factory()->create(['key' => 'ENG']);

    expect(fn () => Project::factory()->create(['key' => 'ENG']))
        ->toThrow(QueryException::class);
});

test('project belongs to an owner via the users table', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();

    expect($project->owner)->toBeInstanceOf(User::class)
        ->and($project->owner->id)->toBe($owner->id);
});

test('allocate next issue number returns sequential integers starting at one', function () {
    $project = Project::factory()->create();

    expect($project->allocateNextIssueNumber())->toBe(1)
        ->and($project->allocateNextIssueNumber())->toBe(2)
        ->and($project->allocateNextIssueNumber())->toBe(3);

    $project->refresh();

    expect($project->next_issue_number)->toBe(4);
});

test('allocate next issue number never returns duplicate numbers across many calls', function () {
    $project = Project::factory()->create();

    $numbers = array_map(fn () => $project->allocateNextIssueNumber(), range(1, 20));

    expect($numbers)->toBe(range(1, 20))
        ->and(array_unique($numbers))->toHaveCount(20);
});
