<?php

use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

test('board column persists with expected attributes', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create([
        'name' => 'To Do',
        'position' => 0,
    ]);

    expect($column)->toBeInstanceOf(BoardColumn::class)
        ->and($column->project_id)->toBe($project->id)
        ->and($column->name)->toBe('To Do')
        ->and($column->position)->toBe(0);

    $this->assertModelExists($column);
});

test('board column position is unique per project', function () {
    $project = Project::factory()->create();
    BoardColumn::factory()->for($project)->create(['position' => 0]);

    expect(fn () => BoardColumn::factory()->for($project)->create(['position' => 0]))
        ->toThrow(QueryException::class);
});

test('the same position can be reused across different projects', function () {
    $projectOne = Project::factory()->create();
    $projectTwo = Project::factory()->create();

    BoardColumn::factory()->for($projectOne)->create(['position' => 0]);
    $columnTwo = BoardColumn::factory()->for($projectTwo)->create(['position' => 0]);

    $this->assertModelExists($columnTwo);
});

test('a project exposes its board columns ordered by position', function () {
    $project = Project::factory()->create();
    BoardColumn::factory()->for($project)->create(['name' => 'Done', 'position' => 2]);
    BoardColumn::factory()->for($project)->create(['name' => 'To Do', 'position' => 0]);
    BoardColumn::factory()->for($project)->create(['name' => 'In Progress', 'position' => 1]);

    expect($project->boardColumns->pluck('name')->all())
        ->toBe(['To Do', 'In Progress', 'Done']);
});

test('creating a project with default columns materializes To Do, In Progress and Done', function () {
    $owner = User::factory()->create();

    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'WEB',
        'name' => 'Website Revamp',
        'description' => 'Rebuild the marketing site',
    ]);

    expect($project)->toBeInstanceOf(Project::class);
    $this->assertModelExists($project);

    expect($project->boardColumns)->toHaveCount(3)
        ->and($project->boardColumns->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done'])
        ->and($project->boardColumns->pluck('position')->all())->toBe([0, 1, 2]);
});

test('creating a project with default columns auto-attaches the owner as a member', function () {
    $owner = User::factory()->create();

    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'API',
        'name' => 'API Platform',
        'description' => null,
    ]);

    expect($project->members)->toHaveCount(1)
        ->and($project->members->first()->id)->toBe($owner->id);
});
