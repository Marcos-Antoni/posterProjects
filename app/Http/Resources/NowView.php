<?php

namespace App\Http\Resources;

use App\Actions\Support\LastActivity;
use App\Actions\Support\WeeklyMainPriority;
use App\Enums\ItemKind;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Now read model (now-focus, design D6): the ONE item the Now screen,
 * MCP `now-view` and (Phase 11) `GET /api/v1/now` show. Selection order:
 *
 *  1. the owner's active item;
 *  2. else the first available item of the current weekly main priority
 *     (an objective or a plan), by plan position then item position;
 *  3. else the oldest available item (creation order) of the first active
 *     objective, by the owner's manual order, that has one.
 *
 * Only active objectives count, and items of a draft plan (a level that is
 * not open yet) or a retired plan are never suggested. A capture is never
 * an item, so it can never be the Now task (capture-inbox spec).
 */
class NowView
{
    public function __construct(
        private WeeklyMainPriority $weeklyPriority,
        private LastActivity $lastActivity,
    ) {}

    /**
     * The active item, else the suggested one, else null. The returned item
     * has its `objective` and `plan` relations loaded.
     */
    public function select(User $owner): ?Item
    {
        // Whatever made it active, the active item IS the Now task: it is
        // never hidden behind a suggestion (only retired/done are excluded).
        $active = Item::query()
            ->where('items.user_id', $owner->id)
            ->where('items.is_active', true)
            ->whereNull('items.completed_at')
            ->whereNull('items.retired_at')
            ->with(['objective', 'plan'])
            ->first();

        if ($active !== null) {
            return $active;
        }

        $priority = $this->weeklyPriority->currentFor($owner);

        if ($priority !== null) {
            $suggested = $this->candidates($owner)
                ->available()
                ->when(
                    $priority instanceof Objective,
                    fn (Builder $query) => $query->where('items.objective_id', $priority->id),
                    fn (Builder $query) => $query->where('items.plan_id', $priority->id),
                )
                ->orderBy('plans.position')
                ->orderBy('plans.id')
                ->orderBy('items.position')
                ->orderBy('items.id')
                ->first();

            if ($suggested !== null) {
                return $suggested;
            }
        }

        return $this->candidates($owner)
            ->available()
            ->orderBy('objectives.position')
            ->orderBy('objectives.id')
            ->orderBy('items.created_at')
            ->orderBy('items.id')
            ->first();
    }

    /**
     * The Now task as every surface shows it (web Now screen, MCP
     * `now-view`, and the Phase 11 `/api/v1/now` base shape), or null when
     * nothing is available. `unlocks` are the items completing it opens;
     * `prerequisite` is the latest done prerequisite (the station before it
     * on the mini-map); `milestone` is the nearest open milestone it leads
     * to (the "Hito:" context). `focus_started_at` is the open focus
     * session's start (UTC), null for a suggestion.
     *
     * @return array{key: string, kind: string, title: string, description: string|null, two_minute_version: string, is_active: bool, focus_started_at: string|null, objective: array{key: string, title: string}, plan: array{id: int, title: string}, milestone: array{key: string, title: string}|null, prerequisite: array{key: string, title: string, state: string}|null, unlocks: list<array{key: string, title: string, state: string}>, url: string}|null
     */
    public function present(User $owner): ?array
    {
        $item = $this->select($owner);

        if ($item === null) {
            return null;
        }

        ItemDetails::load($item);

        $prerequisite = $item->prerequisites
            ->whereNull('retired_at')
            ->whereNotNull('completed_at')
            ->sortByDesc(fn (Item $candidate): string => (string) $candidate->completed_at?->toIso8601String())
            ->first();

        $focusStartedAt = $item->is_active
            ? FocusSession::query()->where('item_id', $item->id)->whereNull('ended_at')->latest('started_at')->value('started_at')
            : null;

        return [
            'key' => $item->key,
            'kind' => $item->kind->value,
            'title' => $item->title,
            'description' => $item->description,
            'two_minute_version' => $item->two_minute_version,
            'is_active' => $item->is_active,
            'focus_started_at' => $focusStartedAt === null ? null : Carbon::parse($focusStartedAt)->utc()->toIso8601String(),
            'objective' => ['key' => $item->objective->key, 'title' => $item->objective->title],
            'plan' => ['id' => $item->plan->id, 'title' => $item->plan->title],
            'milestone' => $item->kind === ItemKind::Milestone ? null : $this->nearestMilestone($item),
            'prerequisite' => $prerequisite === null ? null : ItemDetails::neighbours(collect([$prerequisite]))[0],
            'unlocks' => ItemDetails::neighbours($item->dependents->whereNull('retired_at')),
            'url' => route('objectives.items.show', [$item->objective->key, $item->key]),
        ];
    }

