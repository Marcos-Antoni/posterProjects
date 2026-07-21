<?php

use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

/**
 * T-9.8: the issue modal's subtask management. Inline creation reuses
 * `IssueController::store` with `parent_id` (already validated to a single
 * level by `StoreIssueRequest::after()` since T-9.3) — no new backend
 * endpoint. Toggling "Done" reuses `IssueController::update` (already
 * recomputes `position` on a column change since T-9.7) by moving
 * `board_column_id` to the project's last/first column by position — no
 * new backend endpoint either. These tests confirm both existing endpoints
 * already satisfy T-9.8's acceptance criteria end-to-end.
 */
test('a member can create a subtask under an issue via parent_id', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $parent = Issue::factory()->for($project)->epic()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Sub-task of the epic',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'parent_id' => $parent->id,
    ]);

    $response->assertRedirect();

    $subtask = Issue::where('title', 'Sub-task of the epic')->firstOrFail();

    expect($subtask->parent_id)->toBe($parent->id)
        ->and($subtask->type)->toBe(IssueType::Task);
});

test('creating a subtask under an issue that is itself a sub-task is rejected (one-level hierarchy)', function () {
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
    $childOfEpic = Issue::factory()->for($project)->task()->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
        'parent_id' => $epic->id,
    ]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues", [
        'title' => 'Grandchild attempt',
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'parent_id' => $childOfEpic->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
    expect(Issue::where('title', 'Grandchild attempt')->exists())->toBeFalse();
});

test('toggling done moves an issue to the last column by position', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, , $done] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $toDo->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
    ]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'board_column_id' => $done->id,
    ]);

    expect($issue->refresh()->board_column_id)->toBe($done->id);
});

test('toggling done off moves an issue back to the first column by position', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo, , $done] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create([
        'board_column_id' => $done->id,
        'sprint_id' => null,
        'reporter_id' => $owner->id,
    ]);

    $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}", [
        'board_column_id' => $toDo->id,
    ]);

    expect($issue->refresh()->board_column_id)->toBe($toDo->id);
});
