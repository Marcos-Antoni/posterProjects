<?php

namespace App\Actions\Objectives;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ObjectiveState;
use App\Models\Objective;
use Illuminate\Validation\ValidationException;

/**
 * Closed → active (projects spec: "A closed objective MAY be reopened").
 */
class ReopenObjective
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Objective $objective): Objective
    {
        $this->ensureOwned($actor, $objective);

        if ($objective->state !== ObjectiveState::Closed) {
            throw ValidationException::withMessages(['objective' => 'Solo se reabre un objetivo cerrado.']);
        }

        return $this->transaction->run($actor, Operation::ReopenObjective, $objective, function () use ($objective): Objective {
            $objective->update(['state' => ObjectiveState::Active, 'closed_at' => null]);

            return $objective;
        });
    }
}
