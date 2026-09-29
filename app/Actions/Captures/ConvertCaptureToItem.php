<?php

namespace App\Actions\Captures;

use App\Actions\Items\AddItem;
use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsCaptures;
use App\Actions\Support\Operation;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Plan;

/**
 * Triage: converts a capture into a task or milestone (capture-inbox spec
 * "Triage Converts Or Retires Captures"). The capture keeps a link to the
 * new item and leaves the untriaged list; the item itself is created
 * exactly like "Agregar" on a plan (`AddItem`), 2-minute version required.
 */
class ConvertCaptureToItem
{
    use GuardsCaptures;

    public function __construct(
        private DomainTransaction $transaction,
        private AddItem $addItem,
    ) {}

    /**
     * @param  array<string, mixed>  $itemData  validated input (`StoreItemRequest` shape)
     */
    public function __invoke(Actor $actor, Capture $capture, Plan $plan, array $itemData): Item
    {
        $this->ensureUntriaged($actor, $capture);

        return $this->transaction->run($actor, Operation::ConvertCaptureToItem, $capture, function () use ($actor, $capture, $plan, $itemData): Item {
            $item = ($this->addItem)($actor, $plan, $itemData);

            $capture->update(['triaged_at' => now(), 'result_type' => 'item', 'result_id' => $item->id]);

            return $item;
        });
    }
}
