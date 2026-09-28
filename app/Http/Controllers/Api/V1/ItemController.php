<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Items\CheckItem;
use App\Actions\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckItemRequest;
use App\Http\Resources\ItemDetails;
use App\Models\Item;
use App\Models\Objective;
use Illuminate\Http\JsonResponse;

class ItemController extends Controller
{
    /**
     * One item by its public key inside `{objective}` (api-issues spec). The
     * key is resolved in code, never by route-model binding: a key without a
     * dash, a bad suffix, another objective's prefix, an unknown number and a
     * retired item are all the same generic 404.
     */
    public function show(Objective $objective, string $item): JsonResponse
    {
        return response()->json([
            'data' => ItemDetails::present(ItemDetails::load($this->resolve($objective, $item))),
        ]);
    }

    /**
     * Mark an available or active item done through the same `CheckItem`
     * action as the web: a locked item is 422, a milestone needs non-empty
     * `evidence` (422), an already-done item is 200 unchanged. Answers the
     * item detail plus `unlocked` (the items that became available).
     */
    public function check(CheckItemRequest $request, Objective $objective, string $item, CheckItem $checkItem): JsonResponse
    {
        $result = $checkItem(
            Actor::ownerApi($request->user()),
            $this->resolve($objective, $item),
            $request->validated('evidence'),
            $request->validated('link'),
        );

        return response()->json([
            'data' => [
                ...ItemDetails::present(ItemDetails::load($result->item)),
                'unlocked' => ItemDetails::neighbours($result->unlocked),
            ],
        ]);
    }

    private function resolve(Objective $objective, string $key): Item
    {
        $item = Item::resolveByKey($objective, $key);

        abort_if($item === null, 404);

        return $item;
    }
}
