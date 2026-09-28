<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Actions\Support\PlanStateRecalculator;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Marks an available or active item done (issues spec). A locked item is
 * refused; a milestone needs a line of evidence; an already-done item is
 * returned unchanged (idempotent). Closes the item's open focus session,
 * recomputes its plan and reports which dependents became available.
 */
class CheckItem
{
    use GuardsObjectives;

    public const MISSING_EVIDENCE = 'Un hito se marca con una línea de evidencia: qué quedó hecho y dónde se ve.';

    public function __construct(
        private DomainTransaction $transaction,
        private PlanStateRecalculator $planStates,
    ) {}

    public function __invoke(Actor $actor, Item $item, ?string $evidence = null, ?string $link = null): CheckResult
    {
        $this->ensureItemWritable($actor, $item);

        $state = $item->deriveState();

        if ($state === ItemState::Done) {
            return new CheckResult($item, new Collection);
        }

        if ($state === ItemState::Locked) {
            $open = $item->prerequisites()
                ->whereNull('items.retired_at')
                ->whereNull('items.completed_at')
                ->pluck('title')
                ->implode(', ');

            throw ValidationException::withMessages([
                'item' => "Esta tarea está bloqueada: se abre al terminar {$open}.",
            ]);
        }

        $evidence = trim((string) $evidence);

        if ($item->kind === ItemKind::Milestone && $evidence === '') {
            throw ValidationException::withMessages(['evidence' => self::MISSING_EVIDENCE]);
        }

        return $this->transaction->run($actor, Operation::CheckItem, $item, function () use ($item, $evidence, $link): CheckResult {
            $lockedBefore = $this->lockedDependentIds($item);

            $item->update(['completed_at' => now(), 'is_active' => false]);

            $item->focusSessions()->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'done']);

            if ($item->kind === ItemKind::Milestone) {
                $item->evidence()->updateOrCreate([], ['text' => $evidence, 'link' => filled($link) ? $link : null]);
            }

            $this->planStates->recalculate($item->plan);

            $unlocked = Item::query()
                ->withState()
                ->with('objective')
                ->whereIn('items.id', $lockedBefore)
                ->get()
                ->filter(fn (Item $dependent): bool => $dependent->state === ItemState::Available)
                ->values();

            return new CheckResult($item->refresh(), $unlocked);
        });
    }

    /**
     * @return list<int>
     */
    private function lockedDependentIds(Item $item): array
    {
        return array_values(Item::query()
            ->withState()
            ->whereIn('items.id', $item->dependents()->select('items.id'))
            ->get()
            ->filter(fn (Item $dependent): bool => $dependent->state === ItemState::Locked)
            ->map(fn (Item $dependent): int => $dependent->id)
            ->all());
    }
}
