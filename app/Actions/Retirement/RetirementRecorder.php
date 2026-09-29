<?php

namespace App\Actions\Retirement;

use App\Enums\RetirementDecision;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one `retirements` history row per retired element (design D8),
 * with the snapshot columns the Retired view filters on.
 */
class RetirementRecorder
{
    public function __construct(private RetirementHandlers $handlers) {}

    /**
     * Record the retirement of `$element`, BEFORE the handler marks it (the
     * prior state is read here).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function record(Model $element, string $reason, RetirementDecision $decision, ?array $payload = null, ?Retirement $parent = null): Retirement
    {
        $handler = $this->handlers->for($element);

        return Retirement::query()->create([
            'user_id' => $handler->ownerId($element),
            'retirable_type' => $element->getMorphClass(),
            'retirable_id' => $element->getKey(),
            'kind' => $handler->kind($element),
            'objective_id' => $handler->objectiveId($element),
            'parent_id' => $parent?->id,
            'reason' => $reason,
            'decision' => $decision,
            'decision_payload' => $payload,
            'prior_state' => $handler->priorState($element),
            'retired_at' => now(),
        ]);
    }
}