    /**
     * The nearest open milestone downstream of the item (fewest unlock
     * steps, then plan order), walking the dependency graph with a
     * recursive CTE (cycles are impossible: AddDependency refuses them;
     * the depth cap is a belt).
     *
     * @return array{key: string, title: string}|null
     */
    private function nearestMilestone(Item $item): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            WITH RECURSIVE downstream(id, depth) AS (
                SELECT dependent_id, 1 FROM item_dependencies WHERE prerequisite_id = ?
                UNION
                SELECT edges.dependent_id, downstream.depth + 1
                FROM item_dependencies AS edges
                INNER JOIN downstream ON edges.prerequisite_id = downstream.id
                WHERE downstream.depth < 64
            )
            SELECT milestones.number, milestones.title, milestone_objectives.key AS objective_key
            FROM downstream
            INNER JOIN items AS milestones ON milestones.id = downstream.id
            INNER JOIN objectives AS milestone_objectives ON milestone_objectives.id = milestones.objective_id
            WHERE milestones.kind = 'milestone'
                AND milestones.completed_at IS NULL
                AND milestones.retired_at IS NULL
            ORDER BY downstream.depth, milestones.position, milestones.id
            LIMIT 1
            SQL, [$item->id]);

        if ($row === null) {
            return null;
        }

        return ['key' => sprintf('%s-%d', $row->objective_key, $row->number), 'title' => $row->title];
    }

    /**
     * "Retomar" (now-focus "No Punishment…", design D14): true when the
     * owner has done something before (a check, a habit log or a started
     * task) but nothing yesterday or today, on the UTC-6 calendar. It never
     * says how many days passed.
     */
    public function needsRestart(User $owner): bool
    {
        $timezone = (string) config('habits.timezone');
        $yesterdayStart = now()->setTimezone($timezone)->startOfDay()->subDay()->utc();
        $last = $this->lastActivity->lastActivityAt($owner);

        return $last !== null && $last->lt($yesterdayStart);
    }

    /**
     * The owner's non-retired items of active objectives, outside draft and
     * retired plans.
     *
     * @return Builder<Item>
     */
    private function candidates(User $owner): Builder
    {
        $today = Habit::todayLocalDate()->toDateString();

        return Item::query()
            ->select('items.*')
            ->join('objectives', 'objectives.id', '=', 'items.objective_id')
            ->join('plans', 'plans.id', '=', 'items.plan_id')
            ->where('items.user_id', $owner->id)
            ->where('objectives.user_id', $owner->id)
            ->where('objectives.state', ObjectiveState::Active->value)
            ->whereNotIn('plans.state', [PlanState::Draft->value, PlanState::Retired->value])
            ->whereNull('items.retired_at')
            ->whereNotExists(fn ($dismissed) => $dismissed->selectRaw('1')
                ->from('now_dismissals')
                ->whereColumn('now_dismissals.item_id', 'items.id')
                ->where('now_dismissals.user_id', $owner->id)
                ->where('now_dismissals.local_date', $today))
            ->with(['objective', 'plan']);
    }
}
