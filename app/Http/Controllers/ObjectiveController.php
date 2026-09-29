<?php

namespace App\Http\Controllers;

use App\Actions\Objectives\ActivateObjective;
use App\Actions\Objectives\CloseObjective;
use App\Actions\Objectives\CreateObjective;
use App\Actions\Objectives\ReopenObjective;
use App\Actions\Objectives\UpdateObjective;
use App\Actions\Support\Actor;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Http\Requests\Objectives\CloseObjectiveRequest;
use App\Http\Requests\StoreObjectiveRequest;
use App\Http\Requests\UpdateObjectiveRequest;
use App\Http\Resources\ObjectiveTree;
use App\Models\ControlMapEntry;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Objectives index, tree, form and lifecycle (screens 3–5). Every mutation
 * goes through the domain actions with an `owner-web` actor.
 */
class ObjectiveController extends Controller
{
    /**
     * The owner's ACTIVE objectives in manual order, each with its metric and
     * the next concrete step (screen 3). Items of all objectives are read in
     * one query and grouped in memory.
     */
    public function index(Request $request): Response
    {
        $objectives = $request->user()->objectives()->active()->with('controlPlan')->get();

        $items = Item::query()
            ->withState()
            ->join('plans', 'plans.id', '=', 'items.plan_id')
            ->whereIn('items.objective_id', $objectives->pluck('id'))
            ->whereNull('items.retired_at')
            ->where('plans.state', '!=', PlanState::Retired->value)
            ->orderBy('plans.position')
            ->orderBy('items.position')
            ->orderBy('items.id')
            ->get()
            ->groupBy('objective_id');

        $locked = $items->flatten(1)->filter(fn (Item $item): bool => $item->state === ItemState::Locked);
        $prerequisites = ObjectiveTree::prerequisitesOf(array_values($locked->map(fn (Item $item): int => $item->id)->all()));

        return Inertia::render('objectives/index', [
            'objectives' => $objectives->values()->map(function (Objective $objective, int $index) use ($items, $prerequisites): array {
                /** @var Collection<int, Item> $objectiveItems */
                $objectiveItems = $items->get($objective->id, new Collection)
                    ->each(fn (Item $item) => $item->setRelation('objective', $objective));

                return [
                    'key' => $objective->key,
                    'title' => $objective->title,
                    'identity_statement' => $objective->identity_statement,
                    'line' => ($index % 3) + 1,
                    'control_plan' => ObjectiveTree::controlPlan($objective->controlPlan),
                    'progress' => [
                        'done' => $objectiveItems->where('state', ItemState::Done)->count(),
                        'total' => $objectiveItems->count(),
                    ],
                    'last_done' => $objectiveItems->where('state', ItemState::Done)->sortByDesc('completed_at')->first()?->title,
                    'next_milestone' => $this->nextMilestone($objectiveItems),
                    'next' => $this->nextStep($objectiveItems, $prerequisites),
                ];
            })->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('objectives/form', [
            'objective' => null,
        ]);
    }

    public function store(StoreObjectiveRequest $request, CreateObjective $createObjective): RedirectResponse
    {
        $objective = $createObjective(Actor::ownerWeb($request->user()), $request->validated());

        return redirect()->route('objectives.show', $objective->key);
    }

    /**
     * The whole tree of one objective (screen 4): 5-point plan, control map
     * and plans with their items; retired descendants hidden.
     */
    public function show(Objective $objective): Response
    {
        $objective->load(['controlPlan', 'controlMapEntries']);

        $plans = ObjectiveTree::plans($objective);

        return Inertia::render('objectives/show', [
            'objective' => $this->presentObjective($objective),
            'controlPlan' => ObjectiveTree::controlPlan($objective->controlPlan),
            'controlMap' => $this->presentControlMap($objective->controlMapEntries),
            'plans' => $plans,
            'levelSuggestion' => $this->levelSuggestion($plans),
        ]);
    }

    public function edit(Objective $objective): Response
    {
        $objective->load(['controlPlan', 'controlMapEntries']);

        return Inertia::render('objectives/form', [
            'objective' => [
                ...$this->presentObjective($objective),
                'control_plan' => ObjectiveTree::controlPlan($objective->controlPlan),
                'control_map' => $this->presentControlMap($objective->controlMapEntries),
            ],
        ]);
    }

    public function update(UpdateObjectiveRequest $request, Objective $objective, UpdateObjective $updateObjective): RedirectResponse
    {
        $updateObjective(Actor::ownerWeb($request->user()), $objective, $request->validated());

        return back();
    }

    public function activate(Request $request, Objective $objective, ActivateObjective $activateObjective): RedirectResponse
    {
        $activateObjective(Actor::ownerWeb($request->user()), $objective);

        return redirect()->route('objectives.show', $objective->key);
    }

    public function reopen(Request $request, Objective $objective, ReopenObjective $reopenObjective): RedirectResponse
    {
        $reopenObjective(Actor::ownerWeb($request->user()), $objective);

        return redirect()->route('objectives.show', $objective->key);
    }

    /**
     * Screen 13: the learning review form, with the objective's progress and
     * every habit linked to it (with its current streak) so the owner
     * decides keep/retire with the full picture, not by impulse.
     */
    public function closeShow(Objective $objective): Response
    {
        $objective->load('controlPlan');

        $items = Item::query()->withState()->where('objective_id', $objective->id)->get();

        return Inertia::render('objectives/close', [
            'objective' => $this->presentObjective($objective),
            'control_plan' => ObjectiveTree::controlPlan($objective->controlPlan),
            'progress' => [
                'done' => $items->where('state', ItemState::Done)->count(),
                'total' => $items->count(),
                'milestones_done' => $items->filter(fn (Item $item): bool => $item->kind === ItemKind::Milestone && $item->state === ItemState::Done)->count(),
            ],
            'habits' => $objective->habits()->get()->map(fn (Habit $habit): array => [
                'id' => $habit->id,
                'name' => $habit->name,
                'two_minute_version' => $habit->two_minute_version,
                'current_streak' => $habit->currentStreak(),
            ])->all(),
        ]);
    }

    public function close(CloseObjectiveRequest $request, Objective $objective, CloseObjective $closeObjective): RedirectResponse
    {
        $closeObjective(
            Actor::ownerWeb($request->user()),
            $objective,
            [
                'what_learned' => (string) $request->validated('what_learned'),
                'what_repeat' => (string) $request->validated('what_repeat'),
                'what_change' => (string) $request->validated('what_change'),
            ],
            $this->habitDecisions($request),
        );

        return redirect()->route('objectives.index');
    }

    /**
     * @return list<array{habit_id: int, decision: string, reason?: string|null, relink_objective_id?: int|null}>
     */
    private function habitDecisions(CloseObjectiveRequest $request): array
    {
        $decisions = [];

        foreach ((array) $request->validated('habits', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $decisions[] = [
                'habit_id' => (int) ($entry['habit_id'] ?? 0),
                'decision' => (string) ($entry['decision'] ?? ''),
                'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
                'relink_objective_id' => isset($entry['relink_objective_id']) ? (int) $entry['relink_objective_id'] : null,
            ];
        }

        return $decisions;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentObjective(Objective $objective): array
    {
        return [
            'key' => $objective->key,
            'title' => $objective->title,
            'identity_statement' => $objective->identity_statement,
            'state' => $objective->state->value,
            'is_writable' => $objective->state === ObjectiveState::Active,
        ];
    }

    /**
     * @param  Collection<int, ControlMapEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function presentControlMap(Collection $entries): array
    {
        return array_values($entries->map(fn (ControlMapEntry $entry): array => [
            'id' => $entry->id,
            'zone' => $entry->zone->value,
            'text' => $entry->text,
            'can_become_task' => $entry->zone->canBecomeTask(),
        ])->all());
    }

    /**
     * Goldilocks scaling (control-plan spec): suggest preparing the next
     * level once the current level's items are at least 80% done, never
     * activating it.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return array{from_title: string, next_level: int}|null
     */
    private function levelSuggestion(array $plans): ?array
    {
        $levels = collect($plans)->filter(fn (array $plan): bool => $plan['level'] !== null);

        foreach ($levels->where('state', PlanState::Active->value)->merge($levels->where('state', PlanState::Done->value)) as $plan) {
            $total = $plan['progress']['total'];
            $nextLevel = $plan['level'] + 1;
            $nextIsActive = $levels->contains(fn (array $other): bool => $other['level'] === $nextLevel
                && in_array($other['state'], [PlanState::Active->value, PlanState::Done->value], true));

            if ($total > 0 && $plan['progress']['done'] / $total >= 0.8 && ! $nextIsActive) {
                return ['from_title' => $plan['title'], 'next_level' => $nextLevel];
            }
        }

        return null;
    }

    /**
     * The next concrete step of an objective: its active item, else its first
     * available one, else the first locked one with what opens it.
     *
     * @param  Collection<int, Item>  $items
     * @param  Collection<array-key, Collection<int, stdClass>>  $prerequisites
     * @return array<string, mixed>|null
     */
    private function nextStep(Collection $items, Collection $prerequisites): ?array
    {
        $next = $items->first(fn (Item $item): bool => $item->state === ItemState::Active)
            ?? $items->first(fn (Item $item): bool => $item->state === ItemState::Available)
            ?? $items->first(fn (Item $item): bool => $item->state === ItemState::Locked);

        if ($next === null) {
            return null;
        }

        return ObjectiveTree::item($next, $prerequisites->get($next->id, new Collection));
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return array{title: string, remaining: int}|null
     */
    private function nextMilestone(Collection $items): ?array
    {
        $remaining = 0;

        foreach ($items as $item) {
            if ($item->state === ItemState::Done) {
                continue;
            }

            if ($item->kind->value === 'milestone') {
                return ['title' => $item->title, 'remaining' => $remaining];
            }

            $remaining++;
        }

        return null;
    }
}
