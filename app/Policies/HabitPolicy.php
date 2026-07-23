<?php

namespace App\Policies;

use App\Models\Habit;
use App\Models\User;

/**
 * Habits are strictly personal: every ability reduces to "is this my
 * habit?". There is intentionally no delete/restore/forceDelete —
 * habits can only be archived (and unarchived), never destroyed.
 */
class HabitPolicy
{
    /**
     * Any authenticated user may list habits — the controller scopes the
     * query to their own.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Only the owner may view a habit.
     */
    public function view(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id;
    }

    /**
     * Only the owner may update a habit.
     */
    public function update(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id;
    }

    /**
     * Only the owner may archive a habit.
     */
    public function archive(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id;
    }

    /**
     * Only the owner may reactivate an archived habit.
     */
    public function unarchive(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id;
    }

    /**
     * Only the owner may record entries against a habit.
     */
    public function logEntry(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id;
    }
}
