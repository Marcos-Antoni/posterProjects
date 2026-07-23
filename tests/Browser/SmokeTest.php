<?php

use App\Models\Habit;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('the login page renders without javascript errors', function () {
    $page = visit('/login');

    $page->assertSee('Iniciar sesión')
        ->assertNoJavascriptErrors();
});

test('every key authenticated page renders without javascript errors', function () {
    $user = User::factory()->create();

    $project = Project::createWithDefaultColumns([
        'owner_id' => $user->id,
        'key' => 'SMOKE',
        'name' => 'Smoke project',
        'description' => 'Fixture project for the browser smoke test.',
    ]);
    [$toDo] = $project->boardColumns()->orderBy('position')->get();

    $sprint = Sprint::factory()->for($project)->create();
    Label::factory()->for($project)->create();

    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $user->id,
        'due_date' => today(),
    ]);

    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create();
    $habit->days()->create([
        'entry_date' => Habit::todayLocalDate(),
        'accumulated_amount' => 5,
        'completion_percent' => 25,
        'completed' => false,
    ]);

    $this->actingAs($user);

    $pages = visit([
        '/projects',
        "/projects/{$project->key}/board",
        "/projects/{$project->key}/backlog",
        '/calendar',
        '/habits',
        '/habits/manage',
        "/habits/{$habit->id}",
        '/settings/mcp-token',
    ]);

    $pages->assertNoJavascriptErrors();
});
