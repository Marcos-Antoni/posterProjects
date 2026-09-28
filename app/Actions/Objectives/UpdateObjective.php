<?php

namespace App\Actions\Objectives;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ObjectiveState;
use App\Models\Objective;

/**
 * Edits an objective's title, identity statement and 5-point plan. An active
 * objective can never lose one of its five points (control-plan spec).
 */
class UpdateObjective
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`UpdateObjectiveRequest`)
     */
    public function __invoke(Actor $actor, Objective $objective, array $data): Objective
    {
        $this->ensureOwned($actor, $objective);
        $this->ensureWritable($objective);

        return $this->transaction->run($actor, Operation::UpdateObjective, $objective, function () use ($objective, $data): Objective {
            if (array_key_exists('title', $data)) {
                $objective->title = trim((string) $data['title']);
            }

            if (array_key_exists('identity_statement', $data)) {
                $objective->identity_statement = filled($data['identity_statement']) ? trim((string) $data['identity_statement']) : null;
            }

            $objective->save();

            $this->controlPlans->write($objective, $data);

            if ($objective->state === ObjectiveState::Active) {
                $this->controlPlans->ensureComplete($objective);
            }

            return $objective->load('controlPlan');
        });
    }
}
