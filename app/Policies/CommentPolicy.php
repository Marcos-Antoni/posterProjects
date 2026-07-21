<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Only the comment's own author may edit it — this is deliberately NOT
     * gated by project ownership. History stays intact: not even the
     * project owner can touch another member's comment.
     */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    /**
     * Only the comment's own author may delete it. Same rule as update().
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}
