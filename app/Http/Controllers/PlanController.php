<?php

namespace App\Http\Controllers;

use App\Actions\Plans\ActivatePlan;
use App\Actions\Plans\CreatePlan;
use App\Actions\Plans\MovePlan;
use App\Actions\Plans\UpdatePlan;
use App\Actions\Support\Actor;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\ObjectiveTree;
use App\Models\ControlMapEntry;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan detail and form (screen 6): the plan's items in manual order, the
 * add-item form with its 2-minute version, and the plan's own 5-point plan.
 */
class PlanController extends Controller
{
    public function create(Request $request, Objective $objective): Response
    {
        $level = $request->integer('level');

        return Inertia::render('plans/form', [
            'objective' => $this->presentObjective($objective),
            'plan' => null,
            'suggestedLevel' => $level >= 1 && $level <= 99 ? $level : $this->suggestedLevel($objective),
        ]);
    }

    public function store(StorePlanRequest $request, Objective $objective, CreatePlan $createPlan): RedirectResponse
    {
        $plan = $createPlan(Actor::ownerWeb($request->user()), $objective, $request->validated());

        return redirect()->route('objectives.plans.show', [$objective->key, $plan->id]);
    }

    public function show(Request $request, Objective $objective, Plan $plan): Response
    {
        $plan->load('controlPlan');

        $tree = collect(ObjectiveTree::plans($objective));

        return Inertia::render('plans/show', [
            'objective' => $this->presentObjective($objective),
            'plan' => [
                ...$this->presentPlan($plan),
                ...collect($tree->firstWhere('id', $plan->id))->only(['progress', 'next_milestone', 'retired_titles', 'items'])->all(),
            ],
            'controlPlan' => ObjectiveTree::controlPlan($plan->controlPlan),
            'ladder' => $tree->filter(fn (array $other): bool => $other['level'] !== null)
                ->sortBy('level')
                ->values()
                ->map(fn (array $other): array => [
                    'id' => $other['id'],
                    'title' => $other['title'],
                    'level' => $other['level'],
                    'state' => $other['state'],
                    'progress' => $other['progress'],
                ])->all(),
            'nextNumber' => $objective->fresh()?->next_item_number,
            'prerequisiteCandidates' => $this->prerequisiteCandidates($request, $plan),
        ]);
    }

    public function edit(Objective $objective, Plan $plan): Response
    {
        $plan->load(['controlPlan', 'controlMapEntries']);

        return Inertia::render('plans/form', [
            'objective' => $this->presentObjective($objective),
            'plan' => [
                ...$this->presentPlan($plan),
                'control_plan' => ObjectiveTree::controlPlan($plan->controlPlan),
                'control_map' => $plan->controlMapEntries->map(fn (ControlMapEntry $entry): array => [
                    'id' => $entry->id,
                    'zone' => $entry->zone->value,
                    'text' => $entry->text,
                    'can_become_task' => $entry->zone->canBecomeTask(),
                ])->values()->all(),
            ],
            'suggestedLevel' => null,
        ]);
    }

    public function update(UpdatePlanRequest $request, Objective $objective, Plan $plan, UpdatePlan $updatePlan): RedirectResponse
    {
        $updatePlan(Actor::ownerWeb($request->user()), $plan, $request->validated());

        return back();
    }

    public function activate(Request $request, Objective $objective, Plan $plan, ActivatePlan $activatePlan): RedirectResponse
    {
        $activatePlan(Actor::ownerWeb($request->user()), $plan);

        return redirect()->route('objectives.plans.show', [$objective->key, $plan->id]);
    }

    public function move(Request $request, Objective $objective, Plan $plan, MovePlan $movePlan): RedirectResponse
    {
        $validated = $request->validate(
            ['direction' => ['required', 'in:up,down']],
            ['direction.required' => 'Elegí subir o bajar.', 'direction.in' => 'Elegí subir o bajar.'],
        );

        $movePlan(Actor::ownerWeb($request->user()), $plan, $validated['direction'] === 'up' ? -1 : 1);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentObjective(Objective $objective): array
    {
        return [
            'key' => $objective->key,
            'title' => $objective->title,
            'state' => $objective->state->value,
            'is_writable' => $objective->state === ObjectiveState::Active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'state' => $plan->state->value,
            'level' => $plan->level,
        ];
    }

    /**
     * The next free rung of the objective's level ladder, offered on a new
     * plan (plans may be progression levels, control-plan spec).
     */
    private function suggestedLevel(Objective $objective): ?int
    {
        $max = $objective->plans()->reorder()->where('state', '!=', PlanState::Retired->value)->max('level');

        return $max === null ? null : ((int) $max) + 1;
    }

    /**
     * Open items of any of the owner's active objectives, for the "Se abre al
     * terminar" picker (dependencies may cross objectives).
     *
     * @return list<array{id: int, key: string, title: string, objective_title: string, same_objective: bool}>
     */
    private function prerequisiteCandidates(Request $request, Plan $plan): array
    {
        return array_values(Item::query()
            ->with('objective:id,key,title')
            ->whereHas('objective', fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->where('state', ObjectiveState::Active->value))
            ->whereNull('retired_at')
            ->whereNull('completed_at')
            ->orderBy('objective_id')
            ->orderBy('number')
            ->limit(500)
            ->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'key' => $item->key,
                'title' => $item->title,
                'objective_title' => $item->objective->title,
                'same_objective' => $item->objective_id === $plan->objective_id,
            ])->all());
    }
}
