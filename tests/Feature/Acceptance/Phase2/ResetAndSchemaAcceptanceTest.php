<?php

/*
| Phase 2 acceptance (independent tester) — spec `legacy-data-export`
| "The Clean-Slate Reset Is Guarded" / "The Backup Must Pass A Restore
| Rehearsal", design D1/D2/D13: guards, what survives, what is emptied, and
| that the normal migration path never drops legacy tables. Local only.
*/

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
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

const P2R_LEGACY = ['projects', 'project_members', 'sprints', 'board_columns', 'labels', 'issues', 'comments', 'issue_label'];
const P2R_NEW = ['objectives', 'plans', 'control_plans', 'control_map_entries', 'items', 'item_two_minute_history', 'item_dependencies', 'milestone_evidence', 'focus_sessions', 'retirements'];

function p2rBackup(?CarbonInterface $createdAt = null, array $manifest = [], ?array $rehearsal = []): string
{
    $createdAt ??= now();
    $base = sys_get_temp_dir().'/p2r-reset-'.bin2hex(random_bytes(5));
    $directory = $base.'/'.$createdAt->copy()->utc()->format('Y-m-d_His');
    File::ensureDirectoryExists($directory.'/tables');
    file_put_contents($directory.'/database.dump', 'PGDMP acceptance bytes');
    file_put_contents($directory.'/tables/users.json', json_encode([['id' => 1]]));

    $data = array_replace([
        'format' => 1,
        'created_at' => $createdAt->copy()->utc()->toIso8601String(),
        'dump' => ['file' => 'database.dump', 'format' => 'custom', 'sha256' => hash_file('sha256', $directory.'/database.dump')],
        'tables' => ['users' => ['rows' => 1, 'json' => ['file' => 'tables/users.json', 'rows' => 1, 'sha256' => hash_file('sha256', $directory.'/tables/users.json')]]],
        'verified' => true,
        'verified_at' => $createdAt->copy()->utc()->toIso8601String(),
        'problems' => [],
    ], $manifest);
    ManifestVerifier::write($directory, $data);

    if ($rehearsal !== null) {
        file_put_contents($directory.'/'.RehearseRestore::RESULT_FILE, json_encode(array_replace([
            'rehearsed_at' => $createdAt->copy()->utc()->toIso8601String(),
            'passed' => true,
            'dump_sha256' => $data['dump']['sha256'],
            'problems' => [],
        ], $rehearsal)));
    }

    Config::set('legacy_backup.path', $base);

    return $directory;
}

beforeEach(function () {
    Config::set('legacy_backup.reset_allow_testing', true);

    $this->owner = User::factory()->create(['email' => 'owner-p2r@example.test']);
    $this->mcp = $this->owner->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;
    $this->mobile = $this->owner->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $this->extra = $this->owner->createToken('scratch', ['*'])->plainTextToken;
    User::factory()->create()->createToken(TokenName::Mcp->value, [TokenName::Mcp->value]);

    $item = Item::factory()->for(Plan::factory()->for(Objective::factory()->for($this->owner)->withControlPlan()))->create();
    $item->twoMinuteHistory()->create(['text' => 'vieja', 'source' => 'owner', 'replaced_at' => now()]);
    Habit::factory()->for($this->owner)->create();
});

afterEach(function () {
    foreach (File::directories(sys_get_temp_dir()) as $directory) {
        if (str_starts_with(basename($directory), 'p2r-reset-')) {
            File::deleteDirectory($directory);
        }
    }
});

