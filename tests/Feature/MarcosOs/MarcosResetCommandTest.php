<?php

use App\Console\Commands\MarcosReset;
use App\Console\Commands\RehearseRestore;
use App\Console\LegacyBackup\ManifestVerifier;
use App\Enums\TokenName;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;

/*
| Task 2.1 — `marcos:reset` guards (legacy-data-export spec "The Clean-Slate
| Reset Is Guarded" and "The Backup Must Pass A Restore Rehearsal"): a
| verified, rehearsed backup younger than 24 h, the confirmation phrase, never
| in `testing` unless the suite forces it; the owner and its `mcp`/`mobile`
| tokens survive and every Marcos OS and legacy domain table ends empty.
|
| Everything runs inside the test transaction (PostgreSQL DDL is
| transactional), so the dropped legacy tables come back afterwards.
*/

/**
 * A minimal but genuine backup folder: a dump file and one JSON table with
 * matching SHA-256 checksums and row counts, verified, plus its rehearsal.
 *
 * @param  array<string, mixed>  $manifestOverrides
 * @param  array<string, mixed>|null  $rehearsal  null = no rehearsal file
 */
function mosBackup(array $manifestOverrides = [], ?array $rehearsal = [], ?CarbonInterface $createdAt = null): string
{
    $base = sys_get_temp_dir().'/mos-reset-'.bin2hex(random_bytes(5));
    $directory = $base.'/'.($createdAt ?? now())->utc()->format('Y-m-d_His');
    File::ensureDirectoryExists($directory.'/tables');

    file_put_contents($directory.'/database.dump', 'PGDMP fake dump bytes');
    file_put_contents($directory.'/tables/users.json', json_encode([['id' => 1]]));

    $manifest = array_replace([
        'format' => 1,
        'created_at' => ($createdAt ?? now())->utc()->toIso8601String(),
        'dump' => ['file' => 'database.dump', 'format' => 'custom', 'sha256' => hash_file('sha256', $directory.'/database.dump')],
        'tables' => ['users' => ['rows' => 1, 'json' => [
            'file' => 'tables/users.json', 'rows' => 1, 'sha256' => hash_file('sha256', $directory.'/tables/users.json'),
        ]]],
        'verified' => true,
        'verified_at' => ($createdAt ?? now())->utc()->toIso8601String(),
        'problems' => [],
    ], $manifestOverrides);

    ManifestVerifier::write($directory, $manifest);

    if ($rehearsal !== null) {
        file_put_contents($directory.'/'.RehearseRestore::RESULT_FILE, json_encode(array_replace([
            'rehearsed_at' => ($createdAt ?? now())->utc()->toIso8601String(),
            'passed' => true,
            'dump_sha256' => $manifest['dump']['sha256'],
            'problems' => [],
        ], $rehearsal)));
    }

    Config::set('legacy_backup.path', $base);

    return $directory;
}

beforeEach(function () {
    Config::set('legacy_backup.reset_allow_testing', true);

    $this->owner = User::factory()->create(['email' => 'marco@example.test']);
    $this->mcp = $this->owner->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;
    $this->mobile = $this->owner->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $this->owner->createToken('otro', ['*']);

    $this->stranger = User::factory()->create();
    $this->stranger->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);

    Item::factory()->for(Plan::factory()->for(Objective::factory()->for($this->owner)->withControlPlan()))->create();
    Habit::factory()->for($this->owner)->create();
});

afterEach(function () {
    foreach (File::directories(sys_get_temp_dir()) as $directory) {
        if (str_starts_with(basename($directory), 'mos-reset-')) {
            File::deleteDirectory($directory);
        }
    }
});

function mosReset(array $options = []): PendingCommand
{
    return test()->artisan('marcos:reset', array_replace([
        '--owner' => 'marco@example.test',
        '--confirm' => MarcosReset::CONFIRMATION_PHRASE,
    ], $options));
}

