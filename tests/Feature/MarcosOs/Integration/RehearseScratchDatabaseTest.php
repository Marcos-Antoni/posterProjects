<?php

use App\Console\Commands\RehearseRestore;
use Illuminate\Support\Facades\DB;

/*
| Wave A integration (Phase 1 LegacyBackup x parallel worktrees): the restore
| rehearsal's scratch database is named after the current DB_DATABASE
| (`<db>_rehearse_<stamp>_<random>`), so worktrees running on marcos_p3,
| marcos_p4… never collide nor drop each other's scratch databases.
*/

test('the scratch prefix is derived from the current database name', function () {
    expect(RehearseRestore::scratchPrefix('marcos_p4'))->toBe('marcos_p4_rehearse_')
        ->and(RehearseRestore::scratchPrefix('postgres'))->toBe('postgres_rehearse_')
        ->and(RehearseRestore::scratchPrefix(DB::connection()->getDatabaseName()))
        ->toStartWith(strtolower(DB::connection()->getDatabaseName()));
});

test('prefixes of different worktree databases never contain one another', function () {
    $prefixes = array_map(RehearseRestore::scratchPrefix(...), ['postgres', 'marcos_p1', 'marcos_p3', 'marcos_p10', 'marcos_p11']);

    foreach ($prefixes as $one) {
        foreach ($prefixes as $other) {
            if ($one !== $other) {
                expect(str_starts_with($one, $other))->toBeFalse();
            }
        }
    }
});

test('odd or very long database names still give a valid PostgreSQL identifier', function () {
    $prefix = RehearseRestore::scratchPrefix('My-Weird.DB name with a very long suffix that goes on and on');
    $full = $prefix.'20260928_120000_abcdef';

    expect($prefix)->toMatch('/^[a-z0-9_]+_rehearse_$/')
        ->and(strlen($full))->toBeLessThanOrEqual(63);
});
