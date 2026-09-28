<?php

use App\Console\LegacyBackup\BackupConnections;
use App\Console\LegacyBackup\PostgresClient;
use App\Console\LegacyBackup\TableJsonWriter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| These tests run the real `pg_dump` / `pg_restore` against the test
| database. Both are separate processes, so they only see COMMITTED rows:
| the fixtures below are written through a dedicated connection that
| commits (outside RefreshDatabase's transaction) and are deleted again in
| `afterEach`, leaving the shared test database exactly as it was.
*/

const LEGACY_SEED_CONNECTION = 'legacy_backup_test_seed';

function legacySeed(): Connection
{
    return BackupConnections::clone(LEGACY_SEED_CONNECTION);
}

/**
 * @return array{user: int, habits: list<int>}
 */
function seedLegacyData(): array
{
    $db = legacySeed();
    $now = now()->utc()->toDateTimeString();

    $userId = $db->table('users')->insertGetId([
        'name' => 'Marco Ñandú',
        'email' => 'marco-backup@example.com',
        'password' => '$2y$04$SECRETPASSWORDHASHSECRETPASSWORDHASHSECRETPASSWORDHAS',
        'remember_token' => 'SECRET-REMEMBER-TOKEN',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->table('personal_access_tokens')->insert([
        'tokenable_type' => 'App\\Models\\User',
        'tokenable_id' => $userId,
        'name' => 'mcp',
        'token' => str_repeat('a', 64),
        'abilities' => '["mcp"]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->table('qr_login_passes')->insert([
        'user_id' => $userId,
        'token_hash' => str_repeat('b', 64),
        'expires_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->table('sessions')->insert([
        'id' => 'SECRET-SESSION-ID',
        'user_id' => $userId,
        'payload' => 'SECRET-SESSION-PAYLOAD',
        'last_activity' => time(),
    ]);

    $habits = [];

    foreach (['Gimnasio 06:30', 'Leer 2 páginas de Control'] as $name) {
        $habits[] = $db->table('habits')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'habit_type' => 'boolean',
            'recurrence_type' => 'daily',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return ['user' => $userId, 'habits' => $habits];
}

function cleanLegacyData(): void
{
    $db = legacySeed();

    $db->table('habits')->where('user_id', function ($query) {
        $query->select('id')->from('users')->where('email', 'marco-backup@example.com');
    })->delete();
    $db->table('sessions')->where('id', 'SECRET-SESSION-ID')->delete();
    $db->table('qr_login_passes')->where('token_hash', str_repeat('b', 64))->delete();
    $db->table('personal_access_tokens')->where('token', str_repeat('a', 64))->delete();
    $db->table('users')->where('email', 'marco-backup@example.com')->delete();

    foreach ($db->table('pg_database')->where('datname', 'like', 'marcos_rehearsal_%')->pluck('datname') as $scratch) {
        $db->statement("DROP DATABASE IF EXISTS \"{$scratch}\" WITH (FORCE)");
    }

    BackupConnections::forget(LEGACY_SEED_CONNECTION);
}

function onlyBackupIn(string $base): string
{
    $folders = File::directories($base);

    expect($folders)->toHaveCount(1);

    return $folders[0];
}

/**
 * @return array<string, mixed>
 */
function readJsonFile(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function scratchDatabases(): array
{
    return legacySeed()->table('pg_database')->where('datname', 'like', 'marcos_rehearsal_%')->pluck('datname')->all();
}

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/marcos-backup-test-'.bin2hex(random_bytes(6));
    $this->seeded = seedLegacyData();
});

afterEach(function () {
    cleanLegacyData();
    File::deleteDirectory($this->base);
});

describe('marcos:export-legacy', function () {
    test('writes a custom-format dump, one JSON per table and a verified manifest with counts and checksums', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);

        $backup = onlyBackupIn($this->base);

        expect(basename($backup))->toMatch('/^\d{4}-\d{2}-\d{2}_\d{6}$/')
            ->and(file_get_contents($backup.'/database.dump', length: 5))->toBe('PGDMP');

        $manifest = readJsonFile($backup.'/manifest.json');

        expect($manifest['verified'])->toBeTrue()
            ->and($manifest['problems'])->toBe([])
            ->and($manifest['dump']['format'])->toBe('custom')
            ->and($manifest['dump']['sha256'])->toBe(hash_file('sha256', $backup.'/database.dump'));

        $db = legacySeed();
        $schema = $db->getSchemaBuilder();
        $liveTables = $schema->getTableListing($schema->getCurrentSchemaName(), false);

        expect(array_keys($manifest['tables']))->toEqualCanonicalizing($liveTables);

        foreach ($manifest['tables'] as $table => $entry) {
            expect($entry['rows'])->toBe($db->table($table)->count(), "live count of {$table}");

            if ($entry['json'] === null) {
                expect(file_exists("{$backup}/tables/{$table}.json"))->toBeFalse();

                continue;
            }

            $path = $backup.'/'.$entry['json']['file'];

            expect($entry['json']['sha256'])->toBe(hash_file('sha256', $path))
                ->and(readJsonFile($path))->toHaveCount($entry['rows']);
        }

        expect($manifest['tables']['users']['rows'])->toBe(1)
            ->and($manifest['tables']['habits']['rows'])->toBe(2)
            ->and(readJsonFile($backup.'/tables/habits.json')[1]['name'])->toBe('Leer 2 páginas de Control');
    });

    test('never writes password hashes, token hashes or session identifiers into the JSON files', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);

        $backup = onlyBackupIn($this->base);

        $users = readJsonFile($backup.'/tables/users.json');
        expect($users[0])->not->toHaveKeys(['password', 'remember_token'])
            ->and($users[0]['email'])->toBe('marco-backup@example.com');

        expect(readJsonFile($backup.'/tables/personal_access_tokens.json')[0])->not->toHaveKey('token')
            ->toHaveKey('name', 'mcp');
        expect(readJsonFile($backup.'/tables/qr_login_passes.json')[0])->not->toHaveKey('token_hash');

        expect(file_exists($backup.'/tables/sessions.json'))->toBeFalse()
            ->and(file_exists($backup.'/tables/password_reset_tokens.json'))->toBeFalse();

        $manifest = readJsonFile($backup.'/manifest.json');
        expect($manifest['tables']['sessions']['json'])->toBeNull()
            ->and($manifest['tables']['sessions']['rows'])->toBe(1);

        $allJson = collect(File::allFiles($backup.'/tables'))->map(fn ($file) => $file->getContents())->implode('');
        $manifestText = file_get_contents($backup.'/manifest.json');

        foreach (['SECRETPASSWORDHASH', 'SECRET-REMEMBER-TOKEN', str_repeat('a', 64), str_repeat('b', 64), 'SECRET-SESSION'] as $secret) {
            expect($allJson)->not->toContain($secret)
                ->and($manifestText)->not->toContain($secret);
        }
    });

    test('keeps the backup private to its owner on disk', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);

        $backup = onlyBackupIn($this->base);

        expect(fileperms($backup) & 0777)->toBe(0700)
            ->and(fileperms($backup.'/database.dump') & 0777)->toBe(0600)
            ->and(fileperms($backup.'/manifest.json') & 0777)->toBe(0600)
            ->and(fileperms($backup.'/tables/users.json') & 0777)->toBe(0600);
    });

    test('uses the configured backup path when no --out is given', function () {
        Config::set('legacy_backup.path', $this->base);

        $this->artisan('marcos:export-legacy')->assertExitCode(0);

        expect(readJsonFile(onlyBackupIn($this->base).'/manifest.json')['verified'])->toBeTrue();
    });

    test('refuses an output path inside the repository without writing files', function (string $inside) {
        $target = base_path($inside);

        $this->artisan('marcos:export-legacy', ['--out' => $target])
            ->expectsOutputToContain('dentro del repositorio')
            ->assertExitCode(1);

        expect(file_exists($target))->toBeFalse();
    })->with([
        'storage folder' => 'storage/marcos-backups-test',
        'repo root child' => 'marcos-backups-test',
        'dot-dot back into repo' => 'storage/../marcos-backups-test',
    ]);

    test('refuses the repository root itself and relative paths that resolve into it', function () {
        $this->artisan('marcos:export-legacy', ['--out' => base_path()])->assertExitCode(1);

        $cwd = getcwd();
        chdir(base_path());

        try {
            $this->artisan('marcos:export-legacy', ['--out' => 'relative-backups-test'])->assertExitCode(1);
        } finally {
            chdir($cwd);
        }

        expect(file_exists(base_path('relative-backups-test')))->toBeFalse();
    });

    test('refuses a path outside the repo that is a symlink into it', function () {
        File::ensureDirectoryExists($this->base);
        symlink(base_path('storage'), $this->base.'/sneaky');

        $this->artisan('marcos:export-legacy', ['--out' => $this->base.'/sneaky/backups'])->assertExitCode(1);

        expect(file_exists(base_path('storage/backups')))->toBeFalse();
    });

    test('a row-count mismatch fails the export and marks the backup unverified', function () {
        app()->bind(TableJsonWriter::class, fn () => new class extends TableJsonWriter
        {
            public function write(Connection $connection, string $table, string $path, array $redactedColumns): int
            {
                $rows = parent::write($connection, $table, $path, $redactedColumns);

                if ($table === 'habits') {
                    $decoded = json_decode((string) file_get_contents($path), true);
                    array_pop($decoded);
                    file_put_contents($path, json_encode($decoded));

                    return $rows - 1;
                }

                return $rows;
            }
        });

        $this->artisan('marcos:export-legacy', ['--out' => $this->base])
            ->expectsOutputToContain('La tabla habits tiene 2 filas en la base y 1 en el JSON exportado.')
            ->assertExitCode(1);

        $manifest = readJsonFile(onlyBackupIn($this->base).'/manifest.json');

        expect($manifest['verified'])->toBeFalse()
            ->and($manifest['verified_at'])->toBeNull()
            ->and($manifest['problems'])->toContain('La tabla habits tiene 2 filas en la base y 1 en el JSON exportado.');
    });

    test('a failing pg_dump exits non-zero and leaves the backup unverified', function () {
        Config::set('legacy_backup.pg_dump', '/nonexistent/pg_dump');

        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(1);

        expect(readJsonFile(onlyBackupIn($this->base).'/manifest.json')['verified'])->toBeFalse();
    });

    test('never prints the database password', function () {
        $password = (string) config('database.connections.pgsql.password');

        $this->artisan('marcos:export-legacy', ['--out' => $this->base])
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(0);
    })->skip(fn () => config('database.connections.pgsql.password') === '', 'no password configured');
});

