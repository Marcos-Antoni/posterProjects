<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('guests are redirected to login when visiting the backlog', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);

    $response = $this->get('/projects/DEMO/backlog');

    $response->assertRedirect('/login');
});

test('the backlog renders as a full page with the built Vite assets', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/backlog");

    $response->assertOk();
});

test('a member can view the backlog with the project header', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/backlog", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'projects/backlog');
    $response->assertJsonPath('props.project.key', 'DEMO');
    $response->assertJsonPath('props.project.owner_id', $owner->id);
});

test('sprints are returned with their goal, date range, and story point sum', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create([
        'name' => 'Sprint 1',
        'goal' => 'Ship the MVP',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-14',
    ]);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'story_points' => 3]);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'story_points' => 5]);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'story_points' => null]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/backlog", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $sprints = $response->json('props.sprints');
    expect($sprints)->toHaveCount(1)
        ->and($sprints[0]['name'])->toBe('Sprint 1')
        ->and($sprints[0]['goal'])->toBe('Ship the MVP')
        ->and($sprints[0]['start_date'])->toBe('2026-08-01')
        ->and($sprints[0]['end_date'])->toBe('2026-08-14')
        ->and($sprints[0]['story_points_sum'])->toBe(8)
        ->and($sprints[0]['issues'])->toHaveCount(3);
});

test('issues without a sprint appear in the backlog section, not under any sprint', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'title' => 'Sprint issue']);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Backlog issue']);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/backlog", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $backlogIssues = $response->json('props.backlogIssues');
    expect($backlogIssues)->toHaveCount(1)
        ->and($backlogIssues[0]['title'])->toBe('Backlog issue');

    $sprints = collect($response->json('props.sprints'));
    $sprintIssueTitles = $sprints->flatMap(fn (array $sprint) => collect($sprint['issues'])->pluck('title'));
    expect($sprintIssueTitles->all())->toBe(['Sprint issue']);
});

test('a non-member cannot view the backlog', function () {
    $project = Project::factory()->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->get("/projects/{$project->key}/backlog");

    $response->assertForbidden();
});

test('an archived project backlog returns 404', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach($owner);
    $project->delete();

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/backlog");

    $response->assertNotFound();
});
