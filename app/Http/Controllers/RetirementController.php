<?php

namespace App\Http\Controllers;

use App\Actions\Retirement\RetireElement;
use App\Actions\Retirement\RetirementResult;
use App\Actions\Support\Actor;
use App\Http\Requests\RetireElementRequest;
use App\Http\Resources\RetireContext;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The retire flow (screen 23) for every kind: a GET that feeds the dialog
 * (`RetireContext`, JSON, loaded when it opens) and a POST that retires
 * through `RetireElement`. Every "remove" affordance of the product lands
 * here; there is no delete route anywhere (retirement spec).
 */
class RetirementController extends Controller
{
    public function itemContext(Request $request, Objective $objective, string $item, RetireContext $context): JsonResponse
    {
        return response()->json($context->for($request->user(), $this->item($objective, $item)));
    }

    public function retireItem(RetireElementRequest $request, Objective $objective, string $item, RetireElement $retire): RedirectResponse
    {
        $resolved = $this->item($objective, $item);
        $result = $this->retire($request, $resolved, $retire);

        return redirect()->route('objectives.plans.show', [$objective->key, $resolved->plan_id])
            ->with('unlocked', $result->unlocked->map(fn (Item $unlocked): array => ['key' => $unlocked->key, 'title' => $unlocked->title])->all());
    }

    public function planContext(Request $request, Objective $objective, Plan $plan, RetireContext $context): JsonResponse
    {
        return response()->json($context->for($request->user(), $plan));
    }

    public function retirePlan(RetireElementRequest $request, Objective $objective, Plan $plan, RetireElement $retire): RedirectResponse
    {
        $this->retire($request, $plan, $retire);

        return redirect()->route('objectives.show', $objective->key);
    }

    public function objectiveContext(Request $request, Objective $objective, RetireContext $context): JsonResponse
    {
        return response()->json($context->for($request->user(), $objective));
    }

    public function retireObjective(RetireElementRequest $request, Objective $objective, RetireElement $retire): RedirectResponse
    {
        $this->retire($request, $objective, $retire);

        return redirect()->route('objectives.index');
    }

    public function habitContext(Request $request, Habit $habit, RetireContext $context): JsonResponse
    {
        abort_unless($habit->user_id === $request->user()->id, 404);

        return response()->json($context->for($request->user(), $habit));
    }

    public function retireHabit(RetireElementRequest $request, Habit $habit, RetireElement $retire): RedirectResponse
    {
        $this->retire($request, $habit, $retire);

        return redirect()->route('habits.index');
    }

    private function retire(RetireElementRequest $request, Model $element, RetireElement $retire): RetirementResult
    {
        $result = $retire(
            Actor::ownerWeb($request->user()),
            $element,
            (string) $request->validated('reason'),
            $request->decision(),
            $request->payload(),
        );

        $kind = $result->retirement->kind;

        Inertia::flash('retirement', [
            'message' => $kind->participle('Retirad').'. '.($kind->isFeminine() ? 'La' : 'Lo').' vas a ver en Retirados, con tu razón.',
            'url' => route('retired.index', absolute: false),
        ]);

        return $result;
    }

    private function item(Objective $objective, string $key): Item
    {
        $item = Item::resolveByKey($objective, $key);

        abort_if($item === null, 404);

        return $item;
    }
}
