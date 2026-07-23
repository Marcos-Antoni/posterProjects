<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Sprints\CreateSprint;
use App\Mcp\Tools\Sprints\DeleteSprint;
use App\Mcp\Tools\Sprints\UpdateSprint;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('create-sprint creates a sprint for the project', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach($owner->id);

    $response = PosterServer::actingAs($owner)->tool(CreateSprint::class, [
        'project_key' => 'DEMO',
        'name' => 'Sprint 1',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-14',
    ]);

    $response->assertOk()
        ->assertSee('Sprint 1')
        ->assertSee(route('projects.backlog', ['project' => 'DEMO']));

    $sprint = Sprint::where('project_id', $project->id)->firstOrFail();
    expect($sprint->name)->toBe('Sprint 1')
        ->and($sprint->start_date->toDateString())->toBe('2025-01-01')
        ->and($sprint->end_date->toDateString())->toBe('2025-01-14');
});

test('create-sprint is denied for a member who is not the owner', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($member->id);

    $response = PosterServer::actingAs($member)->tool(CreateSprint::class, [
        'project_key' => 'DEMO',
        'name' => 'Should not exist',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-14',
    ]);

    $response->assertHasErrors();
    expect(Sprint::where('name', 'Should not exist')->exists())->toBeFalse();
});

test('update-sprint renames and reschedules an existing sprint', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach($owner->id);
    $sprint = Sprint::factory()->for($project)->create(['name' => 'Old name']);

    $response = PosterServer::actingAs($owner)->tool(UpdateSprint::class, [
        'project_key' => 'DEMO',
        'sprint_id' => $sprint->id,
        'name' => 'New name',
        'start_date' => '2025-02-01',
        'end_date' => '2025-02-14',
    ]);

    $response->assertOk()->assertSee('New name');

    $sprint->refresh();
    expect($sprint->name)->toBe('New name')
        ->and($sprint->start_date->toDateString())->toBe('2025-02-01')
        ->and($sprint->end_date->toDateString())->toBe('2025-02-14');
});

test('delete-sprint removes it and returns its issues to the backlog', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $owner->id,
    ]);

    $response = PosterServer::actingAs($owner)->tool(DeleteSprint::class, [
        'project_key' => 'DEMO',
        'sprint_id' => $sprint->id,
    ]);

    $response->assertOk();

    expect(Sprint::find($sprint->id))->toBeNull()
        ->and($issue->refresh()->sprint_id)->toBeNull();
});

test('delete-sprint is denied for a member who is not the owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach([$owner->id, $member->id]);
    $sprint = Sprint::factory()->for($project)->create();

    $response = PosterServer::actingAs($member)->tool(DeleteSprint::class, [
        'project_key' => 'DEMO',
        'sprint_id' => $sprint->id,
    ]);

    $response->assertHasErrors();
    expect(Sprint::find($sprint->id))->not->toBeNull();
});

test('update-sprint rejects a sprint_id belonging to another project (scoped lookup)', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach($owner->id);

    $otherProject = Project::factory()->create(['key' => 'OTHER']);
    $otherSprint = Sprint::factory()->for($otherProject)->create();

    $response = PosterServer::actingAs($owner)->tool(UpdateSprint::class, [
        'project_key' => 'DEMO',
        'sprint_id' => $otherSprint->id,
        'name' => 'Hijacked',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-14',
    ]);

    $response->assertHasErrors(["Sprint not found: {$otherSprint->id}"]);
});
