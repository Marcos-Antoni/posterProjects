<?php

namespace App\Actions\ControlMap;

use App\Actions\Items\AddItem;
use App\Actions\Support\Actor;
use App\Actions\Support\GuardsObjectives;
use App\Models\ControlMapEntry;
use App\Models\Item;
use App\Models\Plan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Turns a control-map entry the owner controls ("depende de mí" or "puedo
 * influir") into a task of one of the objective's plans. An entry in "no
 * depende de mí" never becomes a task (control-plan spec). The entry stays
 * in the map.
 */
class ConvertControlMapEntry
{
    use GuardsObjectives;

    public function __construct(private AddItem $addItem) {}

    /**
     * @param  array{two_minute_version?: string, kind?: string, description?: string|null}  $data
     */
    public function __invoke(Actor $actor, ControlMapEntry $entry, Plan $plan, array $data): Item
    {
        $objective = $entry->owningObjective();

        $this->ensureOwned($actor, $objective);

        if (! $entry->zone->canBecomeTask()) {
            throw ValidationException::withMessages([
                'entry' => 'Lo que no depende de vos se anota para soltarlo: no se convierte en tarea.',
            ]);
        }

        if ($plan->objective_id !== $objective->id) {
            throw (new ModelNotFoundException)->setModel(Plan::class, [$plan->id]);
        }

        return ($this->addItem)($actor, $plan, [
            'kind' => $data['kind'] ?? 'task',
            'title' => $entry->text,
            'two_minute_version' => $data['two_minute_version'] ?? '',
            'description' => $data['description'] ?? null,
        ]);
    }
}