test('a verified, rehearsed, fresh backup and the phrase reset everything but the owner and its two tokens', function () {
    mosBackup();

    expect(Schema::hasTable('projects'))->toBeTrue();

    mosReset()->assertSuccessful();

    expect(User::query()->pluck('email')->all())->toBe(['marco@example.test'])
        ->and($this->owner->tokens()->pluck('name')->sort()->values()->all())->toBe(['mcp', 'mobile'])
        ->and(Objective::query()->count())->toBe(0)
        ->and(Plan::query()->count())->toBe(0)
        ->and(Item::query()->count())->toBe(0)
        ->and(Habit::query()->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(2);

    foreach (['projects', 'project_members', 'sprints', 'board_columns', 'labels', 'issues', 'comments', 'issue_label'] as $legacy) {
        expect(Schema::hasTable($legacy))->toBeFalse("{$legacy} should be dropped");
    }

    // Both integrations can reconnect with their existing tokens.
    $this->getJson('/api/v1/user', ['Authorization' => "Bearer {$this->mobile}"])->assertOk();
    app('auth')->forgetGuards();
    $this->postJson('/mcp', mcpInitializePayload(), mcpHeaders($this->mcp))->assertOk();
});

test('it refuses in the testing environment unless the suite forces it', function () {
    Config::set('legacy_backup.reset_allow_testing', false);
    mosBackup();

    mosReset()->assertFailed()->expectsOutputToContain('testing');

    expect(Objective::query()->count())->toBe(1)->and(Schema::hasTable('projects'))->toBeTrue();
});

test('it refuses without the exact confirmation phrase', function (string $phrase) {
    mosBackup();

    mosReset(['--confirm' => $phrase])->assertFailed();

    expect(Objective::query()->count())->toBe(1)->and(User::query()->count())->toBe(2);
})->with(['', 'borrar y empezar de cero', 'SI']);

test('it asks for the phrase when it was not given, and stops on a wrong answer', function () {
    mosBackup();

    test()->artisan('marcos:reset', ['--owner' => 'marco@example.test'])
        ->expectsQuestion('Escribí la frase exacta para confirmar: '.MarcosReset::CONFIRMATION_PHRASE, 'no')
        ->assertFailed();

    expect(Objective::query()->count())->toBe(1);
});

test('it refuses when the backup is not good enough', function (Closure $makeBackup, string $reason) {
    $makeBackup();

    mosReset()->assertFailed()->expectsOutputToContain($reason);

    expect(Objective::query()->count())->toBe(1)
        ->and(Habit::query()->count())->toBe(1)
        ->and(Schema::hasTable('projects'))->toBeTrue();
})->with([
    'no backup at all' => [fn () => Config::set('legacy_backup.path', sys_get_temp_dir().'/mos-reset-empty-'.bin2hex(random_bytes(4))), 'No hay ningún respaldo'],
    'unverified' => [fn () => mosBackup(['verified' => false]), 'no está verificado'],
    'not rehearsed' => [fn () => mosBackup([], null), 'no pasó el ensayo'],
    'failed rehearsal' => [fn () => mosBackup([], ['passed' => false, 'problems' => ['x']]), 'no pasó el ensayo'],
    'rehearsal of another dump' => [fn () => mosBackup([], ['dump_sha256' => str_repeat('0', 64)]), 'no pasó el ensayo'],
    'older than 24 hours' => [fn () => mosBackup([], [], now()->subHours(25)), '24 horas'],
    'dated in the future' => [fn () => mosBackup([], [], now()->addDays(2)), 'fecha futura'],
    'beyond the clock skew' => [fn () => mosBackup([], [], now()->addMinutes(6)), 'fecha futura'],
    'tampered file' => [function () {
        $directory = mosBackup();
        file_put_contents($directory.'/tables/users.json', json_encode([['id' => 1], ['id' => 2]]));
    }, 'SHA-256'],
]);

test('it refuses a backup whose created_at is not a strict ISO-8601 moment, cleanly and touching nothing', function (Closure $makeBackup) {
    $makeBackup();

    mosReset()->assertFailed()->expectsOutputToContain('fecha de creación legible');

    expect(Objective::query()->count())->toBe(1)
        ->and(Habit::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(2)
        ->and(Schema::hasTable('projects'))->toBeTrue();
})->with([
    'empty string' => [fn () => mosBackup(['created_at' => ''])],
    'whitespace' => [fn () => mosBackup(['created_at' => '   '])],
    'now' => [fn () => mosBackup(['created_at' => 'now'])],
    'tomorrow' => [fn () => mosBackup(['created_at' => 'tomorrow'])],
    'garbage' => [fn () => mosBackup(['created_at' => 'no-es-una-fecha'])],
    'numeric timestamp' => [fn () => mosBackup(['created_at' => time()])],
    'numeric string timestamp' => [fn () => mosBackup(['created_at' => (string) time()])],
    'date only' => [fn () => mosBackup(['created_at' => now()->toDateString()])],
    'space instead of T' => [fn () => mosBackup(['created_at' => now()->utc()->format('Y-m-d H:i:sP')])],
    'impossible date' => [fn () => mosBackup(['created_at' => '2026-02-30T10:00:00+00:00'])],
    'null' => [fn () => mosBackup(['created_at' => null])],
    'missing key' => [function () {
        $directory = mosBackup();
        $manifest = ManifestVerifier::read($directory);
        unset($manifest['created_at']);
        ManifestVerifier::write($directory, $manifest);
    }],
]);

test('the strict parser accepts exactly the formats the export writes', function () {
    expect(MarcosReset::parseManifestMoment('2026-09-28T03:21:33+00:00')?->toIso8601String())->toBe('2026-09-28T03:21:33+00:00')
        ->and(MarcosReset::parseManifestMoment('2026-09-28T03:21:33Z')?->toIso8601String())->toBe('2026-09-28T03:21:33+00:00')
        ->and(MarcosReset::parseManifestMoment('2026-09-27T21:21:33-06:00')?->utc()->toIso8601String())->toBe('2026-09-28T03:21:33+00:00');
});

test('it refuses an unknown owner', function () {
    mosBackup();

    mosReset(['--owner' => 'nadie@example.test'])->assertFailed()->expectsOutputToContain('nadie@example.test');

    expect(User::query()->count())->toBe(2);
});

test('a backup a few minutes ahead (clock skew) is still accepted', function () {
    mosBackup([], [], now()->addMinutes(4));

    mosReset()->assertSuccessful();
});

test('an explicit backup folder is used instead of the newest one', function () {
    $good = mosBackup();
    mosBackup(['verified' => false]);

    mosReset(['--backup' => $good])->assertSuccessful();

    expect(Objective::query()->count())->toBe(0);
});
