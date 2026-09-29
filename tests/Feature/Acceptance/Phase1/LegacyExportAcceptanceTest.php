<?php

/*
| Phase 1 acceptance (independent tester) — spec `legacy-data-export`.
|
| Derived from the spec, not from the implementation. `pg_dump` / `pg_restore`
| run as separate processes and only see COMMITTED rows, so fixtures are
| written through a dedicated committing connection and removed afterwards.
*/

use App\Console\LegacyBackup\ManifestVerifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

const P1A_CONN = 'p1a_acceptance_seed';

const P1A_EMAIL = 'p1a-owner@example.test';

const P1A_SECRETS = [
    'password' => '$2y$12$P1ASECRETPASSWORDHASHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'remember' => 'P1A-SECRET-REMEMBER-TOKEN',
    'pat' => 'p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0p1a0',
    'qr' => 'p1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aqrp1aq',
    'session_id' => 'P1A-SECRET-SESSION-ID',
    'session_payload' => 'P1A-SECRET-SESSION-PAYLOAD',
    'reset' => 'P1A-SECRET-RESET-TOKEN-HASH',
];

/** Tables the spec names explicitly: each MUST get its own JSON file. */
const P1A_SPEC_TABLES = [
    'users', 'projects', 'project_members', 'sprints', 'board_columns', 'issues', 'labels',
    'issue_label', 'comments', 'habits', 'habit_entries', 'habit_days', 'qr_login_passes',
    'personal_access_tokens',
];

function p1aDb(): Connection
{
    Config::set('database.connections.'.P1A_CONN, [...DB::connection()->getConfig(), 'name' => P1A_CONN]);

    return DB::connection(P1A_CONN);
}

function p1aSeed(): int
{
    $db = p1aDb();
    $now = now()->utc()->toDateTimeString();

    $userId = $db->table('users')->insertGetId([
        'name' => 'P1A Owner',
        'email' => P1A_EMAIL,
        'password' => P1A_SECRETS['password'],
        'remember_token' => P1A_SECRETS['remember'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->table('personal_access_tokens')->insert([
        'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => $userId, 'name' => 'mobile',
        'token' => P1A_SECRETS['pat'], 'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('qr_login_passes')->insert([
        'user_id' => $userId, 'token_hash' => P1A_SECRETS['qr'], 'expires_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->table('sessions')->insert([
        'id' => P1A_SECRETS['session_id'], 'user_id' => $userId,
        'payload' => P1A_SECRETS['session_payload'], 'last_activity' => time(),
    ]);
    $db->table('password_reset_tokens')->insert([
        'email' => P1A_EMAIL, 'token' => P1A_SECRETS['reset'], 'created_at' => $now,
    ]);

    foreach (['P1A hábito uno', 'P1A hábito dos', 'P1A hábito tres'] as $name) {
        $db->table('habits')->insert([
            'user_id' => $userId, 'name' => $name, 'habit_type' => 'boolean',
            'recurrence_type' => 'daily', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return $userId;
}

function p1aClean(): void
{
    $db = p1aDb();
    $ids = $db->table('users')->where('email', P1A_EMAIL)->pluck('id');

    $db->table('habits')->whereIn('user_id', $ids)->delete();
    $db->table('sessions')->where('id', P1A_SECRETS['session_id'])->delete();
    $db->table('password_reset_tokens')->where('email', P1A_EMAIL)->delete();
    $db->table('qr_login_passes')->whereIn('user_id', $ids)->delete();
    $db->table('personal_access_tokens')->where('token', P1A_SECRETS['pat'])->delete();
    $db->table('users')->whereIn('id', $ids)->delete();

    foreach (p1aScratchDatabases() as $scratch) {
        $db->statement("DROP DATABASE IF EXISTS \"{$scratch}\" WITH (FORCE)");
    }

    DB::purge(P1A_CONN);
}

function p1aScratchDatabases(): array
{
    return p1aDb()->table('pg_database')->where('datname', 'like', 'marcos_rehearsal_%')->pluck('datname')->all();
}

function p1aBackup(string $base): string
{
    $dirs = File::directories($base);
    expect($dirs)->toHaveCount(1);

    return $dirs[0];
}

function p1aJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/p1a-acceptance-'.bin2hex(random_bytes(5));
    $this->userId = p1aSeed();
});

afterEach(function () {
    p1aClean();
    File::deleteDirectory($this->base);
});

test('every table the spec names gets its own JSON file, and every file is listed with a correct SHA-256', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);
    $manifest = p1aJson($backup.'/manifest.json');

    foreach (P1A_SPEC_TABLES as $table) {
        expect($manifest['tables'])->toHaveKey($table);
        expect($manifest['tables'][$table]['json'])->not->toBeNull("{$table} must have a JSON export");
        expect(is_file($backup.'/'.$manifest['tables'][$table]['json']['file']))->toBeTrue();
    }

    // Every file on disk (except the manifest itself / rehearsal) is covered by a checksum.
    $listed = collect($manifest['tables'])->pluck('json.file')->filter()->push($manifest['dump']['file'])->sort()->values()->all();
    $onDisk = collect(File::allFiles($backup))
        ->map(fn ($f) => ltrim(str_replace($backup, '', $f->getPathname()), '/'))
        ->reject(fn ($f) => $f === 'manifest.json')
        ->sort()->values()->all();

    expect($onDisk)->toBe($listed);

    foreach ($listed as $file) {
        $recorded = $file === $manifest['dump']['file']
            ? $manifest['dump']['sha256']
            : collect($manifest['tables'])->firstWhere('json.file', $file)['json']['sha256'];

        expect(hash_file('sha256', $backup.'/'.$file))->toBe($recorded, "sha256 of {$file}");
    }
});

test('manifest row counts equal the live counts and the JSON holds exactly those rows', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);
    $manifest = p1aJson($backup.'/manifest.json');
    $db = p1aDb();

    expect($manifest['verified'])->toBeTrue();

    foreach ($manifest['tables'] as $table => $entry) {
        expect($entry['rows'])->toBe($db->table($table)->count(), "live rows of {$table}");
    }

    $habits = collect(p1aJson($backup.'/tables/habits.json'))->where('user_id', $this->userId);
    expect($habits->pluck('name')->sort()->values()->all())
        ->toBe(['P1A hábito dos', 'P1A hábito tres', 'P1A hábito uno']);
});

