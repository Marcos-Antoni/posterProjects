<?php

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Issues\CreateIssue;
use App\Mcp\Tools\Issues\MoveIssue;
use App\Mcp\Tools\Issues\ShowIssue;
use App\Mcp\Tools\Issues\UpdateIssue;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('create-issue quick-adds a Task at the bottom of the column', function () {
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
        'position' => 0,
    ]);

    $response = PosterServer::actingAs($owner)->tool(CreateIssue::class, [
        'project_key' => 'DEMO',
        'title' => 'Fix the header',
        'board_column_id' => $toDo->id,
    ]);

    $response->assertOk()
        ->assertSee('Fix the header')
        ->assertSee(route('projects.issues.show', ['project' => 'DEMO', 'issueKey' => 'DEMO-2']));

    $issue = Issue::where('title', 'Fix the header')->firstOrFail();

    expect($issue->type)->toBe(IssueType::Task)
        ->and($issue->priority)->toBe(IssuePriority::Medium)
        ->and($issue->reporter_id)->toBe($owner->id)
        ->and($issue->assignee_id)->toBeNull()
        ->and($issue->position)->toBe(1);
});

test('create-issue is denied for a non-member', function () {
    $project = Project::createWithDefaultColumns([
        'owner_id' => User::factory()->create()->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $stranger = User::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(CreateIssue::class, [
        'project_key' => 'DEMO',
        'title' => 'Should not be created',
        'board_column_id' => $toDo->id,
    ]);

    $response->assertHasErrors();
    expect(Issue::where('title', 'Should not be created')->exists())->toBeFalse();
});

test('update-issue changes only the fields sent', function () {
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
        'title' => 'Original title',
        'story_points' => 3,
    ]);

    $response = PosterServer::actingAs($owner)->tool(UpdateIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'title' => 'Updated title',
    ]);

    $response->assertOk()->assertSee('Updated title');

    $issue->refresh();
    expect($issue->title)->toBe('Updated title')
        ->and($issue->story_points)->toBe(3);
});

test('update-issue rejects an issue_key from another project (scoped lookup)', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $otherProject = Project::factory()->create(['key' => 'OTHER']);
    $otherIssue = Issue::factory()->for($otherProject)->create(['number' => 1]);

    $response = PosterServer::actingAs($owner)->tool(UpdateIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $otherIssue->key,
        'title' => 'Hijacked',
    ]);

    $response->assertHasErrors(["Issue not found: {$otherIssue->key}"]);
});

test('move-issue moves an issue across columns and resequences both scopes', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;

    $originFirst = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Origin First', 'position' => 0]);
    $moving = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Moving', 'position' => 1]);
    $originThird = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Origin Third', 'position' => 2]);

    $destFirst = Issue::factory()->for($project)->create(['board_column_id' => $inProgress->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Dest First', 'position' => 0]);
    $destSecond = Issue::factory()->for($project)->create(['board_column_id' => $inProgress->id, 'sprint_id' => null, 'reporter_id' => $owner->id, 'title' => 'Dest Second', 'position' => 1]);

    $response = PosterServer::actingAs($owner)->tool(MoveIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $moving->key,
        'board_column_id' => $inProgress->id,
        'position' => 1,
    ]);

    $response->assertOk();

    // Origin column resequenced (gap closed).
    expect($originFirst->refresh()->position)->toBe(0)
        ->and($originThird->refresh()->position)->toBe(1);

    // Destination column: moving issue inserted at position 1, siblings shifted around it.
    expect($destFirst->refresh()->position)->toBe(0)
        ->and($moving->refresh()->position)->toBe(1)
        ->and($moving->board_column_id)->toBe($inProgress->id)
        ->and($destSecond->refresh()->position)->toBe(2);
});

test('move-issue is denied for a non-member', function () {
    $project = Project::createWithDefaultColumns([
        'owner_id' => User::factory()->create()->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, $inProgress] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'position' => 0]);
    $stranger = User::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(MoveIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'board_column_id' => $inProgress->id,
        'position' => 0,
    ]);

    $response->assertHasErrors();
    expect($issue->refresh()->board_column_id)->toBe($toDo->id);
});

test('show-issue returns the full projection and its url', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create(['name' => 'Ada Lovelace']);
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($assignee->id);
    [$toDo] = $project->boardColumns;

    $issue = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'assignee_id' => $assignee->id,
        'title' => 'Fix the header',
    ]);
    Comment::factory()->for($issue)->for($owner, 'author')->create(['body' => 'Looks good']);

    $response = PosterServer::actingAs($owner)->tool(ShowIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
    ]);

    $response->assertOk()
        ->assertSee('Fix the header')
        ->assertSee('Ada Lovelace')
        ->assertSee('Looks good')
        ->assertSee(route('projects.issues.show', ['project' => 'DEMO', 'issueKey' => $issue->key]));
});

test('show-issue is denied for a non-member', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $stranger = User::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(ShowIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
    ]);

    $response->assertHasErrors();
});

test('show-issue rejects an issue_key from another project (scoped lookup)', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'DEMO']);
    $project->members()->attach($owner->id);

    $otherProject = Project::factory()->create(['key' => 'OTHER']);
    $otherIssue = Issue::factory()->for($otherProject)->create(['number' => 1]);

    $response = PosterServer::actingAs($owner)->tool(ShowIssue::class, [
        'project_key' => 'DEMO',
        'issue_key' => $otherIssue->key,
    ]);

    $response->assertHasErrors(["Issue not found: {$otherIssue->key}"]);
});
