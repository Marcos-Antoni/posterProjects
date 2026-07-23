<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Labels\AttachIssueLabel;
use App\Mcp\Tools\Labels\CreateLabel;
use App\Mcp\Tools\Labels\DeleteLabel;
use App\Mcp\Tools\Labels\DetachIssueLabel;
use App\Mcp\Tools\Labels\ListLabels;
use App\Mcp\Tools\Labels\RenameLabel;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;

test('list-labels returns every project label with its issue count and url', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($user->id);
    $label = Label::factory()->for($project)->create(['name' => 'Bug']);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $issue->labels()->attach($label->id);

    $response = PosterServer::actingAs($user)->tool(ListLabels::class, [
        'project_key' => 'DEMO',
    ]);

    $response->assertOk()
        ->assertSee('Bug')
        ->assertSee(route('projects.labels.index', ['project' => 'DEMO']));
});

test('create-label creates a label owned by the project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($user->id);

    $response = PosterServer::actingAs($user)->tool(CreateLabel::class, [
        'project_key' => 'DEMO',
        'name' => 'Bug',
    ]);

    $response->assertOk()->assertSee('Bug');

    $label = Label::where('project_id', $project->id)->firstOrFail();
    expect($label->name)->toBe('Bug');
});

test('rename-label lets the owner rename a label', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach($owner->id);
    $label = Label::factory()->for($project)->create(['name' => 'Old name']);

    $response = PosterServer::actingAs($owner)->tool(RenameLabel::class, [
        'project_key' => 'DEMO',
        'label_id' => $label->id,
        'name' => 'New name',
    ]);

    $response->assertOk()->assertSee('New name');

    expect($label->refresh()->name)->toBe('New name');
});

test('rename-label is denied for a member who is not the owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach([$owner->id, $member->id]);
    $label = Label::factory()->for($project)->create(['name' => 'Original']);

    $response = PosterServer::actingAs($member)->tool(RenameLabel::class, [
        'project_key' => 'DEMO',
        'label_id' => $label->id,
        'name' => 'Hijacked',
    ]);

    $response->assertHasErrors();
    expect($label->refresh()->name)->toBe('Original');
});

test('delete-label removes it and detaches it from every issue', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach($owner->id);
    $label = Label::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $issue->labels()->attach($label->id);

    $response = PosterServer::actingAs($owner)->tool(DeleteLabel::class, [
        'project_key' => 'DEMO',
        'label_id' => $label->id,
    ]);

    $response->assertOk();

    expect(Label::find($label->id))->toBeNull()
        ->and($issue->labels()->count())->toBe(0);
});

test('delete-label is denied for a member who is not the owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO', 'owner_id' => $owner->id]);
    $project->members()->attach([$owner->id, $member->id]);
    $label = Label::factory()->for($project)->create();

    $response = PosterServer::actingAs($member)->tool(DeleteLabel::class, [
        'project_key' => 'DEMO',
        'label_id' => $label->id,
    ]);

    $response->assertHasErrors();
    expect(Label::find($label->id))->not->toBeNull();
});

test('attach-issue-label is idempotent', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($user->id);
    $label = Label::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['number' => 1]);

    PosterServer::actingAs($user)->tool(AttachIssueLabel::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'label_id' => $label->id,
    ])->assertOk();

    PosterServer::actingAs($user)->tool(AttachIssueLabel::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'label_id' => $label->id,
    ])->assertOk();

    expect($issue->labels()->wherePivot('label_id', $label->id)->count())->toBe(1);
});

test('detach-issue-label removes the attachment', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($user->id);
    $label = Label::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $issue->labels()->attach($label->id);

    $response = PosterServer::actingAs($user)->tool(DetachIssueLabel::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'label_id' => $label->id,
    ]);

    $response->assertOk();
    expect($issue->labels()->count())->toBe(0);
});

test('detach-issue-label rejects a label that is not attached to the issue', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($user->id);
    $label = Label::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['number' => 1]);

    $response = PosterServer::actingAs($user)->tool(DetachIssueLabel::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'label_id' => $label->id,
    ]);

    $response->assertHasErrors(["Label not attached to issue: {$label->id}"]);
});
