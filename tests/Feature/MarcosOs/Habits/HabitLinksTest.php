<?php

use App\Actions\Habits\CreateHabit;
use App\Actions\Habits\UpdateHabit;
use App\Actions\Support\Actor;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
| Task 4.6 — a habit may hang from one objective and optionally one plan of
| that objective; the link is informational: closing or retiring the
| objective (or its plan) never archives, retires or modifies the habit.
*/

beforeEach(fn () => p4Today('2026-09-27'));

function p4LinkPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Dormir 22:00',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
        'two_minute_version' => 'Dejar el teléfono cargando fuera del cuarto',
    ], $overrides);
}

test('a habit hangs from an objective and one of its plans', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();

    $habit = app(CreateHabit::class)(Actor::ownerWeb($user), p4LinkPayload(['objective_id' => $objective->id, 'plan_id' => $plan->id]));

    expect($habit->objective->is($objective))->toBeTrue()
        ->and($habit->plan->is($plan))->toBeTrue()
        ->and($objective->habits()->pluck('id')->all())->toBe([$habit->id])
        ->and($plan->habits()->pluck('id')->all())->toBe([$habit->id]);
});

test('a plan of another objective, a foreign objective or a retired one are rejected', function (Closure $payload, string $field) {
    $user = User::factory()->create();

    $errors = mosErrors(fn () => app(CreateHabit::class)(Actor::ownerWeb($user), $payload($user)));

    expect($errors)->toHaveKey($field)
        ->and($user->habits()->count())->toBe(0);
})->with([
    'plan of another objective' => [fn (User $user) => p4LinkPayload([
        'objective_id' => Objective::factory()->for($user)->create()->id,
        'plan_id' => Plan::factory()->for(Objective::factory()->for($user))->create()->id,
    ]), 'plan_id'],
    'plan without objective' => [fn (User $user) => p4LinkPayload([
        'plan_id' => Plan::factory()->for(Objective::factory()->for($user))->create()->id,
    ]), 'plan_id'],
    'objective of another user' => [fn (User $user) => p4LinkPayload([
        'objective_id' => Objective::factory()->create()->id,
    ]), 'objective_id'],
    'retired objective' => [fn (User $user) => p4LinkPayload([
        'objective_id' => Objective::factory()->for($user)->retired()->create()->id,
    ]), 'objective_id'],
    'retired plan' => [function (User $user) {
        $objective = Objective::factory()->for($user)->create();

        return p4LinkPayload([
            'objective_id' => $objective->id,
            'plan_id' => Plan::factory()->for($objective)->create(['state' => PlanState::Retired])->id,
        ]);
    }, 'plan_id'],
]);

test('the owner can unlink or relink a habit', function () {
    $user = User::factory()->create();
    $first = Objective::factory()->for($user)->create();
    $second = Objective::factory()->for($user)->create();
    $habit = app(CreateHabit::class)(Actor::ownerWeb($user), p4LinkPayload(['objective_id' => $first->id]));

    app(UpdateHabit::class)(Actor::ownerWeb($user), $habit, p4LinkPayload(['objective_id' => $second->id]));
    expect($habit->fresh()->objective_id)->toBe($second->id);

    app(UpdateHabit::class)(Actor::ownerWeb($user), $habit, p4LinkPayload(['objective_id' => null]));
    expect($habit->fresh()->objective_id)->toBeNull()
        ->and($habit->fresh()->plan_id)->toBeNull();
});

test('keeping the link to an objective that was closed meanwhile is allowed', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $habit = app(CreateHabit::class)(Actor::ownerWeb($user), p4LinkPayload(['objective_id' => $objective->id]));
    $objective->update(['state' => ObjectiveState::Closed, 'closed_at' => now()]);

    app(UpdateHabit::class)(Actor::ownerWeb($user), $habit, p4LinkPayload(['name' => 'Dormir 22:30', 'objective_id' => $objective->id]));

    expect($habit->fresh()->name)->toBe('Dormir 22:30')
        ->and($habit->fresh()->objective_id)->toBe($objective->id);
});

test('closing or retiring the objective and its plan never touches the habit', function (ObjectiveState $state) {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['identity_statement' => 'Soy alguien que descansa']);
    $plan = Plan::factory()->for($objective)->create();
    $habit = p4Habit('2026-09-01', ['user_id' => $user->id, 'objective_id' => $objective->id, 'plan_id' => $plan->id]);
    p4Days($habit, '2026-09-20', '2026-09-26');
    $before = $habit->fresh()->only(['name', 'retired_at', 'objective_id', 'plan_id', 'two_minute_version', 'updated_at']);

    $objective->update(['state' => $state, 'closed_at' => now()]);
    $plan->update(['state' => PlanState::Retired]);

    $habit->refresh();

    expect($habit->only(['name', 'retired_at', 'objective_id', 'plan_id', 'two_minute_version', 'updated_at']))->toEqual($before)
        ->and($habit->history()->streak()->current)->toBe(7);

    $this->actingAs($user)->get(route('habits.today'))
        ->assertInertia(fn ($page) => $page->where('habits.0.id', $habit->id));

    $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.0.id', $habit->id);
})->with([ObjectiveState::Closed, ObjectiveState::Retired]);