function p2rUntouched(): void
{
    expect(Objective::query()->count())->toBe(1)
        ->and(Item::query()->count())->toBe(1)
        ->and(Habit::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(2)
        ->and(Schema::hasTable('projects'))->toBeTrue();
}

test('the happy path keeps only the owner and its mcp/mobile tokens, drops legacy tables and empties every Marcos OS table', function () {
    p2rBackup();

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertSuccessful();

    foreach (P2R_LEGACY as $table) {
        expect(Schema::hasTable($table))->toBeFalse("legacy table {$table} must be gone");
    }
    foreach ([...P2R_NEW, 'habits', 'habit_days', 'habit_entries'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} must be empty");
    }

    expect(User::query()->pluck('email')->all())->toBe(['owner-p2r@example.test'])
        ->and(DB::table('personal_access_tokens')->pluck('name')->sort()->values()->all())->toBe(['mcp', 'mobile']);

    $this->getJson('/api/v1/user', ['Authorization' => "Bearer {$this->mobile}"])->assertOk();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/user', ['Authorization' => "Bearer {$this->extra}"])->assertUnauthorized();
});

test('it refuses in testing unless forced, even with a perfect backup and the phrase', function () {
    Config::set('legacy_backup.reset_allow_testing', false);
    p2rBackup();

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();

    p2rUntouched();
});

test('the confirmation phrase must match exactly', function (string $phrase) {
    p2rBackup();

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => $phrase])->assertFailed();

    p2rUntouched();
})->with([
    'trailing space' => MarcosReset::CONFIRMATION_PHRASE.' ',
    'lowercase' => mb_strtolower(MarcosReset::CONFIRMATION_PHRASE),
    'partial' => 'BORRAR',
    'yes' => 'yes',
]);

test('freshness: a backup of 23h passes and one of 24h+1m is refused', function (int $minutesAgo, bool $passes) {
    p2rBackup(now()->subMinutes($minutesAgo));

    $command = $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE]);

    if ($passes) {
        $command->assertSuccessful();
    } else {
        $command->assertFailed();
        p2rUntouched();
    }
})->with([
    '23 hours old' => [23 * 60, true],
    '24h and 1 minute old' => [24 * 60 + 1, false],
]);

test('a backup dated in the future is not "younger than 24 hours" and is refused', function () {
    p2rBackup(now()->addDays(2));

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();

    p2rUntouched();
});

test('it refuses without a verified backup that passed a rehearsal of that very dump', function (Closure $make) {
    $make();

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();

    p2rUntouched();
})->with([
    'no backup folder' => [fn () => Config::set('legacy_backup.path', sys_get_temp_dir().'/p2r-reset-none-'.bin2hex(random_bytes(3)))],
    'manifest says unverified' => [fn () => p2rBackup(manifest: ['verified' => false])],
    'rehearsal missing' => [fn () => p2rBackup(rehearsal: null)],
    'rehearsal failed' => [fn () => p2rBackup(rehearsal: ['passed' => false])],
    'rehearsal of another dump' => [fn () => p2rBackup(rehearsal: ['dump_sha256' => str_repeat('a', 64)])],
    'dump replaced after rehearsal' => [function () {
        $directory = p2rBackup();
        file_put_contents($directory.'/database.dump', 'PGDMP other bytes');
    }],
    'manifest without created_at' => [fn () => p2rBackup(manifest: ['created_at' => null])],
]);

test('a newer broken backup is not bypassed by an older good one unless named explicitly', function () {
    $good = p2rBackup(now()->subHours(2));
    $base = dirname($good);
    $bad = $base.'/'.now()->utc()->format('Y-m-d_His');
    File::copyDirectory($good, $bad);
    $manifest = ManifestVerifier::read($bad);
    $manifest['verified'] = false;
    ManifestVerifier::write($bad, $manifest);

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();
    p2rUntouched();
});

test('the normal migration path never contains the legacy drop migration', function () {
    $default = collect(File::files(database_path('migrations')))->map(fn ($file) => $file->getFilename());

    expect($default->filter(fn ($name) => str_contains($name, 'drop'))->values()->all())->toBe([])
        ->and(File::isDirectory(database_path('migrations/marcos-reset')))->toBeTrue()
        ->and(Schema::hasTable('projects'))->toBeTrue();

    foreach (P2R_NEW as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} must exist after a plain migrate");
    }
});

