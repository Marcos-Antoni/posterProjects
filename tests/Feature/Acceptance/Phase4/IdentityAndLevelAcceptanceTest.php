<?php

/*
| Phase 4 acceptance (independent tester) — habits spec "Identity Votes Are A
| Proportion, Not A Score" (7/30 rolling UTC-6 days, inheritance from the
| objective, creation date, pending today) and "Habits Scale Progressively"
| (≥80 % over 14 days, 14 days lived at the level, step down after a break,
| owner-applied only, streak never reset). Today is Sunday 2026-09-27.
*/

use App\Models\Habit;
use App\Models\Habits\IdentityVotes;
use App\Models\Objective;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/support.php';

beforeEach(fn () => p4aAt('2026-09-27'));

/**
 * @return array<string, array{cast: int, possible: int}>
 */
function p4aVotes(User $user): array
{
    $out = [];

    foreach (app(IdentityVotes::class)->forUser($user) as $group) {
        $out[$group->statement] = [
            'cast7' => $group->last7->cast, 'possible7' => $group->last7->possible,
            'cast30' => $group->last30->cast, 'possible30' => $group->last30->possible,
        ];
    }

    return $out;
}

test('spec: two habits sharing an identity, 10 opportunities in 7 days with 7 shown → 7 of 10', function () {
    $user = User::factory()->create();
    $identity = ['identity_statement' => 'soy alguien que se mueve'];

    // Daily: window 21–27, today pending → 6 opportunities (21–26), 4 shown.
    $walk = p4aHabit('2026-09-01', $identity, fn ($f) => $f->daily(), $user);
    p4aDays($walk, ['2026-09-21', '2026-09-22', '2026-09-24', '2026-09-26']);

    // Mon/Wed/Fri/Sat: 21, 23, 25, 26 → 4 opportunities, 3 shown.
    $gym = p4aHabit('2026-09-01', $identity, fn ($f) => $f->specificWeekdays([1, 3, 5, 6]), $user);
    p4aDays($gym, ['2026-09-21', '2026-09-23', '2026-09-26']);

    expect(p4aVotes($user)['soy alguien que se mueve'])->toMatchArray(['cast7' => 7, 'possible7' => 10]);
});

test('a pending today is not a lost vote; a shown-up today is a vote', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['identity_statement' => 'leo'], user: $user);
    p4aDays($habit, p4aRange('2026-09-21', '2026-09-26'));

    expect(p4aVotes($user)['leo'])->toMatchArray(['cast7' => 6, 'possible7' => 6]);

    p4aDay($habit, '2026-09-27', 'two');

    expect(p4aVotes($user)['leo'])->toMatchArray(['cast7' => 7, 'possible7' => 7]);
});

test('days before the habit was created are not opportunities (7 and 30 days)', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-24', ['identity_statement' => 'escribo'], user: $user);
    p4aDays($habit, ['2026-09-24', '2026-09-26']);

    expect(p4aVotes($user)['escribo'])->toMatchArray(['cast7' => 2, 'possible7' => 3, 'cast30' => 2, 'possible30' => 3]);
});

test('the 30-day window is rolling: day 29 back counts, day 30 back does not', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-08-01', ['identity_statement' => 'medito'], user: $user);
    p4aDay($habit, '2026-08-28'); // today-30 → outside
    p4aDay($habit, '2026-08-29'); // today-29 → inside

    // window 29 Aug – 27 Sep, today pending → 29 opportunities, 1 vote.
    expect(p4aVotes($user)['medito'])->toMatchArray(['cast30' => 1, 'possible30' => 29]);
});

test('a habit without its own statement votes for its objective\'s identity', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['identity_statement' => 'soy alguien sano']);
    $habit = p4aHabit('2026-09-01', ['objective_id' => $objective->id, 'identity_statement' => null], user: $user);
    p4aDays($habit, p4aRange('2026-09-21', '2026-09-23'));

    expect(p4aVotes($user))->toHaveKey('soy alguien sano')
        ->and(p4aVotes($user)['soy alguien sano'])->toMatchArray(['cast7' => 3, 'possible7' => 6]);
});

test('a habit\'s own statement wins over its objective\'s', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['identity_statement' => 'soy alguien sano']);
    p4aHabit('2026-09-01', ['objective_id' => $objective->id, 'identity_statement' => 'corro'], user: $user);

    expect(p4aVotes($user))->toHaveKey('corro')->not->toHaveKey('soy alguien sano');
});

test('archived habits and other users\' habits never vote', function () {
    $user = User::factory()->create();
    $archived = p4aHabit('2026-09-01', ['identity_statement' => 'leo', 'archived_at' => now()], user: $user);
    p4aDays($archived, p4aRange('2026-09-21', '2026-09-26'));
    $foreign = p4aHabit('2026-09-01', ['identity_statement' => 'leo']);
    p4aDays($foreign, p4aRange('2026-09-21', '2026-09-26'));

    expect(p4aVotes($user))->toBe([]);
});

