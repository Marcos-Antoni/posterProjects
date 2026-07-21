<?php

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

test('guests are redirected to login when quick-adding an issue', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();

    $response = $this->post("/projects/{$project->key}/issues", [
        'title' => 'Fix the header',
        'board_column_id' => $column->id,
        'sprint_id' => null,
    ]);

    $response->assertRedirect('/login');
});

test('a member can quick-add an issue to a column as a Task with Medium priority', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Fix the header',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
    ]);

    $response->assertRedirect();

    $issue = Issue::where('title', 'Fix the header')->firstOrFail();

    expect($issue->project_id)->toBe($project->id)
        ->and($issue->board_column_id)->toBe($toDo->id)
        ->and($issue->sprint_id)->toBeNull()
        ->and($issue->type)->toBe(IssueType::Task)
        ->and($issue->priority)->toBe(IssuePriority::Medium)
        ->and($issue->reporter_id)->toBe($owner->id)
        ->and($issue->assignee_id)->toBeNull()
        ->and($issue->number)->toBe(1);
});

test('quick-added issue numbers are allocated sequentially per project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'First',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
    ]);
    $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Second',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
    ]);

    expect(Issue::where('title', 'First')->firstOrFail()->number)->toBe(1)
        ->and(Issue::where('title', 'Second')->firstOrFail()->number)->toBe(2);
});

test('quick-added issues are appended to the bottom of the column', function () {
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

    $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'New task',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
    ]);

    expect(Issue::where('title', 'New task')->firstOrFail()->position)->toBe(1);
});

test('quick-add validates the title is required', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => '',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
    ]);

    $response->assertSessionHasErrors('title');
});

test('quick-add assigns the sprint from the currently viewed filter', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $sprint = Sprint::factory()->for($project)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Sprint task',
        'board_column_id' => $toDo->id,
        'sprint_id' => $sprint->id,
    ]);

    $response->assertRedirect();

    expect(Issue::where('title', 'Sprint task')->firstOrFail()->sprint_id)->toBe($sprint->id);
});

test('a non-member cannot quick-add an issue', function () {
    $project = Project::factory()->create();
    $column = BoardColumn::factory()->for($project)->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->post("/projects/{$project->key}/issues", [
        'title' => 'Should not be created',
        'board_column_id' => $column->id,
        'sprint_id' => null,
    ]);

    $response->assertForbidden();
    expect(Issue::where('title', 'Should not be created')->exists())->toBeFalse();
});

test('quick-add rejects a board column that belongs to another project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $otherProject = Project::factory()->create();
    $otherColumn = BoardColumn::factory()->for($otherProject)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Cross-project',
        'board_column_id' => $otherColumn->id,
        'sprint_id' => null,
    ]);

    $response->assertSessionHasErrors('board_column_id');
});

test('quick-add rejects a sprint that belongs to another project', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $otherProject = Project::factory()->create();
    $otherSprint = Sprint::factory()->for($otherProject)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Cross-project sprint',
        'board_column_id' => $toDo->id,
        'sprint_id' => $otherSprint->id,
    ]);

    $response->assertSessionHasErrors('sprint_id');
});

test('rejects a parent issue that is already a sub-task (one level hierarchy)', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;

    $epic = Issue::factory()->for($project)->epic()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
    ]);
    $child = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'parent_id' => $epic->id,
        'reporter_id' => $owner->id,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Grandchild',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'parent_id' => $child->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
});
