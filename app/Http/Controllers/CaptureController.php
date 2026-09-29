<?php

namespace App\Http\Controllers;

use App\Actions\Captures\ConvertCaptureToHabit;
use App\Actions\Captures\ConvertCaptureToItem;
use App\Actions\Captures\ConvertCaptureToObjectiveDraft;
use App\Actions\Captures\CreateCapture;
use App\Actions\Support\Actor;
use App\Enums\CaptureSource;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Http\Requests\Captures\ConvertCaptureToHabitRequest;
use App\Http\Requests\Captures\ConvertCaptureToItemRequest;
use App\Http\Requests\Captures\ConvertCaptureToObjectiveRequest;
use App\Http\Requests\Captures\StoreCaptureRequest;
use App\Mcp\Support\ResourceLinker;
use App\Models\Capture;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The capture inbox (screen 16) and the global quick-entry overlay
 * (screen 17): one field, reachable from every screen, that never touches
 * Now or an objective until triaged (capture-inbox spec).
 */
class CaptureController extends Controller
{
    public function index(Request $request, ResourceLinker $links): Response
    {
        $owner = $request->user();

        $untriaged = Capture::query()
            ->where('user_id', $owner->id)
            ->untriaged()
            ->get();

        $today = Habit::todayLocalDate()->toDateString();

        $decidedToday = Capture::withRetired()
            ->where('user_id', $owner->id)
            ->where(fn ($query) => $query->whereDate('triaged_at', $today)->orWhereDate('retired_at', $today))
            ->with('result')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        $objectives = Objective::query()
            ->where('user_id', $owner->id)
            ->where('state', ObjectiveState::Active->value)
            ->orderBy('position')
            ->with(['plans' => fn ($query) => $query->where('state', '!=', PlanState::Retired->value)->orderBy('position')])
            ->get();

        return Inertia::render('captures/index', [
            'captures' => $untriaged->map(fn (Capture $capture): array => $this->present($capture))->all(),
            'decided' => $decidedToday->map(fn (Capture $capture): array => [
                'id' => $capture->id,
                'text' => $capture->text,
                'triaged' => $capture->triaged_at !== null,
                'result_url' => $this->resultUrl($capture, $links),
            ])->all(),
            'objectives' => $objectives->map(fn (Objective $objective): array => [
                'key' => $objective->key,
                'title' => $objective->title,
                'plans' => $objective->plans->map(fn (Plan $plan): array => ['id' => $plan->id, 'title' => $plan->title])->all(),
            ])->all(),
        ]);
    }

    /**
     * The quick-entry overlay and the inbox's own field both post here: the
     * capture is saved without leaving the current screen.
     */
    public function store(StoreCaptureRequest $request, CreateCapture $createCapture): RedirectResponse
    {
        $createCapture(Actor::ownerWeb($request->user()), (string) $request->validated('text'), CaptureSource::Web);

        return back();
    }

    public function convertToItem(ConvertCaptureToItemRequest $request, Capture $capture, ConvertCaptureToItem $convert): RedirectResponse
    {
        $owner = $request->user();
        $objective = Objective::query()->where('user_id', $owner->id)->where('key', $request->validated('objective_key'))->first();

        if ($objective === null) {
            throw (new ModelNotFoundException)->setModel(Objective::class, [$request->validated('objective_key')]);
        }

        $plan = Plan::query()->where('objective_id', $objective->id)->whereKey($request->validated('plan_id'))->first();

        if ($plan === null) {
            throw (new ModelNotFoundException)->setModel(Plan::class, [$request->validated('plan_id')]);
        }

        $convert(Actor::ownerWeb($owner), $capture, $plan, [
            'title' => $request->validated('title'),
            'two_minute_version' => $request->validated('two_minute_version'),
        ]);

        return back();
    }

    public function convertToHabit(ConvertCaptureToHabitRequest $request, Capture $capture, ConvertCaptureToHabit $convert): RedirectResponse
    {
        $convert(Actor::ownerWeb($request->user()), $capture, [
            ...$request->validated(),
            'habit_type' => 'yes_no',
            'recurrence_type' => 'daily',
        ]);

        return back();
    }

    public function convertToObjective(ConvertCaptureToObjectiveRequest $request, Capture $capture, ConvertCaptureToObjectiveDraft $convert): RedirectResponse
    {
        $objective = $convert(Actor::ownerWeb($request->user()), $capture, $request->validated());

        return redirect()->route('objectives.edit', $objective->key);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Capture $capture): array
    {
        return [
            'id' => $capture->id,
            'text' => $capture->text,
            'source' => $capture->source->value,
            'source_label' => $capture->source->label(),
            'created_at' => $capture->created_at?->toIso8601String(),
        ];
    }

    private function resultUrl(Capture $capture, ResourceLinker $links): ?string
    {
        $result = $capture->result;

        return match (true) {
            $result instanceof Item => $links->item($result->loadMissing('objective'), $result->objective),
            $result instanceof Habit => $links->habit($result),
            $result instanceof Objective => route('objectives.show', $result->key),
            default => null,
        };
    }
}
