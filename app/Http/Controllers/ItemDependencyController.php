<?php

namespace App\Http\Controllers;

use App\Actions\Items\AddDependency;
use App\Actions\Items\RemoveDependency;
use App\Actions\Support\Actor;
use App\Http\Requests\ItemDependencyRequest;
use App\Models\Item;
use App\Models\Objective;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes "completing A unlocks B" edges from the item screen, in
 * both directions: a prerequisite of this item ("La abrió") or an item this
 * one unlocks ("Abre al terminar"). The other end is a key from any of the
 * owner's objectives; the cycle, duplicate and self-edge rules live in
 * `AddDependency`.
 */
class ItemDependencyController extends Controller
{
    public function storePrerequisite(ItemDependencyRequest $request, Objective $objective, string $item, AddDependency $addDependency): RedirectResponse
    {
        $dependent = $this->resolve($objective, $item);

        $addDependency(Actor::ownerWeb($request->user()), $this->other($request), $dependent);

        return back();
    }

    public function storeUnlock(ItemDependencyRequest $request, Objective $objective, string $item, AddDependency $addDependency): RedirectResponse
    {
        $prerequisite = $this->resolve($objective, $item);

        $addDependency(Actor::ownerWeb($request->user()), $prerequisite, $this->other($request));

        return back();
    }

    public function destroyPrerequisite(Request $request, Objective $objective, string $item, string $prerequisite, RemoveDependency $removeDependency): RedirectResponse
    {
        $other = Item::resolveForOwner($request->user(), $prerequisite);
        abort_if($other === null, 404);

        $removeDependency(Actor::ownerWeb($request->user()), $other, $this->resolve($objective, $item));

        return back();
    }

    public function destroyUnlock(Request $request, Objective $objective, string $item, string $dependent, RemoveDependency $removeDependency): RedirectResponse
    {
        $other = Item::resolveForOwner($request->user(), $dependent);
        abort_if($other === null, 404);

        $removeDependency(Actor::ownerWeb($request->user()), $this->resolve($objective, $item), $other);

        return back();
    }

    private function resolve(Objective $objective, string $key): Item
    {
        $item = Item::resolveByKey($objective, $key);

        abort_if($item === null, 404);

        return $item;
    }

    /**
     * The other end of the edge, by key, among the owner's visible items.
     *
     * @throws ValidationException
     */
    private function other(ItemDependencyRequest $request): Item
    {
        $other = Item::resolveForOwner($request->user(), strtoupper(trim((string) $request->validated('key'))));

        if ($other === null) {
            throw ValidationException::withMessages(['key' => 'No encontramos esa tarea o hito.']);
        }

        return $other;
    }
}
