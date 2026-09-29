<?php

namespace App\Actions\Objectives;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Enums\ObjectiveState;
use App\Models\Objective;

/**
 * The direct path (projects spec): the owner creates an objective with a
 * COMPLETE 5-point plan and it is born active. Anything missing is refused,
 * naming the point, and nothing is saved. Drafts only come from a tree
 * negotiation (Phase 9).
 */
class CreateObjective
{
    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`StoreObjectiveRequest`)
     */
    public function __invoke(Actor $actor, array $data): Objective
    {
        $objective = new Objective([
            'user_id' => $actor->user->id,
            'key' => $data['key'],
            'title' => trim((string) $data['title']),
            'identity_statement' => filled($data['identity_statement'] ?? null) ? trim((string) $data['identity_statement']) : null,
            'state' => ObjectiveState::Active,
        ]);

        return $this->transaction->run($actor, Operation::CreateObjective, $objective, function () use ($actor, $objective, $data): Objective {
            $objective->position = Objective::nextPositionFor($actor->user);
            $objective->save();

            $this->controlPlans->write($objective, $data);
            $this->controlPlans->ensureComplete($objective);

            return $objective->load(['controlPlan', 'controlMapEntries']);
        });
    }
}