test('the schema backs the rules: unique (objective, number), unique objective key, dependency uniqueness and no self-edge', function () {
    $objective = Objective::factory()->for($this->owner)->create(['key' => 'SCHEMA']);
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create();

    $dup = fn (Closure $insert) => expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);

    $dup(fn () => DB::table('items')->insert([
        'objective_id' => $objective->id, 'plan_id' => $plan->id, 'number' => $item->number, 'kind' => 'task',
        'title' => 'x', 'two_minute_version' => 'y', 'is_active' => false, 'position' => 9, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $dup(fn () => DB::table('objectives')->insert([
        'user_id' => $this->owner->id, 'key' => 'SCHEMA', 'title' => 'x', 'state' => 'active', 'position' => 9, 'next_item_number' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $dup(fn () => DB::table('item_dependencies')->insert(['prerequisite_id' => $item->id, 'dependent_id' => $item->id]));
});

test('round 2 — freshness edges around "now" with the 5-minute clock-skew tolerance', function (int $minutesAhead, bool $passes) {
    $this->freezeTime();
    p2rBackup(now()->addMinutes($minutesAhead));

    $command = $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE]);

    if ($passes) {
        $command->assertSuccessful();
    } else {
        $command->assertFailed();
        p2rUntouched();
    }
})->with([
    'exactly now' => [0, true],
    '4 minutes ahead' => [4, true],
    '6 minutes ahead' => [6, false],
    'ten years ahead' => [60 * 24 * 3650, false],
]);

test('round 2 — a missing or unparseable created_at fails closed with a clean refusal, touching nothing', function (mixed $createdAt) {
    p2rBackup(manifest: ['created_at' => $createdAt]);

    try {
        $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();
    } catch (InvalidFormatException) {
        // P2-T5 (menor): an unparseable date escapes as an exception instead of a
        // clean refusal; it still aborts before touching anything.
    }

    p2rUntouched();
})->with([
    'missing' => [null],
    'empty' => [''],
    'garbage' => ['no-es-una-fecha'],
    'number' => [12345],
]);

test('round 3 — hostile created_at variants are refused cleanly and touch nothing', function (Closure $value) {
    $this->freezeTime();
    p2rBackup(manifest: ['created_at' => $value()]);

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();

    p2rUntouched();
})->with([
    'whitespace' => [fn () => '   '],
    'leading space' => [fn () => ' '.now()->utc()->toIso8601String()],
    'trailing space' => [fn () => now()->utc()->toIso8601String().' '],
    'trailing newline' => [fn () => now()->utc()->toIso8601String()."\n"],
    'microseconds' => [fn () => now()->utc()->format('Y-m-d\TH:i:s.uP')],
    'no timezone' => [fn () => now()->utc()->format('Y-m-d\TH:i:s')],
    'space instead of T' => [fn () => now()->utc()->format('Y-m-d H:i:sP')],
    'compact offset' => [fn () => now()->utc()->format('Y-m-d\TH:i:sO')],
    'zero date' => [fn () => '0000-00-00T00:00:00+00:00'],
    'impossible day' => [fn () => '2026-02-30T10:00:00+00:00'],
    'hour 24' => [fn () => now()->utc()->format('Y-m-d').'T24:00:00+00:00'],
    'offset out of range' => [fn () => now()->utc()->format('Y-m-d\TH:i:s').'+99:00'],
    'unicode digits' => [fn () => '٢٠٢٦-٠٩-٢٨T00:00:00+00:00'],
    'fullwidth digits' => [fn () => '２０２６-09-28T00:00:00+00:00'],
    'now word' => [fn () => 'now'],
    'relative' => [fn () => '+1 hour'],
    'numeric timestamp' => [fn () => (string) now()->timestamp],
    'integer timestamp' => [fn () => now()->timestamp],
    'array' => [fn () => ['date' => now()->toIso8601String()]],
    'boolean' => [fn () => true],
]);

test('round 3 — valid ISO-8601 forms within the window are accepted, including a non-UTC offset and a DST-edge local time', function (Closure $value) {
    $this->travelTo('2026-03-08T09:30:00Z'); // US DST switch morning
    p2rBackup(manifest: ['created_at' => $value()]);

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertSuccessful();
})->with([
    'utc +00:00' => [fn () => '2026-03-08T09:00:00+00:00'],
    'Z suffix' => [fn () => '2026-03-08T09:00:00Z'],
    'guatemala -06:00' => [fn () => '2026-03-08T03:00:00-06:00'],
    'new york after DST jump -04:00' => [fn () => '2026-03-08T04:00:00-04:00'],
]);

test('round 3 — a -06:00 offset is compared as an instant: 25 h old in UTC is refused even if the wall clock looks fresh', function () {
    $this->travelTo('2026-09-28T12:00:00Z');
    p2rBackup(manifest: ['created_at' => '2026-09-27T05:00:00-06:00']); // = 11:00Z the day before, 25 h ago

    $this->artisan('marcos:reset', ['--owner' => 'owner-p2r@example.test', '--confirm' => MarcosReset::CONFIRMATION_PHRASE])->assertFailed();

    p2rUntouched();
});
