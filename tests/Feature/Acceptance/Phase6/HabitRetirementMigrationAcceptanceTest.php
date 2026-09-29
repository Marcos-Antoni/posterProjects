<?php

/*
| Phase 6 acceptance (independent tester) — design D2 (`archived_at` renamed
| to `retired_at`), habits spec "Retiring A Habit Follows The Retirement
| Protocol": the data migration keeps every archived habit hidden, gives it
| a history row so it shows in Retirados and can be restored, leaves active
| habits alone, is idempotent, and reverses cleanly.
*/

use App\Actions\Retirement\RestoreElement;
use App\Actions\Support\Actor;
use App\Models\Habit;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function p6mMigration(string $suffix): object
{
    $files = glob(database_path("migrations/2026_09_28_{$suffix}_*.php"));

    return require $files[0];
}

/**
 * Walk the schema back to Phase 2 (habits.archived_at, retirements without owner columns).
 */
function p6mDownToPhase2(): void
{
    p6mMigration('600003')->down();
    p6mMigration('600002')->down();
    p6mMigration('600001')->down();
}

function p6mUp(): void
{
    p6mMigration('600001')->up();
    p6mMigration('600002')->up();
    p6mMigration('600003')->up();
}

function p6mHabitRow(User $user, string $name, ?string $archivedAt): int
{
    return DB::table('habits')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
        'archived_at' => $archivedAt,
        'created_at' => '2026-01-10 12:00:00',
        'updated_at' => '2026-01-10 12:00:00',
    ]);
}

test('down to Phase 2 and back: archived habits become retired with a history row; active habits untouched', function () {
    $owner = User::factory()->create();
    p6mDownToPhase2();

    expect(Schema::hasColumn('habits', 'archived_at'))->toBeTrue()
        ->and(Schema::hasColumn('habits', 'retired_at'))->toBeFalse();

    $archived = p6mHabitRow($owner, 'Meditar archivado', '2026-03-15 20:30:00');
    $active = p6mHabitRow($owner, 'Leer activo', null);
    DB::table('habit_entries')->insert(['habit_id' => $archived, 'amount' => 1, 'logged_at' => '2026-03-01 12:00:00', 'created_at' => '2026-03-01 12:00:00', 'updated_at' => '2026-03-01 12:00:00']);

    p6mUp();

    expect(Schema::hasColumn('habits', 'archived_at'))->toBeFalse()
        ->and(Habit::query()->pluck('id')->all())->toBe([$active])
        ->and(Habit::onlyRetired()->pluck('id')->all())->toBe([$archived])
        ->and(Habit::withRetired()->findOrFail($archived)->retired_at->toDateTimeString())->toBe('2026-03-15 20:30:00')
        ->and(DB::table('habit_entries')->where('habit_id', $archived)->count())->toBe(1);

    $row = Retirement::query()->sole();
    expect($row->retirable_type)->toBe('habit')
        ->and($row->retirable_id)->toBe($archived)
        ->and($row->user_id)->toBe($owner->id)
        ->and($row->kind->value)->toBe('habit')
        ->and($row->decision->value)->toBe('archive_as_is')
        ->and(mb_strlen($row->reason))->toBeGreaterThanOrEqual(10)
        ->and($row->retired_at->toDateTimeString())->toBe('2026-03-15 20:30:00')
        ->and($row->restored_at)->toBeNull();

    // It shows in Retirados and comes back from there.
    $this->actingAs($owner)->get('/retired')->assertOk()->assertSee('Meditar archivado');
    app(RestoreElement::class)(Actor::ownerWeb($owner), $row);
    expect(Habit::query()->find($archived))->not->toBeNull();
});

test('the backfill is idempotent: running it again adds no second row', function () {
    $owner = User::factory()->create();
    p6mDownToPhase2();
    p6mHabitRow($owner, 'Meditar archivado', '2026-03-15 20:30:00');
    p6mUp();

    p6mMigration('600003')->up();

    expect(Retirement::query()->count())->toBe(1);
});

test('the backfill does not duplicate rows for habits retired through the protocol, and its down() keeps them', function () {
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create();
    $this->actingAs($owner)->post("/habits/{$habit->id}/retire", ['reason' => 'no encaja en mi mañana'])->assertSessionHasNoErrors();

    p6mMigration('600003')->up();
    expect(Retirement::query()->count())->toBe(1);

    p6mMigration('600003')->down();
    expect(Retirement::query()->count())->toBe(1)
        ->and(Retirement::query()->sole()->reason)->toBe('no encaja en mi mañana');
});

test('rolling all three back restores archived_at with the same values', function () {
    $owner = User::factory()->create();
    Habit::factory()->for($owner)->create(['name' => 'Activo']);
    $retired = Habit::factory()->for($owner)->retired()->create(['name' => 'Retirado']);
    $at = Habit::withRetired()->findOrFail($retired->id)->retired_at->toDateTimeString();

    p6mDownToPhase2();

    expect(DB::table('habits')->whereNotNull('archived_at')->pluck('id')->all())->toBe([$retired->id])
        ->and(DB::table('habits')->where('id', $retired->id)->value('archived_at'))->toStartWith(substr($at, 0, 16));

    p6mUp();
});
