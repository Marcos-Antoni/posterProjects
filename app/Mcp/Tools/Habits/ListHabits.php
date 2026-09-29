<?php

namespace App\Mcp\Tools\Habits;

use App\Mcp\Support\PresentsHabits;
use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Nivel IA: read. List every non-retired habit of the authenticated user — same data as the web "Todos los hábitos" view: each one\'s 2-minute version, identity statement, the objective it hangs from, level ladder and tolerant streak (streak_current, streak_best, streak_state). Retired habits are hidden; they appear only in retired-view.')]
class ListHabits extends Tool
{
    use PresentsHabits;
    use ResolvesAuthenticatedUser;

    public function __construct(private ResourceLinker $links) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        Gate::forUser($user)->authorize('viewAny', Habit::class);

        $habits = $user->habits()
            ->with(['days', 'schedulePeriods', 'objective'])
            ->orderBy('name')
            ->get();

        $today = Habit::todayLocalDate();

        return Response::json([
            'habits' => $habits->map(fn (Habit $habit): array => $this->habitPayload($habit, $habit->history($today), $this->links))->all(),
        ]);
    }
}
