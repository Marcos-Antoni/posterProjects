<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
| Task 2.2 — the Marcos OS tables (design D2) are created by the default
| migrations with working `down()`, and the legacy JIRA tables are dropped by
| a reset-only migration (design D1) that `php artisan migrate` never runs.
| Everything runs inside the test transaction (PostgreSQL DDL is
| transactional).
*/

const MOS_DOMAIN_TABLES = [
    'objectives', 'plans', 'control_plans', 'control_map_entries', 'items',
    'item_two_minute_history', 'item_dependencies', 'milestone_evidence', 'focus_sessions', 'retirements',
];

const MOS_LEGACY_TABLES = ['projects', 'project_members', 'sprints', 'board_columns', 'labels', 'issues', 'comments', 'issue_label'];

test('the default migrations create every Marcos OS table and leave the legacy ones for the guarded reset', function () {
    foreach (MOS_DOMAIN_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should exist");
    }

    foreach (MOS_LEGACY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is only dropped by marcos:reset");
    }

    expect(collect(File::files(database_path('migrations')))->map->getFilename()->filter(fn (string $name) => str_contains($name, 'drop_legacy')))
        ->toBeEmpty();
});

test('every Marcos OS migration has a working down() and can be re-applied', function () {
    $files = collect(File::files(database_path('migrations')))
        ->map->getPathname()
        ->filter(fn (string $path) => str_contains(basename($path), '2026_09_28_1000'))
        ->sort()
        ->values();

    expect($files)->toHaveCount(10);

    $files->reverse()->each(fn (string $path) => (include $path)->down());

    foreach (MOS_DOMAIN_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should be dropped by down()");
    }

    $files->each(fn (string $path) => (include $path)->up());

    foreach (MOS_DOMAIN_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should be back after up()");
    }
});

test('the reset-only migration drops the legacy tables and its down() recreates them', function () {
    Artisan::call('migrate', ['--path' => 'database/migrations/marcos-reset', '--force' => true]);

    foreach (MOS_LEGACY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should be dropped");
    }

    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/marcos-reset', '--force' => true]);

    foreach (MOS_LEGACY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should be recreated by down()");
    }

    expect(Schema::hasColumns('projects', ['owner_id', 'key', 'next_issue_number', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('issues', ['board_column_id', 'sprint_id', 'parent_id', 'story_points', 'due_date']))->toBeTrue();
});
