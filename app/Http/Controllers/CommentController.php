<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    /**
     * Post a new comment on an issue. Any project member may comment.
     */
    public function store(StoreCommentRequest $request, Project $project, Issue $issue): RedirectResponse
    {
        $issue->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        return back();
    }

    /**
     * Edit a comment's body. Author only — not even the project owner
     * (see `CommentPolicy::update()`).
     */
    public function update(UpdateCommentRequest $request, Project $project, Issue $issue, Comment $comment): RedirectResponse
    {
        $comment->update($request->validated());

        return back();
    }

    /**
     * Delete a comment. Author only — not even the project owner (see
     * `CommentPolicy::delete()`).
     */
    public function destroy(Project $project, Issue $issue, Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $comment->delete();

        return back();
    }
}
