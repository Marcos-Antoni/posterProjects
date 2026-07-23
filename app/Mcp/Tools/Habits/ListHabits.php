<?php

namespace App\Mcp\Tools\Habits;

use App\Mcp\Support\ResolvesAuthenticatedUser;
use App\Mcp\Support\ResourceLinker;
use App\Models\Habit;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List every habit owned by the authenticated user, active and archived alike — same data as the habit management page. Use today-habits to see only what is scheduled today.')]
class ListHabits extends Tool
{
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
            ->orderBy('name')
            ->get();

        return Response::json([
            'habits' => $habits->map(fn (Habit $habit): array => [
                'id' => $habit->id,
                'name' => $habit->name,
                'habit_type' => $habit->habit_type,
                'unit' => $habit->unit,
                'daily_target' => $habit->daily_target,
                'recurrence_type' => $habit->recurrence_type,
                'weekdays' => $habit->weekdays,
                'times_per_week' => $habit->times_per_week,
                'planned_time' => $habit->planned_time,
                'archived_at' => $habit->archived_at?->toIso8601String(),
                'url' => $this->links->habit($habit),
            ])->all(),
        ]);
    }
}
