<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\BoardColumns\CreateBoardColumn;
use App\Mcp\Tools\BoardColumns\DeleteBoardColumn;
use App\Mcp\Tools\BoardColumns\ReorderBoardColumn;
use App\Mcp\Tools\BoardColumns\UpdateBoardColumn;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('create-board-column adds a column at the end of the board', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = PosterServer::actingAs($owner)->tool(CreateBoardColumn::class, [
        'project_key' => 'DEMO',
        'name' => 'Review',
    ]);

    $response->assertOk()
        ->assertSee('Review')
        ->assertSee(route('projects.board', ['project' => 'DEMO']));

    $column = BoardColumn::where('name', 'Review')->firstOrFail();
    expect($column->project_id)->toBe($project->id)
        ->and($column->position)->toBe(3);
});

test('create-board-column is denied for a non-owner member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);

    $response = PosterServer::actingAs($member)->tool(CreateBoardColumn::class, [
        'project_key' => 'DEMO',
        'name' => 'Review',
    ]);

    $response->assertHasErrors();
    expect(BoardColumn::where('name', 'Review')->exists())->toBeFalse();
});

test('update-board-column renames a column', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $response = PosterServer::actingAs($owner)->tool(UpdateBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
        'name' => 'Backlog',
    ]);

    $response->assertOk()->assertSee('Backlog');
    expect($toDo->refresh()->name)->toBe('Backlog');
});

test('update-board-column is denied for a non-owner member', function () {
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

    $response = PosterServer::actingAs($member)->tool(UpdateBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
        'name' => 'Backlog',
    ]);

    $response->assertHasErrors();
    expect($toDo->refresh()->name)->toBe('To Do');
});

test('update-board-column rejects a column belonging to another project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();

    $response = PosterServer::actingAs($owner)->tool(UpdateBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $otherColumn->id,
        'name' => 'Hijacked',
    ]);

    $response->assertHasErrors(["Board column not found: {$otherColumn->id}"]);
    expect($project->boardColumns()->count())->toBe(3);
});

test('reorder-board-column re-sequences every column on the board', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress, $done] = $project->boardColumns;

    $response = PosterServer::actingAs($owner)->tool(ReorderBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
        'position' => 2,
    ]);

    $response->assertOk();

    $ordered = $project->boardColumns()->orderBy('position')->pluck('name')->all();
    expect($ordered)->toBe(['In Progress', 'Done', 'To Do']);

    expect($inProgress->refresh()->position)->toBe(0)
        ->and($done->refresh()->position)->toBe(1)
        ->and($toDo->refresh()->position)->toBe(2);

    $positions = $project->boardColumns()->pluck('position');
    expect($positions->unique()->count())->toBe($positions->count());
});

test('reorder-board-column is denied for a non-owner member', function () {
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

    $response = PosterServer::actingAs($member)->tool(ReorderBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
        'position' => 2,
    ]);

    $response->assertHasErrors();
    expect($toDo->refresh()->position)->toBe(0);
});

test('delete-board-column removes an empty column and reindexes the rest', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [, , $done] = $project->boardColumns;

    $response = PosterServer::actingAs($owner)->tool(DeleteBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $done->id,
    ]);

    $response->assertOk();
    expect(BoardColumn::find($done->id))->toBeNull();

    $positions = $project->boardColumns()->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe([0, 1]);
});

test('delete-board-column moves issues to the destination column and reindexes positions', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress, $done] = $project->boardColumns;

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

    $response = PosterServer::actingAs($owner)->tool(DeleteBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
        'destination_board_column_id' => $inProgress->id,
    ]);

    $response->assertOk();
    expect(BoardColumn::find($toDo->id))->toBeNull();

    $movingIssue->refresh();
    expect($movingIssue->board_column_id)->toBe($inProgress->id)
        ->and($movingIssue->position)->toBe(1);

    expect($existingInDestination->refresh()->position)->toBe(0);

    $remaining = $project->boardColumns()->orderBy('position')->pluck('position')->all();
    expect($remaining)->toBe([0, 1]);
});

test('delete-board-column with issues and no destination fails validation', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = PosterServer::actingAs($owner)->tool(DeleteBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
    ]);

    $response->assertHasErrors();
    expect(BoardColumn::find($toDo->id))->not->toBeNull();
});

test('delete-board-column is denied for a non-owner member', function () {
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

    $response = PosterServer::actingAs($member)->tool(DeleteBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $toDo->id,
    ]);

    $response->assertHasErrors();
    expect(BoardColumn::find($toDo->id))->not->toBeNull();
});

test('delete-board-column rejects a column belonging to another project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();

    $response = PosterServer::actingAs($owner)->tool(DeleteBoardColumn::class, [
        'project_key' => 'DEMO',
        'board_column_id' => $otherColumn->id,
    ]);

    $response->assertHasErrors(["Board column not found: {$otherColumn->id}"]);
    expect(BoardColumn::find($otherColumn->id))->not->toBeNull();
});