test('no password, token or session secret appears in any JSON file, the manifest or the rehearsal record', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);
    $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(0);

    $text = collect(File::allFiles($backup))
        ->reject(fn ($f) => $f->getFilename() === 'database.dump')
        ->map(fn ($f) => $f->getContents())
        ->implode("\n");

    foreach (P1A_SECRETS as $label => $secret) {
        expect($text)->not->toContain($secret, "secret {$label} leaked");
    }

    // Structural check too: no column in any JSON row is a known secret column.
    foreach (File::files($backup.'/tables') as $file) {
        foreach (p1aJson($file->getPathname()) as $row) {
            expect($row)->not->toHaveKeys(['password', 'remember_token', 'token_hash'])
                ->and(array_key_exists('token', $row) && $file->getFilename() === 'personal_access_tokens.json')->toBeFalse();
        }
    }

    // Personal access token METADATA is still there (spec: "metadata without token hashes").
    $pat = collect(p1aJson($backup.'/tables/personal_access_tokens.json'))->firstWhere('tokenable_id', $this->userId);
    expect($pat)->not->toBeNull()->and($pat['name'])->toBe('mobile');

    $user = collect(p1aJson($backup.'/tables/users.json'))->firstWhere('email', P1A_EMAIL);
    expect($user)->not->toBeNull()->and($user['name'])->toBe('P1A Owner');
});

test('the native dump is a restorable custom-format archive that still carries the users table', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);

    expect(file_get_contents($backup.'/database.dump', length: 5))->toBe('PGDMP');

    $list = shell_exec('pg_restore --list '.escapeshellarg($backup.'/database.dump').' 2>&1');
    expect($list)->toContain('TABLE DATA public users')
        ->toContain('TABLE DATA public habits');
});

