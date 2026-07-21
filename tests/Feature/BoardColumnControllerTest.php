<?php

use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login for every board column action', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();

    $this->post("/projects/{$project->key}/board-columns", ['name' => 'Review'])
        ->assertRedirect('/login');

    $this->patch("/projects/{$project->key}/board-columns/{$column->id}", ['name' => 'Review'])
        ->assertRedirect('/login');

    $this->patch("/projects/{$project->key}/board-columns/{$column->id}/reorder", ['position' => 0])
        ->assertRedirect('/login');

    $this->delete("/projects/{$project->key}/board-columns/{$column->id}")
        ->assertRedirect('/login');
});

test('the owner can add a column at the end of the board', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/board-columns", [
        'name' => 'Review',
    ]);

    $response->assertRedirect();

    $column = BoardColumn::where('name', 'Review')->firstOrFail();
    expect($column->project_id)->toBe($project->id)
        ->and($column->position)->toBe(3);
});

test('a non-owner cannot add a column', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);

    $response = $this->actingAs($member)->post("/projects/{$project->key}/board-columns", [
        'name' => 'Review',
    ]);

    $response->assertForbidden();
    expect(BoardColumn::where('name', 'Review')->exists())->toBeFalse();
});

test('adding a column requires a name', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/board-columns", [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('the owner can rename a column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/board-columns/{$toDo->id}", [
        'name' => 'Backlog',
    ]);

    $response->assertRedirect();
    expect($toDo->refresh()->name)->toBe('Backlog');
});

test('a non-owner cannot rename a column', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    [$toDo] = $project->boardColumns;

    $response = $this->actingAs($member)->patch("/projects/{$project->key}/board-columns/{$toDo->id}", [
        'name' => 'Backlog',
    ]);

    $response->assertForbidden();
    expect($toDo->refresh()->name)->toBe('To Do');
});

test('the owner can reorder columns without violating the unique position constraint', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress, $done] = $project->boardColumns;

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/board-columns/{$toDo->id}/reorder", [
        'position' => 2,
    ]);

    $response->assertRedirect();

    $ordered = $project->boardColumns()->orderBy('position')->pluck('name')->all();
    expect($ordered)->toBe(['In Progress', 'Done', 'To Do']);

    expect($inProgress->refresh()->position)->toBe(0)
        ->and($done->refresh()->position)->toBe(1)
        ->and($toDo->refresh()->position)->toBe(2);

    $positions = $project->boardColumns()->pluck('position');
    expect($positions->unique()->count())->toBe($positions->count());
});

test('a non-owner cannot reorder columns', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    [$toDo] = $project->boardColumns;

    $response = $this->actingAs($member)->patch("/projects/{$project->key}/board-columns/{$toDo->id}/reorder", [
        'position' => 2,
    ]);

    $response->assertForbidden();
});

test('the owner can delete an empty column without a destination', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, , $done] = $project->boardColumns;

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/board-columns/{$done->id}");

    $response->assertRedirect();
    expect(BoardColumn::find($done->id))->toBeNull();

    // The remaining columns are reindexed with no gaps and no duplicates.
    $positions = $project->boardColumns()->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe([0, 1]);
});

test('deleting a column with issues requires a destination column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/board-columns/{$toDo->id}");

    $response->assertSessionHasErrors('destination_board_column_id');
    expect(BoardColumn::find($toDo->id))->not->toBeNull();
});

test('deleting a column with issues moves them to the destination column and deletes the column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;

    $existingInDestination = Issue::factory()->for($project)->create([
        'board_column_id' => $inProgress->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'position' => 0,
    ]);
    $movingIssue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'position' => 0,
    ]);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/board-columns/{$toDo->id}", [
        'destination_board_column_id' => $inProgress->id,
    ]);

    $response->assertRedirect();
    expect(BoardColumn::find($toDo->id))->toBeNull();

    $movingIssue->refresh();
    expect($movingIssue->board_column_id)->toBe($inProgress->id)
        ->and($movingIssue->position)->toBe(1);

    expect($existingInDestination->refresh()->position)->toBe(0);
});

test('the destination column must belong to the same project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $otherProject = Project::factory()->create();
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/board-columns/{$toDo->id}", [
        'destination_board_column_id' => $otherColumn->id,
    ]);

    $response->assertSessionHasErrors('destination_board_column_id');
});

test('the destination column cannot be the column being deleted', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/board-columns/{$toDo->id}", [
        'destination_board_column_id' => $toDo->id,
    ]);

    $response->assertSessionHasErrors('destination_board_column_id');
});

test('a non-owner cannot delete a column', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    [$toDo] = $project->boardColumns;

    $response = $this->actingAs($member)->delete("/projects/{$project->key}/board-columns/{$toDo->id}");

    $response->assertForbidden();
    expect(BoardColumn::find($toDo->id))->not->toBeNull();
});

test('a column from another project resolves to a 404 for update, reorder, and destroy', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
    $otherProject->members()->attach($owner);
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();

    $this->actingAs($owner)->patch("/projects/{$project->key}/board-columns/{$otherColumn->id}", ['name' => 'X'])
        ->assertNotFound();

    $this->actingAs($owner)->patch("/projects/{$project->key}/board-columns/{$otherColumn->id}/reorder", ['position' => 0])
        ->assertNotFound();

    $this->actingAs($owner)->delete("/projects/{$project->key}/board-columns/{$otherColumn->id}")
        ->assertNotFound();
});
