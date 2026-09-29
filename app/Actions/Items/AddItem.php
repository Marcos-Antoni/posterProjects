<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\PlanStateRecalculator;
use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\Plan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Adds a task or milestone at the end of a plan (issues spec "Adding An Item
 * Requires A Title And A 2-Minute Version"): it receives the next number of
 * its objective, and may start with prerequisites from any of the owner's
 * objectives.
 */
class AddItem
{
    use GuardsObjectives;

    public const MISSING_TWO_MINUTE = 'Falta la versión de 2 minutos: es lo primero que vas a ver en Ahora.';

    public function __construct(
        private DomainTransaction $transaction,
        private PlanStateRecalculator $planStates,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`StoreItemRequest`)
     */
    public function __invoke(Actor $actor, Plan $plan, array $data): Item
    {
        $objective = $plan->objective;

        $this->ensureOwned($actor, $objective);
        $this->ensureWritable($objective);
        $this->ensurePlanWritable($plan);

        $title = trim((string) ($data['title'] ?? ''));
        $twoMinute = trim((string) ($data['two_minute_version'] ?? ''));

        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Falta el título.']);
        }

        if ($twoMinute === '') {
            throw ValidationException::withMessages(['two_minute_version' => self::MISSING_TWO_MINUTE]);
        }

        $prerequisites = collect(array_values(array_unique(array_map('intval', (array) ($data['prerequisite_ids'] ?? [])))));

        $item = new Item([
            'objective_id' => $objective->id,
            'plan_id' => $plan->id,
            'kind' => ItemKind::from($data['kind'] ?? ItemKind::Task->value),
            'title' => $title,
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'two_minute_version' => $twoMinute,
            'target_date' => $data['target_date'] ?? null,
        ]);

        return $this->transaction->run($actor, Operation::AddItem, $item, function () use ($actor, $objective, $plan, $item, $prerequisites): Item {
            $item->number = $objective->allocateNextItemNumber();
            $item->position = Item::nextPositionIn($plan);
            $item->save();

            if ($prerequisites->isNotEmpty()) {
                $found = Item::query()
                    ->whereIn('items.id', $prerequisites)
                    ->whereNull('items.retired_at')
                    ->whereHas('objective', fn ($query) => $query->where('user_id', $actor->user->id))
                    ->pluck('id');

                if ($found->count() !== $prerequisites->count()) {
                    throw (new ModelNotFoundException)->setModel(Item::class, $prerequisites->diff($found)->values()->all());
                }

                $item->prerequisites()->attach($found->all());
            }

            $this->planStates->recalculate($plan);

            return $item->setRelation('objective', $objective)->load('prerequisites');
        });
    }
}
