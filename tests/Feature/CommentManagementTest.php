<?php

use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('guests are redirected to login when posting a comment', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    $response = $this->post("/projects/{$project->key}/issues/{$issue->id}/comments", [
        'body' => 'Looks good',
    ]);

    $response->assertRedirect('/login');
});

test('a member can post a comment on an issue', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues/{$issue->id}/comments", [
        'body' => 'Looks good to me.',
    ]);

    $response->assertRedirect();

    $comment = Comment::where('issue_id', $issue->id)->firstOrFail();

    expect($comment->body)->toBe('Looks good to me.')
        ->and($comment->user_id)->toBe($owner->id);
});

test('posting an empty comment is rejected', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);

    $response = $this->actingAs($owner)->post("/projects/{$project->key}/issues/{$issue->id}/comments", [
        'body' => '',
    ]);

    $response->assertSessionHasErrors('body');
    expect(Comment::where('issue_id', $issue->id)->exists())->toBeFalse();
});

test('a non-member cannot post a comment', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->post("/projects/{$project->key}/issues/{$issue->id}/comments", [
        'body' => 'Should not be created',
    ]);

    $response->assertForbidden();
    expect(Comment::where('issue_id', $issue->id)->exists())->toBeFalse();
});

test('the author can edit their own comment', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $comment = Comment::factory()->for($issue)->for($owner, 'author')->create(['body' => 'Original']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}", [
        'body' => 'Edited',
    ]);

    $response->assertRedirect();
    expect($comment->refresh()->body)->toBe('Edited');
});

test('the author can delete their own comment', function () {
    $owner = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $comment = Comment::factory()->for($issue)->for($owner, 'author')->create();

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}");

    $response->assertRedirect();
    expect(Comment::find($comment->id))->toBeNull();
});

test('another member cannot edit someone else\'s comment', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($teammate->id);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $comment = Comment::factory()->for($issue)->for($owner, 'author')->create(['body' => 'Original']);

    $response = $this->actingAs($teammate)->patch("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}", [
        'body' => 'Hijacked',
    ]);

    $response->assertForbidden();
    expect($comment->refresh()->body)->toBe('Original');
});

test('another member cannot delete someone else\'s comment', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($teammate->id);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $comment = Comment::factory()->for($issue)->for($owner, 'author')->create();

    $response = $this->actingAs($teammate)->delete("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}");

    $response->assertForbidden();
    expect(Comment::find($comment->id))->not->toBeNull();
});

test('the project owner cannot edit a comment they did not author', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($teammate->id);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $comment = Comment::factory()->for($issue)->for($teammate, 'author')->create(['body' => 'Original']);

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}", [
        'body' => 'Owner tries to hijack',
    ]);

    $response->assertForbidden();
    expect($comment->refresh()->body)->toBe('Original');
});

test('the project owner cannot delete a comment they did not author', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $project = Project::createWithDefaultColumns([
        'owner_id' => $owner->id,
        'key' => 'DEMO',
        'name' => 'Demo',
        'description' => null,
    ]);
    $project->members()->attach($teammate->id);
    [$toDo] = $project->boardColumns;
    $issue = Issue::factory()->for($project)->create(['board_column_id' => $toDo->id, 'sprint_id' => null, 'reporter_id' => $owner->id]);
    $comment = Comment::factory()->for($issue)->for($teammate, 'author')->create();

    $response = $this->actingAs($owner)->delete("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}");

    $response->assertForbidden();
    expect(Comment::find($comment->id))->not->toBeNull();
});

test('a comment from another issue 404s via scoped bindings', function () {
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
    $comment = Comment::factory()->for($otherIssue)->for($owner, 'author')->create();

    $response = $this->actingAs($owner)->patch("/projects/{$project->key}/issues/{$issue->id}/comments/{$comment->id}", [
        'body' => 'Should 404',
    ]);

    $response->assertNotFound();
});