test('tampering with an exported JSON file is detected by the verifier and blocks the rehearsal without creating a scratch DB', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);

    $rows = p1aJson($backup.'/tables/habits.json');
    array_pop($rows);
    file_put_contents($backup.'/tables/habits.json', json_encode($rows));

    $problems = app(ManifestVerifier::class)->verify($backup, p1aJson($backup.'/manifest.json'));
    expect($problems)->not->toBe([]);

    $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(1);

    $result = p1aJson($backup.'/rehearsal.json');
    expect($result['passed'])->toBeFalse()
        ->and($result['scratch_database'])->toBeNull()
        ->and(p1aScratchDatabases())->toBe([]);
});

test('a JSON file whose checksum was re-forged but whose row count differs still fails verification', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);

    $rows = p1aJson($backup.'/tables/habits.json');
    array_pop($rows);
    file_put_contents($backup.'/tables/habits.json', json_encode($rows));

    $manifest = p1aJson($backup.'/manifest.json');
    $manifest['tables']['habits']['json']['sha256'] = hash_file('sha256', $backup.'/tables/habits.json');

    $problems = app(ManifestVerifier::class)->verify($backup, $manifest);

    expect(implode(' ', $problems))->toContain('habits');
});

test('output paths inside the repository are refused with a non-zero exit and nothing is written', function (string $relative) {
    $target = base_path($relative);
    $before = File::exists(base_path('storage/app')) ? count(File::allFiles(base_path('storage/app'))) : 0;

    $this->artisan('marcos:export-legacy', ['--out' => $target])->assertExitCode(1);

    expect(file_exists($target))->toBeFalse();
    expect(File::exists(base_path('storage/app')) ? count(File::allFiles(base_path('storage/app'))) : 0)->toBe($before);
})->with([
    'deep non-existing path' => 'storage/app/p1a/deep/nested',
    'tests folder' => 'tests/p1a-backups',
    'trailing slash' => 'p1a-backups/',
    'dot segments' => 'public/./../storage/../p1a-backups',
]);

test('a sibling directory that merely shares the repo path prefix is NOT treated as inside the repo', function () {
    $sibling = base_path().'-p1a-sibling-'.bin2hex(random_bytes(3));

    try {
        $this->artisan('marcos:export-legacy', ['--out' => $sibling])->assertExitCode(0);
        expect(p1aJson(p1aBackup($sibling).'/manifest.json')['verified'])->toBeTrue();
    } finally {
        File::deleteDirectory($sibling);
    }
});

test('rehearse-restore really restores into a scratch DB, the counts match the manifest, the result is recorded next to it and the DB is dropped', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);
    $manifest = p1aJson($backup.'/manifest.json');

    $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(0);

    expect(is_file($backup.'/rehearsal.json'))->toBeTrue();
    $result = p1aJson($backup.'/rehearsal.json');

    expect($result['passed'])->toBeTrue()
        ->and($result['scratch_dropped'])->toBeTrue()
        ->and($result['scratch_database'])->not->toBe(p1aDb()->getDatabaseName())
        ->and(p1aScratchDatabases())->toBe([]);

    foreach ($manifest['tables'] as $table => $entry) {
        expect($result['tables'][$table]['restored'])->toBe($entry['rows'], "restored rows of {$table}");
    }

    expect($result['tables']['habits']['restored'])->toBeGreaterThanOrEqual(3)
        ->and($result['tables']['users']['restored'])->toBeGreaterThanOrEqual(1);

    // The live database is untouched by the rehearsal.
    expect(p1aDb()->table('habits')->where('user_id', $this->userId)->count())->toBe(3);
});

test('a failing pg_restore is recorded as failed and the scratch database is still dropped', function () {
    $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
    $backup = p1aBackup($this->base);

    Config::set('legacy_backup.pg_restore', '/nonexistent/pg_restore');

    $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(1);

    $result = p1aJson($backup.'/rehearsal.json');
    expect($result['passed'])->toBeFalse()
        ->and($result['scratch_dropped'])->toBeTrue()
        ->and(p1aScratchDatabases())->toBe([]);
});

test('rehearsing a folder without a manifest exits non-zero', function () {
    File::ensureDirectoryExists($this->base.'/empty');

    $this->artisan('marcos:rehearse-restore', ['backup' => $this->base.'/empty'])->assertExitCode(1);
    expect(p1aScratchDatabases())->toBe([]);
});
