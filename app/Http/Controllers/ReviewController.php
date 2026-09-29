<?php

namespace App\Http\Controllers;

use App\Actions\Reviews\SubmitWeeklyReview;
use App\Actions\Support\Actor;
use App\Enums\ItemKind;
use App\Enums\ObjectiveState;
use App\Http\Requests\Reviews\SubmitWeeklyReviewRequest;
use App\Models\Habit;
use App\Models\Habits\IdentityVoteGroup;
use App\Models\Habits\IdentityVotes;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly review (screen 14) and the reviews history (screen 15):
 * progress first, then the two questions and next week's priority
 * (reviews spec). Skipping the review blocks nothing else in the app.
 */
class ReviewController extends Controller
{
    public function weekly(Request $request, IdentityVotes $identityVotes): Response
    {
        $owner = $request->user();
        $since = now()->subDays(7);

        $itemsDone = Item::query()
            ->where('user_id', $owner->id)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->get(['kind', 'completed_at']);

        $habits = $owner->habits()->with(['days', 'schedulePeriods'])->orderBy('id')->get();

        return Inertia::render('reviews/weekly', [
            'progress' => [
                'items_done' => $itemsDone->count(),
                'milestones_done' => $itemsDone->where('kind', ItemKind::Milestone)->count(),
                'habits' => $habits->map(fn (Habit $habit): array => [
                    'name' => $habit->name,
                    'streak_state' => $habit->history()->streak()->state->value,
                    'current_streak' => $habit->history()->streak()->current,
                ])->all(),
                'identity' => array_map(fn (IdentityVoteGroup $group): array => [
                    'statement' => $group->statement,
                    'cast' => $group->last7->cast,
                    'possible' => $group->last7->possible,
                ], $identityVotes->forUser($owner)),
            ],
            'main_candidates' => $this->mainCandidates($owner),
            'maintenance_candidates' => $this->maintenanceCandidates($owner),
        ]);
    }

    public function storeWeekly(SubmitWeeklyReviewRequest $request, SubmitWeeklyReview $submit): RedirectResponse
    {
        $submit(Actor::ownerWeb($request->user()), $request->validated());

        return redirect()->route('reviews.index');
    }

    public function index(Request $request): Response
    {
        $reviews = Review::query()
            ->where('user_id', $request->user()->id)
            ->with('objective')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('reviews/index', [
            'reviews' => $reviews->map(fn (Review $review): array => [
                'id' => $review->id,
                'kind' => $review->kind->value,
                'kind_label' => $review->kind->label(),
                'objective_title' => $review->objective?->title,
                'answers' => $review->answers,
                'created_at' => $review->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * @return list<array{type: string, id: int, label: string}>
     */
    private function mainCandidates(User $owner): array
    {
        $objectives = Objective::query()->where('user_id', $owner->id)->where('state', ObjectiveState::Active->value)->orderBy('position')->get();

        $candidates = $objectives->map(fn (Objective $objective): array => [
            'type' => 'objective',
            'id' => $objective->id,
            'label' => $objective->title,
        ])->all();

        foreach ($objectives as $objective) {
            foreach ($objective->plans as $plan) {
                $candidates[] = ['type' => 'plan', 'id' => $plan->id, 'label' => "{$objective->title} · {$plan->title}"];
            }
        }

        return array_values($candidates);
    }

    /**
     * @return list<array{type: string, id: int, label: string}>
     */
    private function maintenanceCandidates(User $owner): array
    {
        $objectives = Objective::query()->where('user_id', $owner->id)->where('state', ObjectiveState::Active->value)->orderBy('position')->get();
        $habits = $owner->habits()->orderBy('id')->get();

        return [
            ...$objectives->map(fn (Objective $objective): array => ['type' => 'objective', 'id' => $objective->id, 'label' => $objective->title])->all(),
            ...$habits->map(fn (Habit $habit): array => ['type' => 'habit', 'id' => $habit->id, 'label' => $habit->name])->all(),
        ];
    }
}
