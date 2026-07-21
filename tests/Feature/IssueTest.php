<?php

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

test('issue persists with expected attributes', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'title' => 'Fix login bug',
        'description' => 'Users cannot log in with SSO',
    ]);

    expect($issue)->toBeInstanceOf(Issue::class)
        ->and($issue->project_id)->toBe($project->id)
        ->and($issue->title)->toBe('Fix login bug')
        ->and($issue->description)->toBe('Users cannot log in with SSO')
        ->and($issue->number)->toBe(1);

    $this->assertModelExists($issue);
});

test('issue number is allocated sequentially per project via the factory', function () {
    $project = Project::factory()->create();

    $first = Issue::factory()->for($project)->create();
    $second = Issue::factory()->for($project)->create();
    $third = Issue::factory()->for($project)->create();

    expect([$first->number, $second->number, $third->number])->toBe([1, 2, 3]);
});

test('issue number must be unique per project', function () {
    $project = Project::factory()->create();
    Issue::factory()->for($project)->create(['number' => 1]);

    expect(fn () => Issue::factory()->for($project)->create(['number' => 1]))
        ->toThrow(QueryException::class);
});

test('the same issue number can be reused across different projects', function () {
    $projectOne = Project::factory()->create();
    $projectTwo = Project::factory()->create();

    $issueOne = Issue::factory()->for($projectOne)->create();
    $issueTwo = Issue::factory()->for($projectTwo)->create();

    expect($issueOne->number)->toBe(1)
        ->and($issueTwo->number)->toBe(1);
});

test('key accessor combines the project key and the issue number', function () {
    $project = Project::factory()->create(['key' => 'WEB']);
    $issue = Issue::factory()->for($project)->create();

    expect($issue->key)->toBe('WEB-1');
});

test('type and priority are cast to their backed enums', function () {
    $issue = Issue::factory()->create([
        'type' => IssueType::Bug,
        'priority' => IssuePriority::Highest,
    ]);

    expect($issue->type)->toBe(IssueType::Bug)
        ->and($issue->priority)->toBe(IssuePriority::Highest);

    $issue->refresh();

    expect($issue->type)->toBe(IssueType::Bug)
        ->and($issue->priority)->toBe(IssuePriority::Highest);
});

test('sprint_id, parent_id, story_points, due_date and assignee_id are nullable', function () {
    $issue = Issue::factory()->create([
        'sprint_id' => null,
        'parent_id' => null,
        'story_points' => null,
        'due_date' => null,
        'assignee_id' => null,
    ]);

    expect($issue->sprint_id)->toBeNull()
        ->and($issue->parent_id)->toBeNull()
        ->and($issue->story_points)->toBeNull()
        ->and($issue->due_date)->toBeNull()
        ->and($issue->assignee_id)->toBeNull();

    $this->assertModelExists($issue);
});

test('an issue can have a parent and the parent exposes its children structurally', function () {
    $project = Project::factory()->create();
    $parent = Issue::factory()->for($project)->create(['type' => IssueType::Epic]);
    $child = Issue::factory()->for($project)->create(['parent_id' => $parent->id, 'type' => IssueType::Story]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->children->first()->id)->toBe($child->id);
});

test('an issue has a reporter and an optional assignee', function () {
    $reporter = User::factory()->create();
    $assignee = User::factory()->create();

    $issue = Issue::factory()->create([
        'reporter_id' => $reporter->id,
        'assignee_id' => $assignee->id,
    ]);

    expect($issue->reporter->id)->toBe($reporter->id)
        ->and($issue->assignee->id)->toBe($assignee->id);
});

test('factory type states set the expected issue type', function (string $state, IssueType $expected) {
    $issue = Issue::factory()->{$state}()->create();

    expect($issue->type)->toBe($expected);
})->with([
    'epic' => ['epic', IssueType::Epic],
    'story' => ['story', IssueType::Story],
    'task' => ['task', IssueType::Task],
    'bug' => ['bug', IssueType::Bug],
]);
