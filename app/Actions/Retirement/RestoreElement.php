<?php

namespace App\Actions\Retirement;

use App\Actions\Support\ActiveItemRelocker;
use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Models\Concerns\Retirable;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Brings a retired element back to its previous non-retired state
 * (retirement spec "A Retired Element Can Be Restored"): refused while its
 * parent is retired, the retirement row kept as history (`restored_at`),
 * the content retired together with it restored too, and dependency states
 * recomputed (they are derived). A major AI operation, like retiring.
 */
class RestoreElement
{
    public function __construct(
        private DomainTransaction $transaction,
        private RetirementHandlers $handlers,
        private ActiveItemRelocker $relocker,
    ) {}

    /**
     * @throws ModelNotFoundException<Retirement>|ValidationException
     */
    public function __invoke(Actor $actor, Retirement $retirement): Model
    {
        if ($retirement->user_id !== $actor->user->id) {
            throw (new ModelNotFoundException)->setModel(Retirement::class, [$retirement->id]);
        }

        if ($retirement->restored_at !== null) {
            throw ValidationException::withMessages(['retirement' => 'Esto ya volvió al mapa.']);
        }

        $element = $retirement->element();

        if ($element === null) {
            throw (new ModelNotFoundException)->setModel(Retirement::class, [$retirement->id]);
        }

        $blocker = $this->handlers->for($element)->restoreBlocker($element);

        if ($blocker !== null) {
            throw ValidationException::withMessages(['retirement' => $blocker]);
        }

        return $this->transaction->run($actor, Operation::RestoreElement, $element, function () use ($actor, $retirement, $element): Model {
            $locked = Retirement::query()->whereKey($retirement->id)->lockForUpdate()->firstOrFail();
            $current = $element->newQueryWithoutScopes()->whereKey($element->getKey())->lockForUpdate()->first();

            if ($locked->restored_at !== null || ! $current instanceof Retirable || ! $current->isRetired()) {
                throw ValidationException::withMessages(['retirement' => 'Esto ya volvió al mapa.']);
            }

            $this->restore($locked, $element);

            $this->relocker->relockLockedActive($actor->user->id);

            return $element->refresh();
        });
    }

    /**
     * Restore one element, then what was retired together with it.
     */
    private function restore(Retirement $retirement, Model $element): void
    {
        $this->handlers->for($element)->restore($element, $retirement);

        $retirement->update(['restored_at' => now()]);

        foreach ($retirement->children()->open()->get() as $child) {
            $childElement = $child->element();

            if ($childElement !== null) {
                $this->restore($child, $childElement);
            }
        }
    }
}
