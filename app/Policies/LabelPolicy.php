<?php

namespace App\Policies;

use App\Models\Label;
use App\Models\User;

class LabelPolicy
{
    /**
     * No `create()` gate here on purpose — any project member may create a
     * label (`StoreLabelRequest::authorize()` already checks membership
     * directly via `can('view', $project)`, the same pattern
     * `UpdateIssueRequest` uses). Only renaming and deleting are owner-only.
     */

    /**
     * Only the label's project owner may rename it.
     */
    public function update(User $user, Label $label): bool
    {
        return $label->project->owner_id === $user->id;
    }

    /**
     * Only the label's project owner may delete it.
     */
    public function delete(User $user, Label $label): bool
    {
        return $label->project->owner_id === $user->id;
    }
}
