<?php

namespace App\Actions\ControlMap;

use App\Actions\Support\Actor;
use App\Actions\Support\ControlPlanWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ControlZone;
use App\Models\ControlMapEntry;
use App\Models\Objective;
use App\Models\Plan;

/**
 * Appends a short entry to one zone of an objective's or a plan's control
 * map (control-plan spec).
 */
class AddControlMapEntry
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private ControlPlanWriter $controlPlans,
    ) {}

    public function __invoke(Actor $actor, Objective|Plan $plannable, ControlZone $zone, string $text): ControlMapEntry
    {
        $objective = $plannable instanceof Plan ? $plannable->objective : $plannable;

        $this->ensureOwned($actor, $objective);
        $this->ensureWritable($objective);

        return $this->transaction->run(
            $actor,
            Operation::EditControlMap,
            $plannable,
            fn (): ControlMapEntry => $this->controlPlans->appendEntry($plannable, $zone, $text),
        );
    }
}
