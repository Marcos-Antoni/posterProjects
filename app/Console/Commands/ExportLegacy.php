<?php

namespace App\Console\Commands;

use App\Console\LegacyBackup\BackupConnections;
use App\Console\LegacyBackup\BackupPath;
use App\Console\LegacyBackup\ManifestVerifier;
use App\Console\LegacyBackup\PostgresClient;
use App\Console\LegacyBackup\TableJsonWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Exports every row of the legacy database to a timestamped backup folder:
 * a native `pg_dump` (custom format), one JSON file per table without
 * password/token hashes, and a manifest with live row counts and SHA-256
 * checksums. The dump and the counts share one exported snapshot, so they
 * describe the exact same state. The command then verifies the manifest
 * and exits non-zero, leaving the backup marked unverified, on any
 * mismatch.
 */
#[Signature('marcos:export-legacy {--out= : Carpeta base del respaldo (por defecto config legacy_backup.path)}')]
#[Description('Exporta todos los datos actuales a un respaldo verificado (volcado + JSON por tabla + manifiesto).')]
class ExportLegacy extends Command
{
    private const CONNECTION = 'legacy_backup_export';

    /**
     * Execute the console command.
     */
    public function handle(TableJsonWriter $writer, ManifestVerifier $verifier): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->components->error('El respaldo requiere PostgreSQL (pg_dump).');

            return self::FAILURE;
        }

        $base = BackupPath::resolve((string) ($this->option('out') ?: Config::string('legacy_backup.path')));

        if (BackupPath::isInside($base, base_path())) {
            $this->components->error("La carpeta {$base} está dentro del repositorio. Los respaldos nunca entran en git: elegí una ruta fuera de ".base_path().'.');

            return self::FAILURE;
        }

        $directory = $base.'/'.now()->utc()->format('Y-m-d_His');

        if (file_exists($directory)) {
            $this->components->error("Ya existe un respaldo en {$directory}.");

            return self::FAILURE;
        }

        if (! @mkdir($directory.'/tables', 0700, true)) {
            $this->components->error("No se pudo crear {$directory}.");

            return self::FAILURE;
        }

        @chmod($directory, 0700);

        $this->components->info("Exportando a {$directory}");

        try {
            $manifest = $this->export($directory, $writer);
        } catch (Throwable $exception) {
            ManifestVerifier::write($directory, [
                'format' => 1,
                'created_at' => now()->utc()->toIso8601String(),
                'verified' => false,
                'problems' => ['La exportación se interrumpió: '.$exception->getMessage()],
            ]);

            $this->components->error('La exportación falló: '.$exception->getMessage());
            $this->components->warn('El respaldo quedó marcado como NO verificado.');

            return self::FAILURE;
        } finally {
            BackupConnections::forget(self::CONNECTION);
        }

        $problems = $verifier->verify($directory, $manifest);

        $manifest['verified'] = $problems === [];
        $manifest['verified_at'] = $problems === [] ? now()->utc()->toIso8601String() : null;
        $manifest['problems'] = $problems;

        ManifestVerifier::write($directory, $manifest);

        $rows = [];

        foreach ($manifest['tables'] as $table => $entry) {
            $rows[] = [
                $table,
                $entry['rows'],
                $entry['json'] === null ? 'solo en el volcado' : $entry['json']['rows'],
            ];
        }

        $this->table(['Tabla', 'Filas', 'JSON'], $rows);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            $this->components->warn('El respaldo quedó marcado como NO verificado.');

            return self::FAILURE;
        }

        $this->components->info("Respaldo verificado: {$directory}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function export(string $directory, TableJsonWriter $writer): array
    {
        $connection = BackupConnections::clone(self::CONNECTION);

        $connection->beginTransaction();

        try {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            $snapshot = (string) $connection->scalar('SELECT pg_export_snapshot()');

            $dumpFile = $directory.'/database.dump';
            $dump = PostgresClient::for($connection)->dump($dumpFile, $snapshot);

            if ($dump->failed()) {
                throw new \RuntimeException('pg_dump terminó con código '.$dump->exitCode().': '.trim($dump->errorOutput()));
            }

            @chmod($dumpFile, 0600);

            $tables = $this->exportTables($connection, $directory, $writer);

            $serverVersion = (string) $connection->scalar('SHOW server_version');
        } finally {
            $connection->rollBack();
        }

        return [
            'format' => 1,
            'created_at' => now()->utc()->toIso8601String(),
            'app' => [
                'env' => app()->environment(),
                'url' => config('app.url'),
            ],
            'database' => [
                'name' => $connection->getDatabaseName(),
                'server_version' => $serverVersion,
                'snapshot' => $snapshot,
            ],
            'dump' => [
                'file' => 'database.dump',
                'format' => 'custom',
                'bytes' => filesize($dumpFile),
                'sha256' => hash_file('sha256', $dumpFile),
            ],
            'tables' => $tables,
            'verified' => false,
            'verified_at' => null,
            'problems' => [],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function exportTables(Connection $connection, string $directory, TableJsonWriter $writer): array
    {
        $schema = $connection->getSchemaBuilder();
        $names = $schema->getTableListing($schema->getCurrentSchemaName(), false);
        sort($names);

        /** @var array<string, string> $excluded */
        $excluded = Config::array('legacy_backup.excluded_tables');
        /** @var array<string, list<string>> $redacted */
        $redacted = Config::array('legacy_backup.redacted_columns');

        $tables = [];

        foreach ($names as $table) {
            $rows = $connection->table($table)->count();

            if (array_key_exists($table, $excluded)) {
                $tables[$table] = [
                    'rows' => $rows,
                    'json' => null,
                    'excluded_reason' => $excluded[$table],
                ];

                continue;
            }

            $file = "tables/{$table}.json";
            $path = $directory.'/'.$file;
            $columns = $redacted[$table] ?? [];

            $written = $writer->write($connection, $table, $path, $columns);

            @chmod($path, 0600);

            $tables[$table] = [
                'rows' => $rows,
                'json' => [
                    'file' => $file,
                    'rows' => $written,
                    'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                    'redacted_columns' => $columns,
                ],
            ];
        }

        return $tables;
    }
}
