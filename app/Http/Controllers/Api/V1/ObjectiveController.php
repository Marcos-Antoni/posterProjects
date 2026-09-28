<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ObjectiveResource;
use App\Http\Resources\ObjectiveTree;
use App\Models\Objective;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ObjectiveController extends Controller
{
    /**
     * The owner's ACTIVE objectives in manual order, unpaginated, each in the
     * pinned shape (api-projects spec). The 5-point plan is eager loaded and
     * progress comes from eager aggregates, so the query count is the same
     * for 1 or 50 objectives.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $objectives = $request->user()
            ->objectives()
            ->active()
            ->withProgress()
            ->with('controlPlan')
            ->get();

        return ObjectiveResource::collection($objectives);
    }

    /**
     * One objective (active or closed) by key, in the pinned shape plus its
     * non-retired plans and items. `{objective}` is resolved by the shared
     * binder: unknown, foreign, draft and retired keys are one generic 404.
     */
    public function show(Request $request, Objective $objective): JsonResponse
    {
        $objective = Objective::query()
            ->whereKey($objective->id)
            ->withProgress()
            ->with('controlPlan')
            ->firstOrFail();

        $plans = collect(ObjectiveTree::plans($objective))->map(fn (array $plan): array => [
            'id' => $plan['id'],
            'title' => $plan['title'],
            'state' => $plan['state'],
            'level' => $plan['level'],
            'items' => collect($plan['items'])->map(fn (array $item): array => [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'title' => $item['title'],
                'two_minute_version' => $item['two_minute_version'],
                'state' => $item['state'],
                'prerequisite_keys' => $item['prerequisite_keys'],
            ])->all(),
        ])->all();

        return response()->json([
            'data' => [
                ...(new ObjectiveResource($objective))->resolve($request),
                'plans' => $plans,
            ],
        ]);
    }
}
