<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('guests are redirected to login for every sprint action', function () {
    $project = Project::factory()->create();
    $sprint = Sprint::factory()->for($project)->create();

    $this->post("/projects/{$project->key}/sprints", [
        'name' => 'Sprint 1',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ])->assertRedirect('/login');

    $this->patch("/projects/{$project->key}/sprints/{$sprint->id}", [
        'name' => 'Sprint 1',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ])->assertRedirect('/login');

    $this->delete("/projects/{$project->key}/sprints/{$sprint->id}")
        ->assertRedirect('/login');
});

test('the owner can create a sprint', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/sprints", [
        'name' => 'Sprint 1',
        'goal' => 'Ship the MVP',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);

    $response->assertRedirect();

    $sprint = Sprint::where('project_id', $project->id)->where('name', 'Sprint 1')->firstOrFail();
    expect($sprint->goal)->toBe('Ship the MVP')
        ->and($sprint->start_date->toDateString())->toBe('2026-08-01')
        ->and($sprint->end_date->toDateString())->toBe('2026-08-14');
});

test('the goal is optional when creating a sprint', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/sprints", [
        'name' => 'Sprint 1',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);

    $response->assertRedirect();
    $sprint = Sprint::where('project_id', $project->id)->where('name', 'Sprint 1')->firstOrFail();
    expect($sprint->goal)->toBeNull();
});

test('a non-owner cannot create a sprint', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);

    $response = $this->actingAs($member)->post("/projects/{$project->key}/sprints", [
        'name' => 'Sprint 1',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);

    $response->assertForbidden();
    expect(Sprint::where('project_id', $project->id)->exists())->toBeFalse();
});

test('creating a sprint requires a name', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/sprints", [
        'name' => '',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);

    $response->assertSessionHasErrors('name');
});

test('creating a sprint requires start_date and end_date', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/sprints", [
        'name' => 'Sprint 1',
    ]);

    $response->assertSessionHasErrors(['start_date', 'end_date']);
});

test('the end date must not be before the start date', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/sprints", [
        'name' => 'Sprint 1',
        'start_date' => '2026-08-14',
        'end_date' => '2026-08-01',
    ]);

    $response->assertSessionHasErrors('end_date');
    expect(Sprint::where('project_id', $project->id)->exists())->toBeFalse();
});

test('the end date may equal the start date', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/sprints", [
        'name' => 'One-day sprint',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ]);

    $response->assertRedirect();
    expect(Sprint::where('project_id', $project->id)->where('name', 'One-day sprint')->exists())->toBeTrue();
});

test('the owner can update a sprint', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $sprint = Sprint::factory()->for($project)->create(['name' => 'Sprint 1']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/sprints/{$sprint->id}", [
        'name' => 'Sprint 1 renamed',
        'goal' => 'New goal',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-14',
    ]);

    $response->assertRedirect();
    $sprint->refresh();
    expect($sprint->name)->toBe('Sprint 1 renamed')
        ->and($sprint->goal)->toBe('New goal')
        ->and($sprint->start_date->toDateString())->toBe('2026-09-01')
        ->and($sprint->end_date->toDateString())->toBe('2026-09-14');
});

test('a non-owner cannot update a sprint', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    $sprint = Sprint::factory()->for($project)->create(['name' => 'Sprint 1']);

    $response = $this->actingAs($member)->patch("/projects/{$project->key}/sprints/{$sprint->id}", [
        'name' => 'Hacked',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);

    $response->assertForbidden();
    expect($sprint->refresh()->name)->toBe('Sprint 1');
});

test('updating a sprint validates the end date is not before the start date', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $sprint = Sprint::factory()->for($project)->create(['name' => 'Sprint 1']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/sprints/{$sprint->id}", [
        'name' => 'Sprint 1',
        'start_date' => '2026-08-14',
        'end_date' => '2026-08-01',
    ]);

    $response->assertSessionHasErrors('end_date');
});

test('the owner can delete an empty sprint', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $sprint = Sprint::factory()->for($project)->create();

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/sprints/{$sprint->id}");

    $response->assertRedirect();
    expect(Sprint::find($sprint->id))->toBeNull();
});

test('deleting a sprint with issues returns them to the backlog instead of deleting them', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();
    $issueOne = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $owner->id,
    ]);
    $issueTwo = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $owner->id,
    ]);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/sprints/{$sprint->id}");

    $response->assertRedirect();
    expect(Sprint::find($sprint->id))->toBeNull();

    $issueOne->refresh();
    $issueTwo->refresh();
    expect($issueOne->sprint_id)->toBeNull()
        ->and($issueTwo->sprint_id)->toBeNull();
    $this->assertModelExists($issueOne);
    $this->assertModelExists($issueTwo);
});

test('a non-owner cannot delete a sprint', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    $sprint = Sprint::factory()->for($project)->create();

    $response = $this->actingAs($member)->delete("/projects/{$project->key}/sprints/{$sprint->id}");

    $response->assertForbidden();
    expect(Sprint::find($sprint->id))->not->toBeNull();
});

test('a sprint from another project resolves to a 404 for update and destroy', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
    $otherProject->members()->attach($owner);
    $otherSprint = Sprint::factory()->for($otherProject)->create();

    $this->actingAs($owner)->patch("/projects/{$project->key}/sprints/{$otherSprint->id}", [
        'name' => 'X',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ])->assertNotFound();

    $this->actingAs($owner)->delete("/projects/{$project->key}/sprints/{$otherSprint->id}")
        ->assertNotFound();
});
