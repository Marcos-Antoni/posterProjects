<?php

namespace App\Http\Controllers;

use App\Actions\ControlMap\AddControlMapEntry;
use App\Actions\ControlMap\ConvertControlMapEntry;
use App\Actions\ControlMap\RemoveControlMapEntry;
use App\Actions\ControlMap\UpdateControlMapEntry;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Enums\PlanState;
use App\Http\Requests\ConvertControlMapEntryRequest;
use App\Http\Requests\StoreControlMapEntryRequest;
use App\Http\Requests\UpdateControlMapEntryRequest;
use App\Models\ControlMapEntry;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Control map CRUD for objectives and plans (control-plan spec), and the
 * conversion of an entry the owner controls into a task. `{entry}` is always
 * looked up inside the objective or plan of the URL, so an entry of another
 * objective is a 404.
 */
class ControlMapEntryController extends Controller
{
    public function store(StoreControlMapEntryRequest $request, Objective $objective, AddControlMapEntry $addEntry): RedirectResponse
    {
        $addEntry(Actor::ownerWeb($request->user()), $objective, ControlZone::from($request->validated('zone')), $request->validated('text'));

        return back();
    }

    public function update(UpdateControlMapEntryRequest $request, Objective $objective, string $entry, UpdateControlMapEntry $updateEntry): RedirectResponse
    {
        $updateEntry(Actor::ownerWeb($request->user()), $this->find($objective->controlMapEntries(), $entry), $request->validated());

        return back();
    }

    public function destroy(Request $request, Objective $objective, string $entry, RemoveControlMapEntry $removeEntry): RedirectResponse
    {
        $removeEntry(Actor::ownerWeb($request->user()), $this->find($objective->controlMapEntries(), $entry));

        return back();
    }

    /**
     * Turn an entry into a task of one of the objective's (non-retired)
     * plans. An outside-control entry is refused by the action.
     */
    public function convert(ConvertControlMapEntryRequest $request, Objective $objective, string $entry, ConvertControlMapEntry $convertEntry): RedirectResponse
    {
        $plan = $objective->plans()
            ->where('state', '!=', PlanState::Retired->value)
            ->whereKey($request->validated('plan_id'))
            ->first();

        if ($plan === null) {
            return back()->withErrors(['plan_id' => 'Elegí un plan de este objetivo.']);
        }

        $item = $convertEntry(Actor::ownerWeb($request->user()), $this->find($objective->controlMapEntries(), $entry), $plan, $request->validated());

        return redirect()->route('objectives.items.show', [$objective->key, $item->key]);
    }

    public function storeForPlan(StoreControlMapEntryRequest $request, Objective $objective, Plan $plan, AddControlMapEntry $addEntry): RedirectResponse
    {
        $addEntry(Actor::ownerWeb($request->user()), $plan, ControlZone::from($request->validated('zone')), $request->validated('text'));

        return back();
    }

    public function updateForPlan(UpdateControlMapEntryRequest $request, Objective $objective, Plan $plan, string $entry, UpdateControlMapEntry $updateEntry): RedirectResponse
    {
        $updateEntry(Actor::ownerWeb($request->user()), $this->find($plan->controlMapEntries(), $entry), $request->validated());

        return back();
    }

    public function destroyForPlan(Request $request, Objective $objective, Plan $plan, string $entry, RemoveControlMapEntry $removeEntry): RedirectResponse
    {
        $removeEntry(Actor::ownerWeb($request->user()), $this->find($plan->controlMapEntries(), $entry));

        return back();
    }

    /**
     * @param  MorphMany<ControlMapEntry, Objective>|MorphMany<ControlMapEntry, Plan>  $entries
     */
    private function find(MorphMany $entries, string $entry): ControlMapEntry
    {
        abort_unless(ctype_digit($entry), 404);

        return $entries->whereKey((int) $entry)->firstOrFail();
    }
}
