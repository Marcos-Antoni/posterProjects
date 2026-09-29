<?php

use App\Models\Habit;
use App\Models\HabitDay;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
| Task 4.1 — habits gain the Marcos OS columns (design D2) and habit_days the
| 2-minute flag, through a Phase 4 migration with a working down().
*/

const P4_HABIT_COLUMNS = ['objective_id', 'plan_id', 'two_minute_version', 'identity_statement', 'level', 'level_ladder', 'level_started_on'];

test('habits and habit_days carry the Marcos OS columns', function () {
    expect(Schema::hasColumns('habits', P4_HABIT_COLUMNS))->toBeTrue()
        ->and(Schema::hasColumn('habit_days', 'two_minute_logged'))->toBeTrue();
});

test('a habit day defaults to no 2-minute log and casts the new habit columns', function () {
    $habit = Habit::factory()->create([
        'level' => 2,
        'level_ladder' => [
            ['label' => '10 min', 'target' => null, 'two_minute_version' => 'Ponerme la ropa'],
            ['label' => '30 min', 'target' => null, 'two_minute_version' => 'Ponerme la ropa y salir'],
        ],
        'level_started_on' => '2026-09-01',
    ]);

    $day = HabitDay::query()->create(['habit_id' => $habit->id, 'entry_date' => '2026-09-27']);

    expect($day->refresh()->two_minute_logged)->toBeFalse()
        ->and($habit->refresh()->level)->toBe(2)
        ->and($habit->level_ladder)->toHaveCount(2)
        ->and($habit->level_ladder[1]['label'])->toBe('30 min')
        ->and($habit->level_started_on->toDateString())->toBe('2026-09-01');
});

test('the phase 4 migration has a working down() and can be re-applied', function () {
    $files = collect(File::files(database_path('migrations')))
        ->map->getPathname()
        ->filter(fn (string $path) => str_contains(basename($path), '2026_09_28_4000'))
        ->sort()
        ->values();

    expect($files)->not->toBeEmpty();

    $files->reverse()->each(fn (string $path) => (include $path)->down());

    expect(Schema::hasColumn('habits', 'two_minute_version'))->toBeFalse()
        ->and(Schema::hasColumn('habit_days', 'two_minute_logged'))->toBeFalse();

    $files->each(fn (string $path) => (include $path)->up());

    expect(Schema::hasColumns('habits', P4_HABIT_COLUMNS))->toBeTrue()
        ->and(Schema::hasColumn('habit_days', 'two_minute_logged'))->toBeTrue();
});
