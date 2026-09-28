<?php

namespace App\Actions\Retirement;

use App\Actions\Support\ActiveItemRelocker;
use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Enums\ItemState;
use App\Enums\RetirementDecision;
use App\Models\Concerns\Retirable;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Retires any retirable element (retirement spec, design D8): a written
 * reason of at least 10 characters, one content decision (move / split /
 * archive as-is, archive as-is by default for an element without content),
 * applied atomically with a history row per retired element and the
 * dependents recomputed. Retiring is a major AI operation (ai-operations):
 * an AI actor is refused by the tier gate until Phase 8 turns it into a
 * proposal. The element kinds are pluggable through `RetirementHandlers`.
 */
class RetireElement
{
    public const REASON_TOO_SHORT = 'Escribí al menos 10 caracteres para que te sirva después.';

    public const REASON_MIN_LENGTH = 10;

    public const REASON_MAX_LENGTH = 1000;

    public function __construct(
        private DomainTransaction $transaction,
        private RetirementHandlers $handlers,
        private RetirementRecorder $recorder,
        private ActiveItemRelocker $relocker,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  decision details: `target` (move: item key, plan id or objective key),
     *                                         `parts` (split: list of {title, two_minute_version}), `assignments` (plan split: item id => part index)
     *
     * @throws ModelNotFoundException<Model>|ValidationException
     */
    public function __invoke(Actor $actor, Model $element, string $reason, ?RetirementDecision $decision = null, array $payload = []): RetirementResult
    {
        $handler = $this->handlers->for($element);

        if ($handler->ownerId($element) !== $actor->user->id || ($element instanceof Retirable && $element->isRetired())) {
            throw (new ModelNotFoundException)->setModel($element::class, [$element->getKey()]);
        }

        $reason = self::validReason($reason);

        $handler->guard($element);

        $decision ??= $handler->defaultDecision($element);

        if ($decision === null) {
            throw ValidationException::withMessages(['decision' => 'Elegí qué hacés con lo que contiene: mover, dividir o archivar tal cual.']);
        }

        if (! in_array($decision, $handler->decisions($element), true)) {
            throw ValidationException::withMessages(['decision' => "«{$decision->label()}» no aplica a {$handler->kind($element)->label()}."]);
        }

        return $this->transaction->run($actor, Operation::RetireElement, $element, function () use ($handler, $element, $reason, $decision, $payload): RetirementResult {
            $this->lockOpen($element);

            $lockedBefore = $this->lockedDependentIds($handler->itemIds($element));

            $outcome = $handler->retire($element, $reason, $decision, $payload, $this->recorder);

            $this->relocker->relockLockedActive($handler->ownerId($element));

            $unlocked = $lockedBefore === [] ? new Collection : Item::query()
                ->withState()
                ->with('objective')
                ->whereIn('items.id', $lockedBefore)
                ->get()
                ->filter(fn (Item $dependent): bool => $dependent->state === ItemState::Available)
                ->values();

            return new RetirementResult($outcome->retirement, $unlocked, new Collection($outcome->created), $outcome->moved, $outcome->cascaded);
        });
    }

    /**
     * The trimmed reason, or a Spanish validation error when it is shorter
     * than 10 characters (or absurdly long).
     *
     * @throws ValidationException
     */
    public static function validReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN_LENGTH) {
            throw ValidationException::withMessages(['reason' => self::REASON_TOO_SHORT]);
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'La razón tiene como máximo 1000 caracteres.']);
        }

        return $reason;
    }

    /**
     * Re-read the element under a row lock: a concurrent request that
     * retired it first wins, and this one is refused cleanly (no second open
     * retirement for one element).
     *
     * @throws ValidationException
     */
    private function lockOpen(Model $element): void
    {
        $fresh = $element->newQueryWithoutScopes()->whereKey($element->getKey())->lockForUpdate()->first();

        if (! $fresh instanceof Retirable || $fresh->isRetired()) {
            throw ValidationException::withMessages(['retirement' => 'Esto ya está retirado.']);
        }
    }

    /**
     * Locked, non-retired dependents of the given items.
     *
     * @param  list<int>  $itemIds
     * @return list<int>
     */
    private function lockedDependentIds(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return array_values(Item::query()
            ->withState()
            ->whereIn('items.id', fn ($query) => $query->select('dependent_id')->from('item_dependencies')->whereIn('prerequisite_id', $itemIds))
            ->whereNotIn('items.id', $itemIds)
            ->get()
            ->filter(fn (Item $dependent): bool => $dependent->state === ItemState::Locked)
            ->map(fn (Item $dependent): int => $dependent->id)
            ->all());
    }
}
