<?php

use App\Models\Capture;
use App\Models\Review;
use App\Models\WeeklyPriority;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
| Task 7.1 — captures, weekly_priorities, reviews: migrations + models,
| with working down().
*/

test('the phase 7 tables exist and every migration has a working down()', function () {
    foreach (['captures', 'weekly_priorities', 'reviews'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should exist");
    }

    $files = collect(File::files(database_path('migrations')))
        ->map->getPathname()
        ->filter(fn (string $path) => str_contains(basename($path), '2026_09_28_7000'))
        ->sort()
        ->values();

    expect($files)->toHaveCount(3);

    $files->reverse()->each(fn (string $path) => (include $path)->down());

    foreach (['captures', 'weekly_priorities', 'reviews'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should be dropped by down()");
    }

    $files->each(fn (string $path) => (include $path)->up());

    foreach (['captures', 'weekly_priorities', 'reviews'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should be back after up()");
    }
});

test('the models are wired to their tables with factories', function () {
    expect(Capture::factory()->create())->toBeInstanceOf(Capture::class)
        ->and(WeeklyPriority::factory()->create())->toBeInstanceOf(WeeklyPriority::class)
        ->and(Review::factory()->create())->toBeInstanceOf(Review::class);
});
