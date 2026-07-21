<?php

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('guests are redirected to login when visiting the board', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);

    $response = $this->get("/projects/{$project->key}/board");

    $response->assertRedirect('/login');
});

test('the board renders as a full page with the built Vite assets', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board");

    $response->assertOk();
});

test('a member can view the board with its columns ordered by position', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'projects/board');
    $response->assertJsonPath('props.project.key', 'DEMO');
    $response->assertJsonPath('props.project.owner_id', $owner->id);

    $columns = $response->json('props.columns');

    expect($columns)->toHaveCount(3)
        ->and(collect($columns)->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done']);
});

test('board columns include issues with type, priority, points, labels and assignee', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($assignee);

    [$toDo] = $project->boardColumns;
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'type' => IssueType::Bug,
        'priority' => IssuePriority::Highest,
        'story_points' => 5,
        'assignee_id' => $assignee->id,
        'reporter_id' => $owner->id,
        'title' => 'Fix the thing',
    ]);
    $issue->labels()->attach($label);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();

    $columns = collect($response->json('props.columns'));
    $toDoColumn = $columns->firstWhere('name', 'To Do');

    expect($toDoColumn['issues'])->toHaveCount(1);

    $payload = $toDoColumn['issues'][0];

    expect($payload['key'])->toBe('DEMO-1')
        ->and($payload['title'])->toBe('Fix the thing')
        ->and($payload['type'])->toBe('bug')
        ->and($payload['priority'])->toBe(1)
        ->and($payload['story_points'])->toBe(5)
        ->and($payload['labels'])->toHaveCount(1)
        ->and($payload['labels'][0]['name'])->toBe('bug')
        ->and($payload['assignee']['id'])->toBe($assignee->id);
});

test('issues within a column are ordered by position', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Third', 'position' => 2]);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'First', 'position' => 0]);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Second', 'position' => 1]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $columns = collect($response->json('props.columns'));
    $toDoColumn = $columns->firstWhere('name', 'To Do');

    expect(collect($toDoColumn['issues'])->pluck('title')->all())->toBe(['First', 'Second', 'Third']);
});

test('the board filters issues by an explicit sprint query param, including backlog', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $sprint = Sprint::factory()->for($project)->create();

    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Backlog issue']);
    Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => $sprint->id, 'reporter_id' => $owner->id, 'title' => 'Sprint issue']);

    $backlogResponse = $this->actingAs($owner)->get("/projects/{$project->key}/board?sprint=", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);
    $backlogColumns = collect($backlogResponse->json('props.columns'));
    $backlogToDo = $backlogColumns->firstWhere('name', 'To Do');

    expect($backlogResponse->json('props.selectedSprintId'))->toBeNull()
        ->and(collect($backlogToDo['issues'])->pluck('title')->all())->toBe(['Backlog issue']);

    $sprintResponse = $this->actingAs($owner)->get("/projects/{$project->key}/board?sprint={$sprint->id}", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);
    $sprintColumns = collect($sprintResponse->json('props.columns'));
    $sprintToDo = $sprintColumns->firstWhere('name', 'To Do');

    expect($sprintResponse->json('props.selectedSprintId'))->toBe($sprint->id)
        ->and(collect($sprintToDo['issues'])->pluck('title')->all())->toBe(['Sprint issue']);
});

test('the board defaults to the sprint whose date range contains today', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $activeSprint = Sprint::factory()->for($project)->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    Sprint::factory()->for($project)->create([
        'start_date' => now()->subWeeks(2),
        'end_date' => now()->subWeek(),
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    expect($response->json('props.selectedSprintId'))->toBe($activeSprint->id)
        ->and($response->json('props.activeSprintId'))->toBe($activeSprint->id);
});

test('the board defaults to the backlog when no sprint is active', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    Sprint::factory()->for($project)->create([
        'start_date' => now()->subWeeks(2),
        'end_date' => now()->subWeek(),
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    expect($response->json('props.selectedSprintId'))->toBeNull()
        ->and($response->json('props.activeSprintId'))->toBeNull();
});

test('a non-member cannot view the board', function () {
    $project = Project::factory()->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->get("/projects/{$project->key}/board");

    $response->assertForbidden();
});

test('an archived project board returns 404', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id]);
    $project->members()->attach($owner);
    $project->delete();

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/board");

    $response->assertNotFound();
});
