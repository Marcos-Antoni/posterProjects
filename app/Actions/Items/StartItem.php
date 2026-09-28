<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\FocusSession;
use App\Models\Item;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Makes an item THE active one of its owner (now-focus "Exactly One Active
 * Task At A Time", design D6). In one transaction: the owner's previously
 * active item is released (it goes back to available, or locked if its
 * prerequisites changed — the state is derived) and its open focus session
 * is closed with `switched`; then this item is flagged active and a focus
 * session opens at now. The partial unique index
 * `items_one_active_per_user` backs the rule; the owner row is locked first
 * so two concurrent starts serialize instead of hitting it.
 *
 * Starting the item that is already active is a no-op: its focus clock keeps
 * running (a reload or a second tap never resets the 25-minute cue).
 */
class StartItem
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    /**
     * Only items the Now screen can show may become active: an item of a
     * draft or retired plan, or of an objective that is not active, is
     * refused (the Now task is never hidden behind a suggestion).
     */
    private function ensureStartable(Item $item): void
    {
        if ($item->objective->state !== ObjectiveState::Active) {
            throw ValidationException::withMessages([
                'item' => 'Esta tarea es de un objetivo que no está activo: activalo para empezarla.',
            ]);
        }

        $message = match ($item->plan->state) {
            PlanState::Draft => 'Esta tarea es de un plan en borrador: activá el plan para empezarla.',
            PlanState::Retired => 'Esta tarea es de un plan retirado: restauralo desde Retirados para empezarla.',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['item' => $message]);
        }
    }

    public function __invoke(Actor $actor, Item $item): Item
    {
        $this->ensureItemWritable($actor, $item);
        $this->ensureStartable($item);

        $state = $item->deriveState();

        if ($state === ItemState::Active) {
            return $item;
        }

        if ($state === ItemState::Done) {
            throw ValidationException::withMessages([
                'item' => 'Esta tarea ya está hecha: desmarcala si querés volver a trabajarla.',
            ]);
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

        return $this->transaction->run($actor, Operation::StartItem, $item, function () use ($actor, $item): Item {
            User::query()->whereKey($actor->user->id)->lockForUpdate()->first();

            $previous = Item::query()
                ->where('user_id', $actor->user->id)
                ->where('is_active', true)
                ->whereKeyNot($item->id)
                ->pluck('id');

            if ($previous->isNotEmpty()) {
                Item::query()->whereIn('id', $previous)->update(['is_active' => false]);

                FocusSession::query()
                    ->whereIn('item_id', $previous)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => now(), 'end_reason' => 'switched']);
            }

            $item->update(['is_active' => true]);

            $item->focusSessions()->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'switched']);
            $item->focusSessions()->create(['started_at' => now()]);

            return $item->refresh();
        });
    }
}
