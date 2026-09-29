<?php

namespace App\Actions\Captures;

use App\Actions\Objectives\CreateObjectiveDraft;
use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsCaptures;
use App\Actions\Support\Operation;
use App\Models\Capture;
use App\Models\Objective;

/**
 * Triage: converts a capture into a new draft objective (capture-inbox
 * spec). The capture keeps a link to the new objective.
 */
class ConvertCaptureToObjectiveDraft
{
    use GuardsCaptures;

    public function __construct(
        private DomainTransaction $transaction,
        private CreateObjectiveDraft $createDraft,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`ConvertCaptureToObjectiveRequest`): `key`, `title`
     */
    public function __invoke(Actor $actor, Capture $capture, array $data): Objective
    {
        $this->ensureUntriaged($actor, $capture);

        return $this->transaction->run($actor, Operation::ConvertCaptureToObjectiveDraft, $capture, function () use ($actor, $capture, $data): Objective {
            $objective = ($this->createDraft)($actor, $data);

            $capture->update(['triaged_at' => now(), 'result_type' => 'objective', 'result_id' => $objective->id]);

            return $objective;
        });
    }
}
