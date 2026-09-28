<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\PlanStateRecalculator;
use App\Enums\PlanState;
use App\Enums\TwoMinuteSource;
use App\Models\Item;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * The owner edits an item's title, description, 2-minute version, optional
 * target date, plan (only within the same objective) and manual position
 * (issues spec "The Owner Can Edit Item Fields"). A replaced 2-minute
 * version goes to the item's history; it can never be cleared.
 */
class UpdateItem
{
    use GuardsObjectives;

    public function __construct(
        private DomainTransaction $transaction,
        private PlanStateRecalculator $planStates,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`UpdateItemRequest`)
     */
    public function __invoke(Actor $actor, Item $item, array $data, TwoMinuteSource $source = TwoMinuteSource::Owner): Item
    {
        $this->ensureItemWritable($actor, $item);

        $targetPlan = null;

        if (array_key_exists('plan_id', $data) && (int) $data['plan_id'] !== $item->plan_id) {
            $targetPlan = Plan::query()->find((int) $data['plan_id']);

            if ($targetPlan === null || $targetPlan->objective_id !== $item->objective_id || $targetPlan->state === PlanState::Retired) {
                throw ValidationException::withMessages([
                    'plan_id' => 'Solo podés moverla a otro plan del mismo objetivo. Para llevarla a otro objetivo, retirala con la decisión «mover».',
                ]);
            }
        }

        if (array_key_exists('two_minute_version', $data) && trim((string) $data['two_minute_version']) === '') {
            throw ValidationException::withMessages([
                'two_minute_version' => 'La versión de 2 minutos no se puede borrar: es lo primero que ves en Ahora.',
            ]);
        }

        return $this->transaction->run($actor, Operation::UpdateItem, $item, function () use ($item, $data, $targetPlan, $source): Item {
            $previousPlan = $item->plan;

            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = is_string($data[$field]) ? trim($data[$field]) : null;
                    $item->{$field} = $field === 'description' && $value === '' ? null : $value;
                }
            }

            if (array_key_exists('two_minute_version', $data)) {
                $next = trim((string) $data['two_minute_version']);

                if ($next !== $item->two_minute_version) {
                    $item->twoMinuteHistory()->create([
                        'text' => $item->two_minute_version,
                        'source' => $source,
                        'replaced_at' => now(),
                    ]);

                    $item->two_minute_version = $next;
                }
            }

            if (array_key_exists('target_date', $data)) {
                $item->target_date = $data['target_date'] ?: null;
            }

            if ($targetPlan !== null) {
                $item->plan_id = $targetPlan->id;
                $item->position = Item::nextPositionIn($targetPlan);
            }

            $item->save();

            if (array_key_exists('position', $data) && $targetPlan === null) {
                $this->placeAt($item, (int) $data['position']);
            }

            if ($targetPlan !== null) {
                $this->planStates->recalculate($previousPlan);
                $this->planStates->recalculate($targetPlan);
            }

            return $item->refresh();
        });
    }

    /**
     * Put the item at a 0-based index of its plan, renumbering the others.
     */
    private function placeAt(Item $item, int $index): void
    {
        $siblings = Item::query()
            ->where('plan_id', $item->plan_id)
            ->whereKeyNot($item->id)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        array_splice($siblings, max(0, min($index, count($siblings))), 0, [$item]);

        foreach ($siblings as $position => $sibling) {
            if ($sibling->position !== $position) {
                $sibling->position = $position;
                $sibling->save();
            }
        }
    }
}
