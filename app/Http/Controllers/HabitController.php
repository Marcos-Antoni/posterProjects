<?php

namespace App\Http\Controllers;

use App\Actions\Habits\ChangeHabitLevel;
use App\Actions\Habits\CreateHabit;
use App\Actions\Habits\UpdateHabit;
use App\Actions\Support\Actor;
use App\Http\Requests\StoreHabitRequest;
use App\Http\Requests\UpdateHabitRequest;
use App\Http\Resources\HabitPresenter;
use App\Models\Habit;
use App\Models\Habits\IdentityVotes;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The habit screens (design D15 18–21): today, manage, detail/form and
 * identity votes. Streaks, marks, votes and level suggestions are computed on
 * read by `HabitHistory`; each list eager-loads the habits' days once.
 * There is intentionally NO destroy route: habits are never deleted.
 */
class HabitController extends Controller
{
    /**
     * Screen 18: the habits scheduled today (with "volver" and the 2-minute
     * version), the active ones that rest today, and the 7-day identity
     * votes.
     */
    public function today(Request $request, IdentityVotes $votes): Response
    {
        Gate::authorize('viewAny', Habit::class);

        $today = Habit::todayLocalDate();

        $habits = $request->user()
            ->habits()
            ->with(['days', 'schedulePeriods', 'objective', 'plan'])
            ->orderBy('name')
            ->get();

        [$scheduled, $resting] = $habits->partition(fn (Habit $habit): bool => $habit->isScheduledOn($today));

        return Inertia::render('habits/today', [
            'date' => $today->toDateString(),
            'habits' => $scheduled->map(fn (Habit $habit): array => HabitPresenter::todayRow($habit, $today))->values()->all(),
            'resting' => $resting->map(fn (Habit $habit): array => HabitPresenter::todayRow($habit, $today))->values()->all(),
            'identities' => array_map(
                fn ($group): array => HabitPresenter::identity($group, $today),
                $votes->forUser($request->user(), $today),
            ),
        ]);
    }

    /**
     * Screen 19: every habit with its 2-minute version, level, link and
     * tolerant streak (plus a level suggestion when due). Retired habits are
     * NOT listed here: the retirement spec makes them visible only in the
     * Retired view, so the screen shows how many there are and links there
     * (where "Devolver al mapa" restores them).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Habit::class);

        $today = Habit::todayLocalDate();

        $active = $request->user()
            ->habits()
            ->with(['days', 'schedulePeriods', 'objective', 'plan'])
            ->orderBy('name')
            ->get();

        $retiredCount = Habit::onlyRetired()->whereBelongsTo($request->user())->count();

        return Inertia::render('habits/index', [
            'habits' => $active->map(function (Habit $habit) use ($today): array {
                $history = $habit->history($today);

                return [
                    ...HabitPresenter::summary($habit, $history),
                    'suggestion' => $history->levelSuggestion()?->toArray(),
                ];
            })->values()->all(),
            'retired_count' => $retiredCount,
        ]);
    }

    /**
     * Screen 20 in its "create" state: the same form, empty.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('viewAny', Habit::class);

        return Inertia::render('habits/show', [
            'habit' => null,
            'objectives' => HabitPresenter::objectiveOptions($request->user()),
        ]);
    }

    /**
     * Screen 20: a habit's tolerant streak with its rule, the last 8 weeks of
     * marks, its identity votes, the level ladder with any suggestion, and
     * the edit form. Owner only.
     */
    public function show(Request $request, Habit $habit): Response
    {
        Gate::authorize('view', $habit);

        $habit->load(['days', 'schedulePeriods', 'objective', 'plan']);

        $today = Habit::todayLocalDate();
        $history = $habit->history($today);
        $calendarStart = $today->clone()->startOfWeek(CarbonInterface::MONDAY)->subWeeks(7);
        $statement = $habit->effectiveIdentityStatement();

        return Inertia::render('habits/show', [
            'habit' => [
                ...HabitPresenter::summary($habit, $history),
                'objective_id' => $habit->objective_id,
                'plan_id' => $habit->plan_id,
                'level_number' => $habit->level,
                'level_ladder' => $habit->level_ladder ?? [],
                'started_on' => $history->startDate()->toDateString(),
                'has_history' => $habit->days->contains(fn ($day): bool => $day->isShownUp()),
                'suggestion' => $history->levelSuggestion()?->toArray(),
                'next_date' => $history->nextScheduledDate()?->toDateString(),
                'calendar' => $this->calendar($history->marks($calendarStart, $today), $calendarStart),
                'votes' => $statement === null ? null : [
                    'statement' => $statement,
                    'source' => trim((string) $habit->identity_statement) !== '' ? 'habit' : 'objective',
                    'last7' => [...HabitPresenter::tally($history->votes(7)), 'row' => HabitPresenter::voteRow([$habit], $today)],
                    'last30' => [...HabitPresenter::tally($history->votes(30)), 'row' => HabitPresenter::voteRow([$habit], $today, 30)],
                ],
            ],
            'objectives' => HabitPresenter::objectiveOptions($request->user(), $habit),
        ]);
    }

    /**
     * Screen 21: the identity statements, each with its 7- and 30-day vote
     * proportions. Never a score, never ranked.
     */
    public function identity(Request $request, IdentityVotes $votes): Response
    {
        Gate::authorize('viewAny', Habit::class);

        $today = Habit::todayLocalDate();
        $firstHabit = $request->user()->habits()->orderBy('id')->first(['id', 'name']);

        return Inertia::render('habits/identity', [
            'date' => $today->toDateString(),
            'identities' => array_map(
                fn ($group): array => HabitPresenter::identity($group, $today),
                $votes->forUser($request->user(), $today),
            ),
            'first_habit' => $firstHabit !== null ? ['id' => $firstHabit->id, 'name' => $firstHabit->name] : null,
        ]);
    }

    public function store(StoreHabitRequest $request, CreateHabit $createHabit): RedirectResponse
    {
        $habit = $createHabit(Actor::ownerWeb($request->user()), $request->validated());

        return redirect()->route('habits.show', $habit);
    }

    public function update(UpdateHabitRequest $request, Habit $habit, UpdateHabit $updateHabit): RedirectResponse
    {
        $updateHabit(Actor::ownerWeb($request->user()), $habit, $request->validated());

        return redirect()->route('habits.show', $habit);
    }

    /**
     * Apply a level of the ladder (the owner accepting a suggestion, or
     * choosing another level). The streak is untouched.
     */
    public function level(Request $request, Habit $habit, ChangeHabitLevel $changeLevel): RedirectResponse
    {
        Gate::authorize('update', $habit);

        $validated = $request->validate(
            ['level' => ['required', 'integer']],
            ['level.required' => 'Elegí un nivel.', 'level.integer' => 'Elegí un nivel.'],
        );

        $changeLevel(Actor::ownerWeb($request->user()), $habit, (int) $validated['level']);

        return redirect()->route('habits.show', $habit);
    }

    /**
     * The detail calendar: Monday-based weeks, each with its 7 marks and the
     * length of any run a missed day closed that week.
     *
     * @param  list<array{date: string, mark: string, closes: bool, closed_run: int|null}>  $marks
     * @return list<array{week_start: string, days: list<array{date: string, mark: string, closes: bool, closed_run: int|null}>}>
     */
    private function calendar(array $marks, Carbon $start): array
    {
        $weeks = [];

        foreach (array_chunk($marks, 7) as $index => $days) {
            $weeks[] = ['week_start' => $start->clone()->addWeeks($index)->toDateString(), 'days' => $days];
        }

        return $weeks;
    }
}