test('the identity screen shows proportions, never a percentage or score', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['identity_statement' => 'leo'], user: $user);
    p4aDays($habit, ['2026-09-21', '2026-09-23', '2026-09-25']);

    $this->actingAs($user)->get('/habits/identity')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('habits/identity')
        ->where('identities.0.statement', 'leo')
        ->where('identities.0.last7.cast', 3)
        ->where('identities.0.last7.possible', 6)
        ->missing('identities.0.score')
        ->missing('identities.0.percent')
        ->etc());
});

/*
| Level ladder.
*/

/**
 * A daily habit on level 1 of a 3-level ladder, created and started on the level long ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function p4aLadderHabit(User $user, array $attributes = [], ?Closure $state = null): Habit
{
    return p4aHabit('2026-08-01', [
        'level' => 1,
        'level_started_on' => '2026-08-01',
        'level_ladder' => [
            ['label' => '2 min', 'target' => null, 'two_minute_version' => 'Ponerme las zapatillas'],
            ['label' => '10 min', 'target' => null, 'two_minute_version' => 'Salir a la puerta'],
            ['label' => '20 min', 'target' => null, 'two_minute_version' => 'Caminar a la esquina'],
        ],
        ...$attributes,
    ], $state, $user);
}

/**
 * @return array<string, mixed>|null
 */
function p4aSuggestion(User $user): ?array
{
    $props = test()->actingAs($user)->get('/habits/manage')->assertOk()->viewData('page')['props'];

    return $props['habits'][0]['suggestion'] ?? null;
}

test('spec: 12 of 14 at the current level → level-up suggested; level unchanged until the owner accepts', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user);
    // window 14–27; today pending → 13 opportunities (14–26); 12 shown means only 1 missed... use today shown to get 14.
    p4aDays($habit, array_values(array_diff(p4aRange('2026-09-14', '2026-09-27'), ['2026-09-17', '2026-09-22'])));

    $suggestion = p4aSuggestion($user);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['direction'] ?? null)->toBe('up')
        ->and($habit->fresh()->level)->toBe(1);
});

test('79 % (11 of 14) is not enough', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user);
    p4aDays($habit, array_values(array_diff(p4aRange('2026-09-14', '2026-09-27'), ['2026-09-15', '2026-09-19', '2026-09-23'])));

    expect(p4aSuggestion($user))->toBeNull();
});

test('exactly 80 % (8 of 10 scheduled weekdays) suggests a level-up', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user, state: fn ($f) => $f->specificWeekdays([1, 2, 3, 4, 5]));
    // weekdays 14–25: 10 opportunities; miss 16 and 23 (not consecutive).
    p4aDays($habit, ['2026-09-14', '2026-09-15', '2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-24', '2026-09-25']);

    expect(p4aSuggestion($user)['direction'] ?? null)->toBe('up');
});

test('fewer than 14 days lived at the current level → no level-up even at 100 %', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user, ['level_started_on' => '2026-09-20']);
    p4aDays($habit, p4aRange('2026-09-01', '2026-09-26'));

    expect(p4aSuggestion($user))->toBeNull();
});

test('a habit younger than 14 days is never pushed up', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user, ['created_at' => now()->subDays(5), 'level_started_on' => '2026-09-22']);
    p4aDays($habit, p4aRange('2026-09-22', '2026-09-26'));

    expect(p4aSuggestion($user))->toBeNull();
});

test('after the tolerant streak breaks a step down is suggested (never below level 1)', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user, ['level' => 2]);
    p4aDays($habit, p4aRange('2026-09-01', '2026-09-24'));

    expect(p4aSuggestion($user)['direction'] ?? null)->toBe('down');

    $habit->update(['level' => 1]);

    expect(p4aSuggestion($user))->toBeNull();
});

test('on the last level there is no level-up', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user, ['level' => 3]);
    p4aDays($habit, p4aRange('2026-09-01', '2026-09-26'));

    expect(p4aSuggestion($user))->toBeNull();
});

test('accepting a level keeps the streak and history; it restarts the 14-day clock', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user);
    p4aDays($habit, p4aRange('2026-09-01', '2026-09-26'));
    $before = $habit->history()->streak();

    $this->actingAs($user)->post("/habits/{$habit->id}/level", ['level' => 2])->assertRedirect();

    $habit->refresh();
    $after = $habit->history()->streak();

    expect($habit->level)->toBe(2)
        ->and($habit->two_minute_version)->toBe('Salir a la puerta')
        ->and($after->current)->toBe($before->current)
        ->and($after->best)->toBe($before->best)
        ->and($habit->days()->count())->toBe(26)
        ->and(p4aSuggestion($user))->toBeNull();
});

test('another user can not change my habit\'s level', function () {
    $owner = User::factory()->create();
    $habit = p4aLadderHabit($owner);

    $this->actingAs(User::factory()->create())->post("/habits/{$habit->id}/level", ['level' => 2]);

    expect($habit->fresh()->level)->toBe(1);
});

test('a level outside the ladder is refused', function () {
    $user = User::factory()->create();
    $habit = p4aLadderHabit($user);

    $this->actingAs($user)->post("/habits/{$habit->id}/level", ['level' => 4])->assertSessionHasErrors('level');
    $this->actingAs($user)->post("/habits/{$habit->id}/level", ['level' => 0])->assertSessionHasErrors('level');

    expect($habit->fresh()->level)->toBe(1);
});
