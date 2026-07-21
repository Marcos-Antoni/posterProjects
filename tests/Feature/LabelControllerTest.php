<?php

use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login for the label management actions', function () {
    $project = Project::factory()->create();
    $label = Label::factory()->for($project)->create();

    $this->get("/projects/{$project->key}/labels")
        ->assertRedirect('/login');

    $this->patch("/projects/{$project->key}/labels/{$label->id}", ['name' => 'renamed'])
        ->assertRedirect('/login');

    $this->delete("/projects/{$project->key}/labels/{$label->id}")
        ->assertRedirect('/login');
});

test('the label management page renders as a full page with the built Vite assets', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/labels");

    $response->assertOk();
});

test('a member can view the label management list with issue counts', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $bug = Label::factory()->for($project)->create(['name' => 'bug']);
    $feature = Label::factory()->for($project)->create(['name' => 'feature']);
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $issue->labels()->attach($bug);

    $response = $this->actingAs($owner)->get("/projects/{$project->key}/labels", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'projects/labels');

    $labels = collect($response->json('props.labels'))->keyBy('name');
    expect($labels['bug']['issues_count'])->toBe(1)
        ->and($labels['feature']['issues_count'])->toBe(0);
});

test('a non-owner member can still view the label management list (read-only)', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($member)->get("/projects/{$project->key}/labels", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.labels'))->toHaveCount(1);
});

test('a non-member cannot view the label management list', function () {
    $project = Project::factory()->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->get("/projects/{$project->key}/labels");

    $response->assertForbidden();
});

test('the owner can rename a label', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/labels/{$label->id}", [
        'name' => 'defect',
    ]);

    $response->assertRedirect();
    expect($label->refresh()->name)->toBe('defect');
});

test('renaming a label to a name already used in the project fails with a friendly error', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    Label::factory()->for($project)->create(['name' => 'feature']);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/labels/{$label->id}", [
        'name' => 'feature',
    ]);

    $response->assertSessionHasErrors('name');
    expect($label->refresh()->name)->toBe('bug');
});

test('renaming a label to its own current name is allowed (unique rule ignores itself)', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/labels/{$label->id}", [
        'name' => 'bug',
    ]);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('name');
});

test('a non-owner cannot rename a label', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($member)->patch("/projects/{$project->key}/labels/{$label->id}", [
        'name' => 'defect',
    ]);

    $response->assertForbidden();
    expect($label->refresh()->name)->toBe('bug');
});

test('the owner can delete a label', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/labels/{$label->id}");

    $response->assertRedirect();
    expect(Label::find($label->id))->toBeNull();
});

test('deleting a label also removes its attachments from issues (DB cascade on issue_label.label_id)', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $label = Label::factory()->for($project)->create(['name' => 'bug']);
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $issue->labels()->attach($label);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/labels/{$label->id}");

    $response->assertRedirect();
    expect(Label::find($label->id))->toBeNull();
    expect(DB::table('issue_label')->where('label_id', $label->id)->exists())->toBeFalse();
    $this->assertModelExists($issue);
});

test('a non-owner cannot delete a label', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($member);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($member)->delete("/projects/{$project->key}/labels/{$label->id}");

    $response->assertForbidden();
    expect(Label::find($label->id))->not->toBeNull();
});

test('a label from another project resolves to a 404 for update and destroy', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
    $otherProject->members()->attach($owner);
    $otherLabel = Label::factory()->for($otherProject)->create();

    $this->actingAs($owner)->patch("/projects/{$project->key}/labels/{$otherLabel->id}", ['name' => 'x'])
        ->assertNotFound();

    $this->actingAs($owner)->delete("/projects/{$project->key}/labels/{$otherLabel->id}")
        ->assertNotFound();
});
