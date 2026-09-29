<?php

namespace App\Actions\Support;

use App\Models\Capture;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Shared guard for the capture triage actions: the capture must belong to
 * the actor's owner and still be untriaged (a triaged capture already left
 * the inbox; converting it again is refused as "not found", never a second
 * conversion overwriting the first link).
 */
trait GuardsCaptures
{
    /**
     * @throws ModelNotFoundException<Capture>
     */
    protected function ensureUntriaged(Actor $actor, Capture $capture): void
    {
        if ($capture->user_id !== $actor->user->id || $capture->isTriaged()) {
            throw (new ModelNotFoundException)->setModel(Capture::class, [$capture->id]);
        }
    }
}
