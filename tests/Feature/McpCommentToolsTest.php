<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Comments\CreateComment;
use App\Mcp\Tools\Comments\DeleteComment;
use App\Mcp\Tools\Comments\UpdateComment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('create-comment posts a comment authored by the caller', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($user->id);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);

    $response = PosterServer::actingAs($user)->tool(CreateComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'body' => 'Looks good to me',
    ]);

    $response->assertOk()
        ->assertSee('Looks good to me')
        ->assertSee(route('projects.issues.show', ['project' => 'DEMO', 'issueKey' => $issue->key]));

    $comment = Comment::where('issue_id', $issue->id)->firstOrFail();
    expect($comment->body)->toBe('Looks good to me')
        ->and($comment->user_id)->toBe($user->id);
});

test('create-comment is denied for a non-member', function () {
    $project = Project::factory()->create(['key' => 'DEMO']);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $stranger = User::factory()->create();

    $response = PosterServer::actingAs($stranger)->tool(CreateComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'body' => 'Should not be created',
    ]);

    $response->assertHasErrors();
    expect(Comment::where('body', 'Should not be created')->exists())->toBeFalse();
});

test('update-comment lets the author edit their own comment', function () {
    $author = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($author->id);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $comment = Comment::factory()->for($issue)->for($author, 'author')->create(['body' => 'Original']);

    $response = PosterServer::actingAs($author)->tool(UpdateComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'comment_id' => $comment->id,
        'body' => 'Edited',
    ]);

    $response->assertOk()->assertSee('Edited');

    expect($comment->refresh()->body)->toBe('Edited');
});

test('update-comment is denied for a member who is not the author', function () {
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach([$author->id, $otherMember->id]);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $comment = Comment::factory()->for($issue)->for($author, 'author')->create(['body' => 'Original']);

    $response = PosterServer::actingAs($otherMember)->tool(UpdateComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'comment_id' => $comment->id,
        'body' => 'Hijacked',
    ]);

    $response->assertHasErrors();
    expect($comment->refresh()->body)->toBe('Original');
});

test('delete-comment lets the author delete their own comment', function () {
    $author = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($author->id);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $comment = Comment::factory()->for($issue)->for($author, 'author')->create();

    $response = PosterServer::actingAs($author)->tool(DeleteComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'comment_id' => $comment->id,
    ]);

    $response->assertOk();
    expect(Comment::find($comment->id))->toBeNull();
});

test('delete-comment is denied for a member who is not the author', function () {
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach([$author->id, $otherMember->id]);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $comment = Comment::factory()->for($issue)->for($author, 'author')->create();

    $response = PosterServer::actingAs($otherMember)->tool(DeleteComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'comment_id' => $comment->id,
    ]);

    $response->assertHasErrors();
    expect(Comment::find($comment->id))->not->toBeNull();
});

test('update-comment rejects a comment_id belonging to another issue (scoped lookup)', function () {
    $author = User::factory()->create();
    $project = Project::factory()->create(['key' => 'DEMO']);
    $project->members()->attach($author->id);
    $issue = Issue::factory()->for($project)->create(['number' => 1]);
    $otherIssue = Issue::factory()->for($project)->create(['number' => 2]);
    $comment = Comment::factory()->for($otherIssue)->for($author, 'author')->create();

    $response = PosterServer::actingAs($author)->tool(UpdateComment::class, [
        'project_key' => 'DEMO',
        'issue_key' => $issue->key,
        'comment_id' => $comment->id,
        'body' => 'Hijacked',
    ]);

    $response->assertHasErrors(["Comment not found: {$comment->id}"]);
});
