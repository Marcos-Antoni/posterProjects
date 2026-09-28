<?php

use App\Actions\Habits\ChangeHabitLevel;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Models\Habit;
use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
| Task 4.5 — progressive levels (habits spec "Habits Scale Progressively"):
| suggest moving up only at ≥ 80 % shown-up over the last 14 UTC-6 days at the
| current level, suggest stepping down after the tolerant streak breaks, and
| only the owner applies a level change (AI: major), without touching the
| streak. Today is Sunday 2026-09-27.
*/

beforeEach(fn () => p4Today('2026-09-27'));

/**
 * @return list<array{label: string, target: int|null, two_minute_version: string}>
 */
function p4Ladder(): array
{
    return [
        ['label' => 'Ir y hacer 10 min', 'target' => 10, 'two_minute_version' => 'Ponerme la ropa del gym'],
        ['label' => '30 min de rutina', 'target' => 30, 'two_minute_version' => 'Ponerme la ropa del gym que dejé lista'],
        ['label' => '45 min, rutina completa', 'target' => 45, 'two_minute_version' => 'Salir con la mochila'],
    ];
}

function p4LadderHabit(int $level = 2, string $levelSince = '2026-08-01'): Habit
{
    return p4Habit('2026-08-01', [
        'level' => $level,
        'level_ladder' => p4Ladder(),
        'level_started_on' => $levelSince,
        'daily_target' => p4Ladder()[$level - 1]['target'],
        'two_minute_version' => p4Ladder()[$level - 1]['two_minute_version'],
    ], fn ($f) => $f->quantitative('minutos', 30));
}

test('a level-up is suggested at 12 of 14 and the level does not change by itself', function () {
    $habit = p4LadderHabit();
    p4Days($habit, '2026-09-14', '2026-09-27');
    $habit->days()->whereIn('entry_date', ['2026-09-16', '2026-09-20'])->update(['completed' => false]);

    $suggestion = $habit->fresh()->history()->levelSuggestion();

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->direction)->toBe('up')
        ->and($suggestion->toLevel)->toBe(3)
        ->and($suggestion->cast)->toBe(12)
        ->and($suggestion->possible)->toBe(14)
        ->and($habit->fresh()->level)->toBe(2);
});

test('no level-up below 80 % over the last 14 days', function () {
    $habit = p4LadderHabit();
    p4Days($habit, '2026-09-14', '2026-09-27');
    $habit->days()->whereIn('entry_date', ['2026-09-16', '2026-09-20', '2026-09-24'])->update(['completed' => false]);

    expect($habit->fresh()->history()->levelSuggestion())->toBeNull();
});

test('the 2-minute version counts toward the level-up proportion', function () {
    $habit = p4LadderHabit();
    p4Days($habit, '2026-09-14', '2026-09-26', 'two');

    expect($habit->fresh()->history()->levelSuggestion()?->direction)->toBe('up');
});

test('no level-up from the top level or without a ladder', function () {
    $top = p4LadderHabit(3);
    p4Days($top, '2026-09-14', '2026-09-27');

    $plain = p4Habit('2026-08-01');
    p4Days($plain, '2026-09-14', '2026-09-27');

    expect($top->history()->levelSuggestion())->toBeNull()
        ->and($plain->history()->levelSuggestion())->toBeNull();
});

test('no level-up until 14 days were lived at the current level', function () {
    $habit = p4LadderHabit(2, '2026-09-20');
    p4Days($habit, '2026-09-14', '2026-09-27');

    expect($habit->history()->levelSuggestion())->toBeNull();
});

test('a step down is suggested after the tolerant streak breaks', function () {
    $habit = p4LadderHabit();
    p4Days($habit, '2026-09-01', '2026-09-24');

    $suggestion = $habit->history()->levelSuggestion();

    expect($suggestion?->direction)->toBe('down')
        ->and($suggestion->toLevel)->toBe(1);

    $bottom = p4LadderHabit(1);
    p4Days($bottom, '2026-09-01', '2026-09-24');

    expect($bottom->history()->levelSuggestion())->toBeNull();
});

test('the owner applies a level: target and 2-minute version follow, the streak is untouched', function () {
    $habit = p4LadderHabit();
    p4Days($habit, '2026-09-14', '2026-09-26');
    $before = $habit->history()->streak();

    $updated = app(ChangeHabitLevel::class)(Actor::ownerWeb($habit->user), $habit, 3);

    expect($updated->level)->toBe(3)
        ->and($updated->daily_target)->toBe(45)
        ->and($updated->two_minute_version)->toBe('Salir con la mochila')
        ->and($updated->level_started_on->toDateString())->toBe('2026-09-27')
        ->and($updated->fresh()->history()->streak()->current)->toBe($before->current)
        ->and($updated->fresh()->history()->streak()->best)->toBe($before->best);
});

test('a level outside the ladder is rejected', function (int $level) {
    $habit = p4LadderHabit();

    $errors = mosErrors(fn () => app(ChangeHabitLevel::class)(Actor::ownerWeb($habit->user), $habit, $level));

    expect($errors)->toHaveKey('level')
        ->and($habit->fresh()->level)->toBe(2);
})->with([0, 4, -1]);

test('the AI cannot change a level on its own: it is a major operation', function () {
    $habit = p4LadderHabit();

    expect(fn () => app(ChangeHabitLevel::class)(Actor::aiMcp($habit->user), $habit, 3))
        ->toThrow(MajorOperationRequiresProposal::class);

    expect($habit->fresh()->level)->toBe(2);
});

test('the owner accepts the suggestion from the web', function () {
    $habit = p4LadderHabit();

    $this->actingAs($habit->user)
        ->post(route('habits.level.update', $habit), ['level' => 3])
        ->assertRedirect(route('habits.show', $habit));

    expect($habit->fresh()->level)->toBe(3);

    $this->actingAs(User::factory()->create())
        ->post(route('habits.level.update', $habit), ['level' => 1])
        ->assertForbidden();
});
