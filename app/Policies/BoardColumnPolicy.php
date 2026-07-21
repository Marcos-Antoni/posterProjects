<?php

namespace App\Policies;

use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\User;

class BoardColumnPolicy
{
    /**
     * Only the project's owner may add columns to its board.
     */
    public function create(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id;
    }

    /**
     * Only the project's owner may rename or reorder a column.
     */
    public function update(User $user, BoardColumn $boardColumn): bool
    {
        return $boardColumn->project->owner_id === $user->id;
    }

    /**
     * Only the project's owner may delete a column.
     */
    public function delete(User $user, BoardColumn $boardColumn): bool
    {
        return $boardColumn->project->owner_id === $user->id;
    }
}
