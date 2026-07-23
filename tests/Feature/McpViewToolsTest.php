<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Views\BacklogView;
use App\Mcp\Tools\Views\BoardView;
use App\Mcp\Tools\Views\CalendarView;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('board-view shows the project columns with their issues', function () {
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
        'title' => 'Fix the header',
    ]);

    $response = PosterServer::actingAs($owner)->tool(BoardView::class, [
        'project_key' => 'DEMO',
    ]);

    $response->assertOk()
        ->assertSee('To Do')
        ->assertSee('Fix the header')
        ->assertSee(route('projects.issues.show', ['project' => 'DEMO', 'issueKey' => $issue->key]));
});

test('board-view is denied for a non-member', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);
    $stranger = User::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(BoardView::class, [
        'project_key' => 'DEMO',
    ]);

    $response->assertHasErrors();
});

test('backlog-view shows sprints and the unassigned backlog', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create(['name' => 'Sprint 1']);
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
        'reporter_id' => $owner->id,
        'title' => 'Sprint issue',
        'story_points' => 5,
    ]);
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Backlog issue',
    ]);

    $response = PosterServer::actingAs($owner)->tool(BacklogView::class, [
        'project_key' => 'DEMO',
    ]);

    $response->assertOk()
        ->assertSee('Sprint 1')
        ->assertSee('Sprint issue')
        ->assertSee('Backlog issue');
});

test('backlog-view is denied for a non-member', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);
    $stranger = User::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(BacklogView::class, [
        'project_key' => 'DEMO',
    ]);

    $response->assertHasErrors();
});

test('calendar-view only shows issues with a due date from the user\'s own projects', function () {
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
        'title' => 'Has a due date',
        'due_date' => today()->startOfMonth()->addDays(5),
    ]);
    Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'No due date',
        'due_date' => null,
    ]);

    $otherProject = Project::createWithDefaultColumns([
        'owner_id' => User::factory()->create()->id,
        'key' => 'OTHER',
        'name' => 'Other',
        'description' => null,
    ]);
    [$otherColumn] = $otherProject->boardColumns;
    Issue::factory()->for($otherProject)->create([
        'board_column_id' => $otherColumn->id,
        'sprint_id' => null,
        'title' => 'Not my project',
        'due_date' => today()->startOfMonth()->addDays(5),
    ]);

    $response = PosterServer::actingAs($owner)->tool(CalendarView::class);

    $response->assertOk()
        ->assertSee('Has a due date')
        ->assertDontSee('No due date')
        ->assertDontSee('Not my project');
});
