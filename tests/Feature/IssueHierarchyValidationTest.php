<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('assigning a parent that already has a parent is rejected (one-level hierarchy)', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $epic = Issue::factory()->for($project)->epic()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $childOfEpic = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'parent_id' => $epic->id,
    ]);
    $topLevel = Issue::factory()->for($project)->task()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$topLevel->id}", [
        'parent_id' => $childOfEpic->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
    expect($topLevel->refresh()->parent_id)->toBeNull();
});

test('an issue that already has children cannot receive a parent', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $parentCandidate = Issue::factory()->for($project)->epic()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $issueWithChildren = Issue::factory()->for($project)->task()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'parent_id' => $issueWithChildren->id,
    ]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issueWithChildren->id}", [
        'parent_id' => $parentCandidate->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
    expect($issueWithChildren->refresh()->parent_id)->toBeNull();
});

test('an issue cannot be set as its own parent', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'parent_id' => $issue->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
});

test('a valid top-level parent is accepted', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $epic = Issue::factory()->for($project)->epic()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $task = Issue::factory()->for($project)->task()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$task->id}", [
        'parent_id' => $epic->id,
    ]);

    $response->assertRedirect();
    expect($task->refresh()->parent_id)->toBe($epic->id);
});

test('clearing the parent does not run hierarchy validation', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $epic = Issue::factory()->for($project)->epic()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $task = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'parent_id' => $epic->id,
    ]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$task->id}", [
        'parent_id' => null,
    ]);

    $response->assertRedirect();
    expect($task->refresh()->parent_id)->toBeNull();
});

test('a parent from another project is rejected', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $task = Issue::factory()->for($project)->task()->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $otherProject = Project::factory()->create();
    $otherEpic = Issue::factory()->for($otherProject)->epic()->create();

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$task->id}", [
        'parent_id' => $otherEpic->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
});
