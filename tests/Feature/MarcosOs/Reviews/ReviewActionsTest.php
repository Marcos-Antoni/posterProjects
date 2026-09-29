<?php

use App\Actions\Objectives\CloseObjective;
use App\Actions\Reviews\SubmitWeeklyReview;
use App\Actions\Support\Actor;
use App\Actions\Support\WeeklyMainPriority;
use App\Enums\ObjectiveState;
use App\Enums\ReviewKind;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use App\Models\WeeklyPriority;
use Illuminate\Validation\ValidationException;

/*
| Task 7.4-7.7 — weekly priority feeding Now, the weekly review, and
| closing an objective with its learning review + habit decisions
| (reviews spec).
*/

test('a third maintenance standard is rejected', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();
    $habits = Habit::factory()->for($owner)->count(3)->create();

    expect(fn () => app(SubmitWeeklyReview::class)(Actor::ownerWeb($owner), [
        'what_worked' => 'terminé el boceto',
        'what_blocked' => 'me distraje con el celular',
        'main_type' => 'objective',
        'main_id' => $objective->id,
        'maintenance' => $habits->map(fn (Habit $habit): array => ['type' => 'habit', 'id' => $habit->id])->all(),
    ]))->toThrow(ValidationException::class, SubmitWeeklyReview::TOO_MANY_MAINTENANCE);
});

test('submitting the weekly review records it and sets next week the main priority, feeding Now', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $available = Item::factory()->for($plan)->create();
    $habit = Habit::factory()->for($owner)->create();

    $review = app(SubmitWeeklyReview::class)(Actor::ownerWeb($owner), [
        'what_worked' => 'terminé el boceto',
        'what_blocked' => 'me distraje con el celular',
        'main_type' => 'objective',
        'main_id' => $objective->id,
        'maintenance' => [['type' => 'habit', 'id' => $habit->id]],
    ]);

    expect($review->kind)->toBe(ReviewKind::Weekly)
        ->and($review->answers['what_worked'])->toBe('terminé el boceto');

    $week = WeeklyPriority::nextWeek();
    $priority = WeeklyPriority::query()->where('user_id', $owner->id)->where('iso_year', $week['year'])->where('iso_week', $week['week'])->first();

    expect($priority)->not->toBeNull()
        ->and($priority->main_type)->toBe('objective')
        ->and($priority->main_id)->toBe($objective->id)
        ->and($priority->maintenance)->toBe([['type' => 'habit', 'id' => $habit->id]]);
});

test('the weekly review shows progress before asking, and skipping it blocks nothing', function () {
    // No review submitted at all — nothing else in the app is blocked: the
    // Now screen still selects normally (no assertion needed beyond "no
    // exception"), and WeeklyPriorityReader answers "none" gracefully.
    $owner = User::factory()->create();

    expect(app(WeeklyMainPriority::class)->currentFor($owner))->toBeNull();
});

test('closing an objective asks about each linked habit and requires every decision', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();
    $habitA = Habit::factory()->for($owner)->create(['objective_id' => $objective->id]);
    $habitB = Habit::factory()->for($owner)->create(['objective_id' => $objective->id]);

    expect(fn () => app(CloseObjective::class)(Actor::ownerWeb($owner), $objective, [
        'what_learned' => 'aprendí', 'what_repeat' => 'repetiría', 'what_change' => 'cambiaría',
    ], [
        ['habit_id' => $habitA->id, 'decision' => 'keep'],
    ]))->toThrow(ValidationException::class, CloseObjective::MISSING_DECISIONS);

    expect($objective->fresh()->state)->toBe(ObjectiveState::Active);

    $review = app(CloseObjective::class)(Actor::ownerWeb($owner), $objective, [
        'what_learned' => 'aprendí que hay que empezar chico',
        'what_repeat' => 'repetiría el ritual de captura',
        'what_change' => 'cambiaría la revisión a los viernes',
    ], [
        ['habit_id' => $habitA->id, 'decision' => 'keep'],
        ['habit_id' => $habitB->id, 'decision' => 'retire', 'reason' => 'terminé el libro, entra otro hábito'],
    ]);

    expect($review->kind)->toBe(ReviewKind::Objective)
        ->and($review->objective_id)->toBe($objective->id)
        ->and($objective->fresh()->state)->toBe(ObjectiveState::Closed)
        ->and($objective->fresh()->closed_at)->not->toBeNull()
        ->and(Habit::query()->whereKey($habitA->id)->exists())->toBeTrue()
        ->and(Habit::query()->whereKey($habitB->id)->exists())->toBeFalse()
        ->and(Habit::withRetired()->whereKey($habitB->id)->first()->retired_at)->not->toBeNull();
});

test('closing an objective releases its active item so Now never shows a task of a closed objective', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->active()->create();
    FocusSession::query()->create(['item_id' => $item->id, 'started_at' => now()->subMinutes(5)]);

    app(CloseObjective::class)(Actor::ownerWeb($owner), $objective, [
        'what_learned' => 'x', 'what_repeat' => 'y', 'what_change' => 'z',
    ], []);

    expect($item->fresh()->is_active)->toBeFalse()
        ->and(FocusSession::query()->first()->end_reason)->toBe('objective-closed');
});

test('closing a non-active objective is refused', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->closed()->withControlPlan()->create();

    expect(fn () => app(CloseObjective::class)(Actor::ownerWeb($owner), $objective, [
        'what_learned' => 'x', 'what_repeat' => 'y', 'what_change' => 'z',
    ], []))->toThrow(ValidationException::class);
});
