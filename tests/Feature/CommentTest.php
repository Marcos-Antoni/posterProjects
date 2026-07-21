<?php

use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;

test('comment persists with expected attributes', function () {
    $issue = Issue::factory()->create();
    $author = User::factory()->create();
    $comment = Comment::factory()->for($issue)->for($author, 'author')->create([
        'body' => 'Looks good to me.',
    ]);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->issue_id)->toBe($issue->id)
        ->and($comment->user_id)->toBe($author->id)
        ->and($comment->body)->toBe('Looks good to me.');

    $this->assertModelExists($comment);
});

test('a comment belongs to its issue and author', function () {
    $issue = Issue::factory()->create();
    $author = User::factory()->create();
    $comment = Comment::factory()->for($issue)->for($author, 'author')->create();

    expect($comment->issue->id)->toBe($issue->id)
        ->and($comment->author->id)->toBe($author->id);
});

test('an issue exposes its comments', function () {
    $issue = Issue::factory()->create();
    Comment::factory()->for($issue)->count(3)->create();

    $otherIssue = Issue::factory()->create();
    Comment::factory()->for($otherIssue)->create();

    expect($issue->comments)->toHaveCount(3);
});
