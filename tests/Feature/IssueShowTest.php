<?php

use App\Enums\IssuePriority;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login when opening the issue deep link', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);

    $response = $this->get("/projects/{$project->key}/issues/DEMO-{$issue->number}");

    $response->assertRedirect('/login');
});

test('a member can open an issue directly (F5-safe deep link) and gets the board behind it', function () {
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

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/issues/{$issue->key}", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'projects/board');
    $response->assertJsonPath('props.project.key', 'DEMO');
    $response->assertJsonPath('props.issue.key', $issue->key);
    $response->assertJsonPath('props.issue.title', 'Fix the header');

    // The board behind the modal is the same board data as BoardController::show.
    expect($response->json('props.columns'))->toHaveCount(3);
});

test('the issue payload includes labels, assignee, reporter, parent, children and comments', function () {
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
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $parent = Issue::factory()->for($project)->epic()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'title' => 'Parent epic',
    ]);

    $issue = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'assignee_id' => $assignee->id,
        'parent_id' => $parent->id,
        'title' => 'Fix the header',
        'priority' => IssuePriority::High,
        'story_points' => 3,
    ]);
    $issue->labels()->attach($label);

    $child = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'parent_id' => $issue->id,
        'title' => 'Sub-task of Fix the header',
    ]);

    $comment = Comment::factory()->for($issue)->for($owner, 'author')->create(['body' => 'Looks good']);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/issues/{$issue->key}", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $issuePayload = $response->json('props.issue');

    expect($issuePayload['title'])->toBe('Fix the header')
        ->and($issuePayload['type'])->toBe('task')
        ->and($issuePayload['priority'])->toBe(2)
        ->and($issuePayload['story_points'])->toBe(3)
        ->and($issuePayload['labels'])->toHaveCount(1)
        ->and($issuePayload['labels'][0]['name'])->toBe('bug')
        ->and($issuePayload['assignee']['name'])->toBe('Ada Lovelace')
        ->and($issuePayload['reporter']['id'])->toBe($owner->id)
        ->and($issuePayload['parent']['key'])->toBe($parent->key)
        ->and($issuePayload['parent']['title'])->toBe('Parent epic')
        ->and($issuePayload['children'])->toHaveCount(1)
        ->and($issuePayload['children'][0]['key'])->toBe($child->key)
        ->and($issuePayload['comments'])->toHaveCount(1)
        ->and($issuePayload['comments'][0]['body'])->toBe('Looks good')
        ->and($issuePayload['comments'][0]['author']['id'])->toBe($owner->id);
});

test('an issue key with no dash returns 404 instead of erroring', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'DEMO']);
    $project->members()->attach($owner);

    $response = $this->actingAs($owner)->get('/projects/DEMO/issues/NoDashHere');

    $response->assertNotFound();
});

test('an issue key with a non-numeric suffix returns 404', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'DEMO']);
    $project->members()->attach($owner);

    $response = $this->actingAs($owner)->get('/projects/DEMO/issues/DEMO-abc');

    $response->assertNotFound();
});

test('an issue number that does not exist returns 404', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'DEMO']);
    $project->members()->attach($owner);

    $response = $this->actingAs($owner)->get('/projects/DEMO/issues/DEMO-999');

    $response->assertNotFound();
});

test('an issue key whose prefix does not match the URL project key returns 404', function () {
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
        'reporter_id' => $owner->id,
        'number' => 7,
    ]);

    // Real project, real issue number — wrong prefix in the URL.
    $response = $this->actingAs($owner)->get("/projects/{$project->key}/issues/OTHER-{$issue->number}");

    $response->assertNotFound();
});

test('a non-member cannot open an issue deep link', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->get("/projects/{$project->key}/issues/DEMO-{$issue->number}");

    $response->assertForbidden();
});

test('an archived project issue deep link returns 404', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->id, 'key' => 'DEMO']);
    $project->members()->attach($owner);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $project->delete();

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/issues/DEMO-{$issue->number}");

    $response->assertNotFound();
});
