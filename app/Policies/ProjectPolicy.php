<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Any authenticated user may list projects — the controller scopes the
     * query to only the ones they are a member of.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Only members of the project may view it.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->members()->whereKey($user->id)->exists();
    }

    /**
     * Only the owner may update the project's key, name, or description.
     */
    public function update(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id;
    }

    /**
     * Only the owner may archive (soft delete) the project.
     */
    public function archive(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id;
    }

    /**
     * Only the owner may restore an archived project.
     */
    public function restore(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id;
    }

    /**
     * Only the owner may permanently delete an archived project.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id;
    }
}
