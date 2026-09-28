<?php

use App\Models\Habits\IdentityVotes;
use App\Models\Objective;
use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
| Task 4.4 — identity votes (habits spec): per identity statement, votes
| cast over scheduled opportunities for the rolling last 7 and last 30 UTC-6
| days; a habit without its own statement inherits its objective's. A
| proportion, never a score. Today is Sunday 2026-09-27.
*/

beforeEach(fn () => p4Today('2026-09-27'));

test('votes are counted per identity across the habits that share it', function () {
    $user = User::factory()->create();
    $daily = p4Habit('2026-08-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que se mueve']);
    $weekdays = p4Habit('2026-08-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que se mueve'], fn ($f) => $f->specificWeekdays([1, 2, 4]));

    // Daily: 21–27 are 7 opportunities; shown 21–24 and today → 5.
    p4Days($daily, '2026-09-21', '2026-09-24');
    p4Day($daily, '2026-09-27', 'two');
    // Mon 21, Tue 22, Thu 24 are 3 opportunities; shown Mon and Thu → 2.
    p4Day($weekdays, '2026-09-21');
    p4Day($weekdays, '2026-09-24');

    $groups = app(IdentityVotes::class)->forUser($user);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->statement)->toBe('Soy alguien que se mueve')
        ->and($groups[0]->last7->cast)->toBe(7)
        ->and($groups[0]->last7->possible)->toBe(10)
        ->and($groups[0]->last7->twoMinute)->toBe(1)
        ->and(collect($groups[0]->habits)->pluck('id')->sort()->values()->all())->toBe(collect([$daily->id, $weekdays->id])->sort()->values()->all());
});

test('a pending today is not a lost vote', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-08-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que lee']);
    p4Days($habit, '2026-09-21', '2026-09-26');

    $group = app(IdentityVotes::class)->forUser($user)[0];

    expect($group->last7->cast)->toBe(6)
        ->and($group->last7->possible)->toBe(6);
});

test('the 30-day window is rolling and opportunities start when the habit was created', function () {
    $user = User::factory()->create();
    $old = p4Habit('2026-07-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que duerme bien']);
    $new = p4Habit('2026-09-25', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que escribe']);
    p4Day($old, '2026-08-28'); // outside the window (Aug 29 – Sep 27)
    p4Days($old, '2026-08-29', '2026-09-07');
    p4Day($new, '2026-09-25');

    $groups = collect(app(IdentityVotes::class)->forUser($user))->keyBy('statement');

    expect($groups['Soy alguien que duerme bien']->last30->cast)->toBe(10)
        ->and($groups['Soy alguien que duerme bien']->last30->possible)->toBe(29)
        ->and($groups['Soy alguien que escribe']->last30->cast)->toBe(1)
        ->and($groups['Soy alguien que escribe']->last30->possible)->toBe(2);
});

test('a habit without its own statement inherits its objective identity', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['identity_statement' => 'Soy alguien que construye cada día']);
    $inheriting = p4Habit('2026-09-20', ['user_id' => $user->id, 'objective_id' => $objective->id, 'identity_statement' => null]);
    $own = p4Habit('2026-09-20', ['user_id' => $user->id, 'objective_id' => $objective->id, 'identity_statement' => 'Soy alguien que entrena']);
    p4Habit('2026-09-20', ['user_id' => $user->id, 'identity_statement' => null]);

    $groups = collect(app(IdentityVotes::class)->forUser($user))->keyBy('statement');

    expect($groups->keys()->all())->toBe(['Soy alguien que construye cada día', 'Soy alguien que entrena'])
        ->and($groups['Soy alguien que construye cada día']->source)->toBe('objective')
        ->and($groups['Soy alguien que construye cada día']->objective?->is($objective))->toBeTrue()
        ->and(collect($groups['Soy alguien que construye cada día']->habits)->pluck('id')->all())->toBe([$inheriting->id])
        ->and($groups['Soy alguien que entrena']->source)->toBe('habit')
        ->and(collect($groups['Soy alguien que entrena']->habits)->pluck('id')->all())->toBe([$own->id]);
});

test('archived habits and other users never vote', function () {
    $user = User::factory()->create();
    p4Habit('2026-09-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que medita', 'archived_at' => now()]);
    p4Habit('2026-09-01', ['identity_statement' => 'Soy alguien que medita']);

    expect(app(IdentityVotes::class)->forUser($user))->toBe([]);
});

test('times-per-week opportunities are the weekly quota, and the week in progress is never a lost vote', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-08-01', ['user_id' => $user->id, 'identity_statement' => 'Soy alguien que corre'], fn ($f) => $f->timesPerWeek(3));
    // Window Sep 21–27 is the current (in-progress) week: 2 shown days.
    p4Days($habit, '2026-09-21', '2026-09-22');
    // Previous closed weeks inside the 30-day window.
    p4Days($habit, '2026-09-14', '2026-09-15'); // 2 of 3

    $group = app(IdentityVotes::class)->forUser($user)[0];

    expect($group->last7->cast)->toBe(2)
        ->and($group->last7->possible)->toBe(2);

    // Weeks in Aug 29 – Sep 27: Aug 29–30 (2 of 7 days in the window → quota pro-rated to ceil(3×2/7) = 1,
    // 0 shown), Aug 31, Sep 7 (0 of 3 each), Sep 14 (2 of 3), Sep 21 in progress (2 of 2).
    expect($group->last30->cast)->toBe(4)
        ->and($group->last30->possible)->toBe(1 + 3 + 3 + 3 + 2);
});
