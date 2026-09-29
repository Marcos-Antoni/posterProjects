<?php

namespace App\Http\Controllers;

use App\Actions\Items\AddItem;
use App\Actions\Items\CheckItem;
use App\Actions\Items\MoveItem;
use App\Actions\Items\UncheckItem;
use App\Actions\Items\UpdateItem;
use App\Actions\Support\Actor;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Http\Requests\CheckItemRequest;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Resources\ItemDetails;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemTwoMinuteHistory;
use App\Models\Objective;
use App\Models\Plan;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Items (screen 7): the refresh-safe deep link `/objectives/{KEY}/items/{KEY-N}`
 * and every item mutation. `{item}` is resolved by `Item::resolveByKey()`, so a
 * malformed, mismatched, unknown or retired key is always the same 404.
 */
class ItemController extends Controller
{
    public function store(StoreItemRequest $request, Objective $objective, Plan $plan, AddItem $addItem): RedirectResponse
    {
        $addItem(Actor::ownerWeb($request->user()), $plan, $request->validated());

        return back();
    }

    public function show(Objective $objective, string $item): Response
    {
        $resolved = ItemDetails::load($this->resolve($objective, $item));

        $history = $resolved->twoMinuteHistory()->get();

        return Inertia::render('items/show', [
            'objective' => [
                'key' => $objective->key,
                'title' => $objective->title,
                'is_writable' => $objective->state === ObjectiveState::Active,
            ],
            'item' => [
                ...ItemDetails::present($resolved),
                'number' => $resolved->number,
                'created_at' => $resolved->created_at?->toIso8601String(),
                'plan_state' => $resolved->plan->state->value,
                'target_date_passed' => $resolved->target_date !== null
                    && $resolved->completed_at === null
                    && $resolved->target_date->toDateString() < Habit::todayLocalDate()->toDateString(),
            ],
            'twoMinuteVersions' => $this->versions($resolved, array_values($history->all())),
            'focus' => $this->focus($resolved),
            'plans' => $objective->plans()
                ->where('state', '!=', PlanState::Retired->value)
                ->get(['id', 'title'])
                ->map(fn (Plan $plan): array => ['id' => $plan->id, 'title' => $plan->title])
                ->all(),
        ]);
    }

    public function update(UpdateItemRequest $request, Objective $objective, string $item, UpdateItem $updateItem): RedirectResponse
    {
        $updateItem(Actor::ownerWeb($request->user()), $this->resolve($objective, $item), $request->validated());

        return back();
    }

    public function check(CheckItemRequest $request, Objective $objective, string $item, CheckItem $checkItem): RedirectResponse
    {
        $storedPath = $request->file('image')?->store('milestone-evidence', 'public');
        $imagePath = is_string($storedPath) ? $storedPath : null;

        $result = $checkItem(
            Actor::ownerWeb($request->user()),
            $this->resolve($objective, $item),
            $request->validated('evidence'),
            $request->validated('link'),
            $imagePath,
        );

        return back()->with('unlocked', $result->unlocked->map(fn (Item $unlocked): array => [
            'key' => $unlocked->key,
            'title' => $unlocked->title,
        ])->all());
    }

    public function uncheck(Request $request, Objective $objective, string $item, UncheckItem $uncheckItem): RedirectResponse
    {
        $uncheckItem(Actor::ownerWeb($request->user()), $this->resolve($objective, $item));

        return back();
    }

    public function move(Request $request, Objective $objective, string $item, MoveItem $moveItem): RedirectResponse
    {
        $validated = $request->validate(
            ['direction' => ['required', 'in:up,down']],
            ['direction.required' => 'Elegí subir o bajar.', 'direction.in' => 'Elegí subir o bajar.'],
        );

        $moveItem(Actor::ownerWeb($request->user()), $this->resolve($objective, $item), $validated['direction'] === 'up' ? -1 : 1);

        return back();
    }

    private function resolve(Objective $objective, string $key): Item
    {
        $item = Item::resolveByKey($objective, $key);

        abort_if($item === null, 404);

        return $item;
    }

    /**
     * Every 2-minute version, current first, each with who set it and when:
     * a history row holds a REPLACED version and who replaced it, so a
     * version was set by the replacement recorded just before it (or at the
     * item's creation for the oldest).
     *
     * @param  list<ItemTwoMinuteHistory>  $history  newest first
     * @return list<array{text: string, current: bool, source: string, at: string|null}>
     */
    private function versions(Item $item, array $history): array
    {
        $texts = [$item->two_minute_version, ...array_map(fn (ItemTwoMinuteHistory $row): string => $row->text, $history)];

        $versions = [];

        foreach ($texts as $index => $text) {
            $setBy = $history[$index] ?? null;

            $versions[] = [
                'text' => $text,
                'current' => $index === 0,
                'source' => $setBy === null ? 'created' : $setBy->source->value,
                'at' => $setBy === null ? $item->created_at?->toIso8601String() : $setBy->replaced_at->toIso8601String(),
            ];
        }

        return $versions;
    }

    /**
     * Minutes of focus today and yesterday (UTC-6 days) and the open
     * session's start, if any. No countdown: the clock only feeds the silent
     * 25-minute cue (design D7).
     *
     * @return array{today_minutes: int, yesterday_minutes: int, open_since: string|null}
     */
    private function focus(Item $item): array
    {
        $timezone = config('habits.timezone');
        $today = now()->setTimezone($timezone)->startOfDay();
        $yesterday = $today->clone()->subDay();

        $sessions = $item->focusSessions()->where('started_at', '>=', $yesterday->clone()->utc())->get();

        $minutesBetween = function (CarbonInterface $from, CarbonInterface $to) use ($sessions): int {
            return (int) round($sessions->sum(function (FocusSession $session) use ($from, $to): float {
                $start = $session->started_at->max($from);
                $end = ($session->ended_at ?? now())->min($to);

                return $end->gt($start) ? $start->diffInSeconds($end) / 60 : 0.0;
            }));
        };

        return [
            'today_minutes' => $minutesBetween($today, $today->clone()->addDay()),
            'yesterday_minutes' => $minutesBetween($yesterday, $today),
            'open_since' => $sessions->firstWhere('ended_at', null)?->started_at->toIso8601String(),
        ];
    }
}
