<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login when visiting the calendar', function () {
    $response = $this->get('/calendar');

    $response->assertRedirect('/login');
});

test('the calendar renders as a full page with the built Vite assets', function () {
    $owner = User::factory()->create();

    $response = $this->actingAs($owner)->get('/calendar');

    $response->assertOk();
});

test('a member sees issues due this month from their own projects, with the project key to build links', function () {
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
        'title' => 'Ship the thing',
        'due_date' => today()->startOfMonth()->addDays(5),
    ]);

    $response = $this->actingAs($owner)->get('/calendar', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'calendar');

    $issues = $response->json('props.issues');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['id'])->toBe($issue->id)
        ->and($issues[0]['title'])->toBe('Ship the thing')
        ->and($issues[0]['due_date'])->toBe(today()->startOfMonth()->addDays(5)->toDateString())
        ->and($issues[0]['project']['key'])->toBe('DEMO');
});

test('issues without a due date are excluded', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'due_date' => null,
    ]);

    $response = $this->actingAs($owner)->get('/calendar', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    expect($response->json('props.issues'))->toHaveCount(0);
});

test('issues from a project the user is not a member of are excluded', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'due_date' => today()->startOfMonth()->addDays(5),
    ]);

    $response = $this->actingAs($stranger)->get('/calendar', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.issues'))->toHaveCount(0);
});

test('issues from an archived (soft-deleted) project are excluded even for a former member', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'due_date' => today()->startOfMonth()->addDays(5),
    ]);
    $project->delete();

    $response = $this->actingAs($owner)->get('/calendar', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.issues'))->toHaveCount(0);
});

test('only issues due within the requested month are returned, excluding the month before and after', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $startOfMonth = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Start of month',
        'due_date' => today()->startOfMonth(),
    ]);
    $endOfMonth = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'End of month',
        'due_date' => today()->endOfMonth(),
    ]);
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Next month',
        'due_date' => today()->addMonthNoOverflow()->startOfMonth(),
    ]);
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Previous month',
        'due_date' => today()->subMonthNoOverflow()->endOfMonth(),
    ]);

    $response = $this->actingAs($owner)->get('/calendar', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $titles = collect($response->json('props.issues'))->pluck('title')->all();
    expect($titles)->toBe(['Start of month', 'End of month']);
});

test('the response defaults to the current month when no month param is given', function () {
    $owner = User::factory()->create();
    Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get('/calendar', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    expect($response->json('props.month'))->toBe(today()->format('Y-m'));
});

test('an explicit ?month= query param navigates to a different month', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $nextMonth = today()->addMonthNoOverflow()->startOfMonth();
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Due next month',
        'due_date' => $nextMonth->copy()->addDays(2),
    ]);
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Due this month',
        'due_date' => today()->startOfMonth(),
    ]);

    $response = $this->actingAs($owner)->get('/calendar?month='.$nextMonth->format('Y-m'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.month'))->toBe($nextMonth->format('Y-m'));

    $titles = collect($response->json('props.issues'))->pluck('title')->all();
    expect($titles)->toBe(['Due next month']);
});

test('a malformed month query param falls back to the current month instead of erroring', function () {
    $owner = User::factory()->create();
    Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get('/calendar?month=not-a-month', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.month'))->toBe(today()->format('Y-m'));
});
