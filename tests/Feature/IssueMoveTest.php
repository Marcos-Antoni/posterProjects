<?php

use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('guests are redirected to login when moving an issue', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $column->id]);

    $response = $this->patch("/projects/{$project->key}/issues/{$issue->id}/move", [
        'board_column_id' => $column->id,
        'position' => 0,
    ]);

    $response->assertRedirect('/login');
});

test('a member can move an issue to a different column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;

    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'position' => 0,
    ]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/move", [
        'board_column_id' => $inProgress->id,
        'position' => 0,
    ]);

    $response->assertRedirect();

    $issue->refresh();
    expect($issue->board_column_id)->toBe($inProgress->id)
        ->and($issue->position)->toBe(0);
});

test('drag never changes the sprint_id of the moved issue', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();

    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $owner->id,
        'position' => 0,
    ]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/move", [
        'board_column_id' => $inProgress->id,
        'position' => 0,
    ]);

    expect($issue->refresh()->sprint_id)->toBe($sprint->id);
});

test('a member can reorder an issue within the same column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $first = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'First', 'position' => 0]);
    $second = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Second', 'position' => 1]);
    $third = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Third', 'position' => 2]);

    // Move "First" to the end.
    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$first->id}/move", [
        'board_column_id' => $toDo->id,
        'position' => 2,
    ]);

    $response->assertRedirect();

    $ordered = $toDo->issues()->orderBy('position')->pluck('title')->all();
    expect($ordered)->toBe(['Second', 'Third', 'First']);

    // Positions are sequential starting at 0.
    expect($second->refresh()->position)->toBe(0)
        ->and($third->refresh()->position)->toBe(1)
        ->and($first->refresh()->position)->toBe(2);
});

test('moving an issue to another column closes the gap left behind in the origin column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;

    $first = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'First', 'position' => 0]);
    $second = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Second', 'position' => 1]);
    $third = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Third', 'position' => 2]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$second->id}/move", [
        'board_column_id' => $inProgress->id,
        'position' => 0,
    ]);

    expect($first->refresh()->position)->toBe(0)
        ->and($third->refresh()->position)->toBe(1)
        ->and($second->refresh()->position)->toBe(0)
        ->and($second->refresh()->board_column_id)->toBe($inProgress->id);
});

test('moving an issue into a column inserts it between the existing issues at the target position', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;

    $moving = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Moving', 'position' => 0]);
    $destFirst = Issue::factory()->for($project)->create(['board_column_id' => $inProgress->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Dest First', 'position' => 0]);
    $destSecond = Issue::factory()->for($project)->create(['board_column_id' => $inProgress->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Dest Second', 'position' => 1]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$moving->id}/move", [
        'board_column_id' => $inProgress->id,
        'position' => 1,
    ]);

    $ordered = $inProgress->issues()->orderBy('position')->pluck('title')->all();
    expect($ordered)->toBe(['Dest First', 'Moving', 'Dest Second']);
});

test('reordering respects the (board_column_id, sprint_id) scope and does not disturb the other sprint sharing the column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();

    // Backlog issues in the same column.
    $backlogFirst = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Backlog First', 'position' => 0]);
    $backlogSecond = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Backlog Second', 'position' => 1]);

    // Sprint issues in the SAME column.
    $sprintFirst = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'title' => 'Sprint First', 'position' => 0]);
    $sprintSecond = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'title' => 'Sprint Second', 'position' => 1]);

    // Reorder the sprint-scoped issues only.
    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$sprintFirst->id}/move", [
        'board_column_id' => $toDo->id,
        'position' => 1,
    ]);

    // Sprint scope reordered as expected.
    expect($sprintSecond->refresh()->position)->toBe(0)
        ->and($sprintFirst->refresh()->position)->toBe(1);

    // Backlog scope untouched.
    expect($backlogFirst->refresh()->position)->toBe(0)
        ->and($backlogSecond->refresh()->position)->toBe(1);
});

test('a non-member cannot move an issue', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $column->id]);
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->patch("/projects/{$project->key}/issues/{$issue->id}/move", [
        'board_column_id' => $column->id,
        'position' => 0,
    ]);

    $response->assertForbidden();
});

test('rejects a target column that belongs to another project', function () {
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

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/move", [
        'board_column_id' => $otherColumn->id,
        'position' => 0,
    ]);

    $response->assertSessionHasErrors('board_column_id');
});

test('rejects an issue that belongs to another project with a 404', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $otherProject = Project::factory()->create();
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();
    $otherIssue = Issue::factory()->for($otherProject)->create(['board_column_id' => $otherColumn->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$otherIssue->id}/move", [
        'board_column_id' => $toDo->id,
        'position' => 0,
    ]);

    $response->assertNotFound();
});

test('rejects a negative position', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/move", [
        'board_column_id' => $toDo->id,
        'position' => -1,
    ]);

    $response->assertSessionHasErrors('position');
});