describe('marcos:rehearse-restore', function () {
    test('restores into a scratch database, records a passing result and drops the scratch database', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
        $backup = onlyBackupIn($this->base);

        $this->artisan('marcos:rehearse-restore', ['backup' => $backup])
            ->expectsOutputToContain('Ensayo aprobado')
            ->assertExitCode(0);

        $result = readJsonFile($backup.'/rehearsal.json');
        $manifest = readJsonFile($backup.'/manifest.json');

        expect($result['passed'])->toBeTrue()
            ->and($result['problems'])->toBe([])
            ->and($result['scratch_database'])->toStartWith('marcos_rehearsal_')
            ->and($result['scratch_dropped'])->toBeTrue()
            ->and($result['dump_sha256'])->toBe($manifest['dump']['sha256'])
            ->and(array_keys($result['tables']))->toEqualCanonicalizing(array_keys($manifest['tables']));

        foreach ($result['tables'] as $table => $counts) {
            expect($counts['restored'])->toBe($manifest['tables'][$table]['rows'], "restored rows of {$table}");
        }

        expect($result['tables']['habits'])->toBe(['expected' => 2, 'restored' => 2])
            ->and($result['tables']['sessions'])->toBe(['expected' => 1, 'restored' => 1])
            ->and(scratchDatabases())->toBe([]);
    });

    test('accepts a backup folder name relative to the configured backup path', function () {
        Config::set('legacy_backup.path', $this->base);

        $this->artisan('marcos:export-legacy')->assertExitCode(0);
        $backup = onlyBackupIn($this->base);

        $this->artisan('marcos:rehearse-restore', ['backup' => basename($backup)])->assertExitCode(0);

        expect(readJsonFile($backup.'/rehearsal.json')['passed'])->toBeTrue();
    });

    test('a restored row count that differs from the manifest fails, is recorded, and still drops the scratch database', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
        $backup = onlyBackupIn($this->base);

        // A dump that no longer matches the manifest: one more habit than
        // the manifest counted, with a checksum that is internally consistent.
        legacySeed()->table('habits')->insert([
            'user_id' => $this->seeded['user'],
            'name' => 'Dormir 22:00',
            'habit_type' => 'boolean',
            'recurrence_type' => 'daily',
        ]);
        File::delete($backup.'/database.dump');
        PostgresClient::for(legacySeed())->dump($backup.'/database.dump')->throw();

        $manifest = readJsonFile($backup.'/manifest.json');
        $manifest['dump']['sha256'] = hash_file('sha256', $backup.'/database.dump');
        file_put_contents($backup.'/manifest.json', json_encode($manifest));

        $this->artisan('marcos:rehearse-restore', ['backup' => $backup])
            ->expectsOutputToContain('La tabla habits debería tener 2 filas y el ensayo restauró 3.')
            ->assertExitCode(1);

        $result = readJsonFile($backup.'/rehearsal.json');

        expect($result['passed'])->toBeFalse()
            ->and($result['tables']['habits'])->toBe(['expected' => 2, 'restored' => 3])
            ->and($result['scratch_dropped'])->toBeTrue()
            ->and(scratchDatabases())->toBe([]);
    });

    test('refuses to rehearse an unverified backup and records why', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
        $backup = onlyBackupIn($this->base);

        $manifest = readJsonFile($backup.'/manifest.json');
        $manifest['verified'] = false;
        file_put_contents($backup.'/manifest.json', json_encode($manifest));

        $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(1);

        $result = readJsonFile($backup.'/rehearsal.json');

        expect($result['passed'])->toBeFalse()
            ->and($result['scratch_database'])->toBeNull()
            ->and($result['problems'])->toContain('El respaldo no está verificado: no se ensaya.');
    });

    test('refuses a dump whose checksum no longer matches the manifest', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
        $backup = onlyBackupIn($this->base);

        file_put_contents($backup.'/database.dump', 'tampered', FILE_APPEND);

        $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(1);

        $result = readJsonFile($backup.'/rehearsal.json');

        expect($result['passed'])->toBeFalse()
            ->and($result['scratch_database'])->toBeNull()
            ->and($result['problems'])->toContain('El SHA-256 del volcado no coincide con el manifiesto.');
    });

    test('a failing pg_restore is recorded and the scratch database is dropped', function () {
        $this->artisan('marcos:export-legacy', ['--out' => $this->base])->assertExitCode(0);
        $backup = onlyBackupIn($this->base);

        Config::set('legacy_backup.pg_restore', '/nonexistent/pg_restore');

        $this->artisan('marcos:rehearse-restore', ['backup' => $backup])->assertExitCode(1);

        $result = readJsonFile($backup.'/rehearsal.json');

        expect($result['passed'])->toBeFalse()
            ->and($result['scratch_dropped'])->toBeTrue()
            ->and(scratchDatabases())->toBe([]);
    });

    test('fails when the folder has no manifest', function () {
        File::ensureDirectoryExists($this->base.'/empty');

        $this->artisan('marcos:rehearse-restore', ['backup' => $this->base.'/empty'])
            ->expectsOutputToContain('No hay un manifiesto válido')
            ->assertExitCode(1);
    });
});
