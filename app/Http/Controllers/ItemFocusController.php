<?php

namespace App\Http\Controllers;

use App\Actions\Items\ShrinkTwoMinuteVersion;
use App\Actions\Items\StartItem;
use App\Actions\Items\StopItem;
use App\Actions\Support\Actor;
use App\Http\Requests\ShrinkTwoMinuteVersionRequest;
use App\Models\Item;
use App\Models\Objective;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Execution on an item from the Now screen (now-focus): start it (the one
 * active task), stop it for today, and the "estoy trabado" fallback that
 * replaces its 2-minute version. `{item}` is the public key, resolved like
 * every item route: anything malformed, foreign or retired is a 404.
 */
class ItemFocusController extends Controller
{
    public function start(Request $request, Objective $objective, string $item, StartItem $startItem): RedirectResponse
    {
        $startItem(Actor::ownerWeb($request->user()), $this->resolve($objective, $item));

        return back();
    }

    public function stop(Request $request, Objective $objective, string $item, StopItem $stopItem): RedirectResponse
    {
        $stopItem(Actor::ownerWeb($request->user()), $this->resolve($objective, $item));

        return back();
    }

    public function shrink(ShrinkTwoMinuteVersionRequest $request, Objective $objective, string $item, ShrinkTwoMinuteVersion $shrink): RedirectResponse
    {
        $shrink(Actor::ownerWeb($request->user()), $this->resolve($objective, $item), $request->validated('two_minute_version'));

        return back();
    }

    private function resolve(Objective $objective, string $key): Item
    {
        $item = Item::resolveByKey($objective, $key);

        abort_if($item === null, 404);

        return $item;
    }
}
