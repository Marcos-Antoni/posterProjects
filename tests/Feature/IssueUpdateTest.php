<?php

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('guests are redirected to login when updating an issue', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $column->id]);

    $response = $this->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'title' => 'New title',
    ]);

    $response->assertRedirect('/login');
});

test('a member can update an issue title', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Old title',
    ]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'title' => 'New title',
    ]);

    $response->assertRedirect();
    expect($issue->refresh()->title)->toBe('New title');
});

test('a member can update the description', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'description' => 'Updated description',
    ]);

    expect($issue->refresh()->description)->toBe('Updated description');
});

test('a member can update the type and priority', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'priority' => IssuePriority::Medium,
    ]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'type' => 'bug',
        'priority' => 1,
    ]);

    $issue->refresh();
    expect($issue->type)->toBe(IssueType::Bug)
        ->and($issue->priority)->toBe(IssuePriority::Highest);
});

test('a member can update story points and due date', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'story_points' => 8,
        'due_date' => '2026-08-01',
    ]);

    $issue->refresh();
    expect($issue->story_points)->toBe(8)
        ->and($issue->due_date->toDateString())->toBe('2026-08-01');
});

test('a member can assign the issue to another project member', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($teammate->id);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'assignee_id' => $teammate->id,
    ]);

    expect($issue->refresh()->assignee_id)->toBe($teammate->id);
});

test('assigning to a user who is not a project member is rejected', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'assignee_id' => $stranger->id,
    ]);

    $response->assertSessionHasErrors('assignee_id');
    expect($issue->refresh()->assignee_id)->toBeNull();
});

test('a member can move the issue to another sprint of the same project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'sprint_id' => $sprint->id,
    ]);

    expect($issue->refresh()->sprint_id)->toBe($sprint->id);
});

test('changing only the sprint appends the issue to the bottom of its new scope', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'position' => 0]);
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'position' => 0]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'sprint_id' => $sprint->id,
    ]);

    $issue->refresh();
    expect($issue->sprint_id)->toBe($sprint->id)
        ->and($issue->position)->toBe(1);
});

test('a sprint from another project is rejected', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $otherProject = Project::factory()->create();
    $otherSprint = Sprint::factory()->for($otherProject)->create();

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'sprint_id' => $otherSprint->id,
    ]);

    $response->assertSessionHasErrors('sprint_id');
});

test('changing the board column appends the issue to the bottom of its new scope', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;
    Issue::factory()->for($project)->create(['board_column_id' => $inProgress->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'position' => 0]);
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'position' => 0]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'board_column_id' => $inProgress->id,
    ]);

    $issue->refresh();
    expect($issue->board_column_id)->toBe($inProgress->id)
        ->and($issue->position)->toBe(1);
});

test('a board column from another project is rejected', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $otherProject = Project::factory()->create();
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'board_column_id' => $otherColumn->id,
    ]);

    $response->assertSessionHasErrors('board_column_id');
});

test('a non-member cannot update an issue', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $column->id]);
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'title' => 'Hijacked',
    ]);

    $response->assertForbidden();
    expect($issue->refresh()->title)->not->toBe('Hijacked');
});

test('an issue from another project 404s via scoped bindings', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $otherProject = Project::factory()->create();
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();
    $otherIssue = Issue::factory()->for($otherProject)->create(['board_column_id' => $otherColumn->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$otherIssue->id}", [
        'title' => 'Hijacked',
    ]);

    $response->assertNotFound();
});
