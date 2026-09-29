<?php

namespace App\Actions\Objectives;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Enums\ObjectiveState;
use App\Models\Objective;

/**
 * A draft objective born from a captured idea (capture-inbox spec: "convert
 * to ... a new objective draft"). Unlike `CreateObjective` (the direct path,
 * always born ACTIVE with a complete 5-point plan), a draft has none yet:
 * the owner fills its control plan later from the objective form, before
 * `ActivateObjective` (which already requires a complete one).
 */
class CreateObjectiveDraft
{
    public function __construct(private DomainTransaction $transaction) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`ConvertCaptureToObjectiveRequest`): `key`, `title`
     */
    public function __invoke(Actor $actor, array $data): Objective
    {
        $objective = new Objective([
            'user_id' => $actor->user->id,
            'key' => mb_strtoupper(trim((string) $data['key'])),
            'title' => trim((string) $data['title']),
            'state' => ObjectiveState::Draft,
        ]);

        return $this->transaction->run($actor, Operation::CreateObjectiveDraft, $objective, function () use ($actor, $objective): Objective {
            $objective->position = Objective::nextPositionFor($actor->user);
            $objective->save();

            return $objective;
        });
    }
}
