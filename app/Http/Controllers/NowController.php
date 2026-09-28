<?php

namespace App\Http\Controllers;

use App\Actions\Items\DismissItemForToday;
use App\Actions\Items\StopItem;
use App\Actions\Support\Actor;
use App\Actions\Support\WeeklyMainPriority;
use App\Http\Resources\NowHabits;
use App\Http\Resources\NowView;
use App\Models\Habit;
use App\Models\Item;
use App\Models\NowDismissal;
use App\Models\Objective;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 2, "Ahora" (now-focus): the single entry point of Marcos OS. One
 * task — the active one or ONE suggestion — with its 2-minute version first,
 * what it unlocks, today's habit checks, the weekly priority it comes from,
 * and the clock the silent 25-minute cue is computed from (server time plus
 * the persisted focus start; design D7). No list of tasks, feed or counter.
 */
class NowController extends Controller
{
    public function show(Request $request, NowView $nowView, WeeklyMainPriority $weeklyPriority): Response
    {
        $owner = $request->user();
        $priority = $weeklyPriority->currentFor($owner);
        $priorityObjective = $priority instanceof Objective ? $priority : $priority?->objective;

        return Inertia::render('now', [
            'now' => $nowView->present($owner),
            'restart' => $nowView->needsRestart($owner),
            'habits' => NowHabits::forOwner($owner),
            'priority' => $priority === null || $priorityObjective === null ? null : [
                'title' => $priority->title,
                'url' => route('objectives.show', $priorityObjective->key, absolute: false),
            ],
            'closed_today' => NowDismissal::anyToday($owner),
            'today' => Habit::todayLocalDate()->toDateString(),
            'server_now' => now()->utc()->toIso8601String(),
            'cue' => [
                'minutes' => (int) config('marcos.focus_cue_minutes', 25),
                'visible_seconds' => (int) config('marcos.focus_cue_visible_seconds', 60),
            ],
        ]);
    }

    /**
     * "Cerrar por hoy" on the item the owner was looking at (`item`, its
     * public key, sent by the client): an active one is stopped and both are
     * dismissed for the rest of the UTC-6 day; a done one (the unlock moment)
     * is recorded only to mark the day closed — the newly unlocked task is
     * never dismissed.
     * Without `item`, the current Now item is used. A foreign, malformed or
     * retired key is a 404.
     */
    public function closeForToday(Request $request, NowView $nowView, StopItem $stopItem, DismissItemForToday $dismiss): RedirectResponse
    {
        $validated = $request->validate(
            ['item' => ['nullable', 'string', 'max:40']],
            ['item.string' => 'La tarea no es válida.', 'item.max' => 'La tarea no es válida.'],
        );
        $actor = Actor::ownerWeb($request->user());

        if (filled($validated['item'] ?? null)) {
            $item = Item::resolveForOwner($actor->user, $validated['item']);

            abort_if($item === null, 404);
        } else {
            $item = $nowView->select($actor->user);
        }

        // A done item is recorded too: it marks the day as closed (the Now
        // screen stays on "Listo por hoy" after a reload) without touching
        // the task it just unlocked.
        if ($item !== null) {
            if ($item->is_active && $item->completed_at === null) {
                $stopItem($actor, $item);
            }

            $dismiss($actor, $item);
        }

        return back();
    }
}
