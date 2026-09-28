<?php

use App\Enums\ObjectiveState;
use App\Models\Habit;
use App\Models\Objective;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| Task 4.7 — the habit screens (18 today, 19 manage, 20 detail/form, 21
| identity votes) receive everything the mockups draw, computed on read, and
| never a missed-day count, a percentage of votes or a composite score.
| Today is Sunday 2026-09-27 (UTC-6).
*/

beforeEach(fn () => p4Today('2026-09-27'));

function p4Ladder3(): array
{
    return [
        ['label' => 'Ir y hacer 10 min', 'target' => null, 'two_minute_version' => 'Ponerme la ropa'],
        ['label' => '30 min de rutina', 'target' => null, 'two_minute_version' => 'Ponerme la ropa que dejé lista'],
        ['label' => '45 min, rutina completa', 'target' => null, 'two_minute_version' => 'Salir con la mochila'],
    ];
}

test('screen 18: scheduled habits with 2-minute version, 14-day strip and streak state; resting ones apart', function () {
    $user = User::factory()->create();
    $read = p4Habit('2026-09-01', ['user_id' => $user->id, 'name' => 'Leer 2 páginas', 'two_minute_version' => 'Abrir el libro', 'identity_statement' => 'Soy alguien que construye cada día']);
    p4Days($read, '2026-09-14', '2026-09-25');
    $gym = p4Habit('2026-09-01', ['user_id' => $user->id, 'name' => 'Gimnasio 06:30'], fn ($f) => $f->specificWeekdays([2, 3, 4, 5]));

    $this->actingAs($user)->get(route('habits.today'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('habits/today')
            ->where('date', '2026-09-27')
            ->has('habits', 1)
            ->where('habits.0.id', $read->id)
            ->where('habits.0.two_minute_version', 'Abrir el libro')
            ->where('habits.0.streak.current', 12)
            ->where('habits.0.streak.state', 'at_risk')
            ->has('habits.0.strip', 14)
            ->where('habits.0.strip.12.mark', 'g')
            ->where('habits.0.strip.13.mark', 'p')
            ->where('habits.0.today.shown_up', false)
            ->has('resting', 1)
            ->where('resting.0.id', $gym->id)
            ->where('resting.0.next_date', '2026-09-29')
            ->has('identities', 1)
            ->where('identities.0.statement', 'Soy alguien que construye cada día')
            ->where('identities.0.last7.cast', 5)
            ->where('identities.0.last7.possible', 6)
            ->where('identities.0.last7.row', ['v', 'v', 'v', 'v', 'v', 'n']));
});

test('screen 18 never carries debt: no missed-day counts in the payload', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-08-01', ['user_id' => $user->id]);
    p4Days($habit, '2026-08-01', '2026-08-10');

    $json = json_encode($this->actingAs($user)->get(route('habits.today'))->viewData('page')['props']);

    expect($json)->not->toContain('missed')
        ->not->toContain('debt')
        ->not->toContain('overdue');
});

test('screen 19: every habit with link, level, streak and a due level suggestion; archived ones apart', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['title' => 'Marcos OS en uso diario']);
    $gym = p4Habit('2026-08-01', [
        'user_id' => $user->id, 'name' => 'Gimnasio', 'level' => 2, 'level_ladder' => p4Ladder3(), 'level_started_on' => '2026-08-01',
    ]);
    p4Days($gym, '2026-09-14', '2026-09-27');
    $sleep = p4Habit('2026-09-01', ['user_id' => $user->id, 'name' => 'Dormir', 'objective_id' => $objective->id]);
    $old = p4Habit('2026-08-01', ['user_id' => $user->id, 'name' => 'Meditar', 'archived_at' => now()]);
    p4Days($old, '2026-08-02', '2026-08-10');

    $this->actingAs($user)->get(route('habits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('habits/index')
            ->has('habits', 2)
            ->where('habits.0.id', $sleep->id)
            ->where('habits.0.objective.title', 'Marcos OS en uso diario')
            ->where('habits.0.level', null)
            ->where('habits.0.suggestion', null)
            ->where('habits.1.id', $gym->id)
            ->where('habits.1.level', ['current' => 2, 'total' => 3, 'label' => '30 min de rutina'])
            ->where('habits.1.suggestion.direction', 'up')
            ->where('habits.1.suggestion.cast', 14)
            ->where('habits.1.suggestion.possible', 14)
            ->has('archived', 1)
            ->where('archived.0.id', $old->id)
            ->where('archived.0.recorded_days', 9));
});

