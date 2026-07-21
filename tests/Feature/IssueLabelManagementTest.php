<?php

use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login when creating a label', function () {
    $project = Project::factory()->create();

    $response = $this->post("/projects/{$project->key}/labels", ['name' => 'bug']);

    $response->assertRedirect('/login');
});

test('a member can create a project label', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/labels", ['name' => 'bug']);

    $response->assertRedirect();
    expect(Label::where('project_id', $project->id)->where('name', 'bug')->exists())->toBeTrue();
});

test('creating a duplicate label name in the same project fails with a friendly Spanish error, not a SQL exception', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/labels", ['name' => 'bug']);

    $response->assertSessionHasErrors('name');
    expect(Label::where('project_id', $project->id)->where('name', 'bug')->count())->toBe(1);
});

test('a non-member cannot create a label', function () {
    $project = Project::factory()->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->post("/projects/{$project->key}/labels", ['name' => 'bug']);

    $response->assertForbidden();
    expect(Label::where('project_id', $project->id)->exists())->toBeFalse();
});

test('a member can attach an existing label to an issue', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues/{$issue->id}/labels", [
        'label_id' => $label->id,
    ]);

    $response->assertRedirect();
    expect($issue->labels()->whereKey($label->id)->exists())->toBeTrue();
});

test('attaching the same label twice is idempotent and does not error', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);
    $issue->labels()->attach($label);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues/{$issue->id}/labels", [
        'label_id' => $label->id,
    ]);

    $response->assertRedirect();
    expect($issue->labels()->whereKey($label->id)->count())->toBe(1);
});

test('attaching a label from another project is rejected', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $otherProject = Project::factory()->create();
    $otherLabel = Label::factory()->for($otherProject)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues/{$issue->id}/labels", [
        'label_id' => $otherLabel->id,
    ]);

    $response->assertSessionHasErrors('label_id');
    expect($issue->labels()->exists())->toBeFalse();
});

test('a non-member cannot attach a label', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $label = Label::factory()->for($project)->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->post("/projects/{$project->key}/issues/{$issue->id}/labels", [
        'label_id' => $label->id,
    ]);

    $response->assertForbidden();
    expect($issue->labels()->exists())->toBeFalse();
});

test('a member can detach a label from an issue', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);
    $issue->labels()->attach($label);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/issues/{$issue->id}/labels/{$label->id}");

    $response->assertRedirect();
    expect($issue->labels()->whereKey($label->id)->exists())->toBeFalse();
});

test('a non-member cannot detach a label', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $label = Label::factory()->for($project)->create();
    $issue->labels()->attach($label);
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->delete("/projects/{$project->key}/issues/{$issue->id}/labels/{$label->id}");

    $response->assertForbidden();
    expect($issue->labels()->whereKey($label->id)->exists())->toBeTrue();
});

test('a label not attached to another issue 404s via scoped bindings', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $otherIssue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $label = Label::factory()->for($project)->create(['name' => 'bug']);
    $otherIssue->labels()->attach($label);

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/issues/{$issue->id}/labels/{$label->id}");

    $response->assertNotFound();
});
