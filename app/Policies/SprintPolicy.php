<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;

class SprintPolicy
{
    /**
     * Only the project's owner may create sprints.
     */
    public function create(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id;
    }

    /**
     * Only the project's owner may rename/reschedule a sprint.
     */
    public function update(User $user, Sprint $sprint): bool
    {
        return $sprint->project->owner_id === $user->id;
    }

    /**
     * Only the project's owner may delete a sprint.
     */
    public function delete(User $user, Sprint $sprint): bool
    {
        return $sprint->project->owner_id === $user->id;
    }
}