test('screen 20: detail with 8-week calendar, votes for its identity, ladder, suggestion and the form options', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['identity_statement' => 'Soy alguien que se mueve']);
    Objective::factory()->for($user)->retired()->create();
    Objective::factory()->create();
    $habit = p4Habit('2026-08-01', [
        'user_id' => $user->id, 'objective_id' => $objective->id, 'level' => 2, 'level_ladder' => p4Ladder3(), 'level_started_on' => '2026-08-01',
    ], fn ($f) => $f->specificWeekdays([2, 3, 4, 5]));
    p4Days($habit, '2026-09-01', '2026-09-26');

    $this->actingAs($user)->get(route('habits.show', $habit))
        ->assertInertia(fn (Assert $page) => $page
            ->component('habits/show')
            ->where('habit.id', $habit->id)
            ->has('habit.calendar', 8)
            ->has('habit.calendar.7.days', 7)
            ->where('habit.calendar.7.days.6.date', '2026-09-27')
            ->where('habit.votes.statement', 'Soy alguien que se mueve')
            ->where('habit.votes.source', 'objective')
            ->where('habit.votes.last7.cast', 4)
            ->where('habit.votes.last7.possible', 4)
            ->where('habit.level_number', 2)
            ->has('habit.level_ladder', 3)
            ->where('habit.suggestion.direction', 'up')
            ->has('objectives', 1)
            ->where('objectives.0.id', $objective->id));
});

test('screen 20 create state: an empty form with the objectives to hang from', function () {
    $user = User::factory()->create();
    Objective::factory()->for($user)->create();
    Objective::factory()->for($user)->create(['state' => ObjectiveState::Closed, 'closed_at' => now()]);

    $this->actingAs($user)->get(route('habits.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('habits/show')
            ->where('habit', null)
            ->has('objectives', 1));
});

test('screen 21: identity statements in the order they were written, as proportions only', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['identity_statement' => 'Soy alguien que construye cada día', 'title' => 'Marcos OS en uso diario']);
    $first = p4Habit('2026-08-01', ['user_id' => $user->id, 'objective_id' => $objective->id]);
    $second = p4Habit('2026-08-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que se mueve'], fn ($f) => $f->specificWeekdays([2, 3, 4, 5]));
    p4Days($first, '2026-09-21', '2026-09-27');
    p4Day($second, '2026-09-22');

    $response = $this->actingAs($user)->get(route('habits.identity'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('habits/identity')
        ->where('date', '2026-09-27')
        ->has('identities', 2)
        ->where('identities.0.statement', 'Soy alguien que construye cada día')
        ->where('identities.0.source', 'objective')
        ->where('identities.0.objective.title', 'Marcos OS en uso diario')
        ->where('identities.0.habits.0.id', $first->id)
        ->where('identities.0.last7.cast', 7)
        ->where('identities.0.last7.possible', 7)
        ->has('identities.0.last7.items', 7)
        ->has('identities.0.last30.weeks', 5)
        ->where('identities.1.statement', 'Soy alguien que se mueve')
        ->where('identities.1.last7.cast', 1)
        ->where('identities.1.last7.possible', 4)
        ->where('first_habit.id', $first->id));

    $json = json_encode($response->viewData('page')['props']['identities']);

    expect($json)->not->toContain('percent')
        ->not->toContain('score')
        ->not->toContain('rank');
});

test('habit screens are owner-only deep links; a non-numeric id is a 404', function () {
    $habit = Habit::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('habits.show', $habit))->assertForbidden();
    $this->actingAs($stranger)->get('/habits/abc')->assertNotFound();
    $this->actingAs($stranger)->get(route('habits.identity'))->assertOk();
});

test('guests are sent to login from every habit screen', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['habits.today', 'habits.index', 'habits.identity', 'habits.create']);
