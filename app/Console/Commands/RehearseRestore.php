<?php

namespace App\Console\Commands;

use App\Console\LegacyBackup\BackupConnections;
use App\Console\LegacyBackup\BackupPath;
use App\Console\LegacyBackup\ManifestVerifier;
use App\Console\LegacyBackup\PostgresClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves a backup can actually be restored: the dump is restored into a
 * throwaway scratch database, every table's row count is compared with the
 * manifest, the result is written to `rehearsal.json` next to the manifest,
 * and the scratch database is dropped whatever happens.
 */
#[Signature('marcos:rehearse-restore {backup : Carpeta del respaldo (ruta absoluta o nombre dentro de legacy_backup.path)}')]
#[Description('Ensaya la restauración de un respaldo en una base temporal y compara las filas con el manifiesto.')]
class RehearseRestore extends Command
{
    public const RESULT_FILE = 'rehearsal.json';

    private const ADMIN_CONNECTION = 'legacy_backup_admin';

    private const SCRATCH_CONNECTION = 'legacy_backup_scratch';

    /**
     * Execute the console command.
     */
    public function handle(ManifestVerifier $verifier): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->components->error('El ensayo requiere PostgreSQL (pg_restore).');

            return self::FAILURE;
        }

        $directory = $this->resolveBackup((string) $this->argument('backup'));
        $manifest = ManifestVerifier::read($directory);

        if ($manifest === null) {
            $this->components->error("No hay un manifiesto válido en {$directory}.");

            return self::FAILURE;
        }

        $result = [
            'rehearsed_at' => now()->utc()->toIso8601String(),
            'passed' => false,
            'dump_sha256' => $manifest['dump']['sha256'] ?? null,
            'scratch_database' => null,
            'scratch_dropped' => null,
            'tables' => [],
            'problems' => [],
        ];

        if (($manifest['verified'] ?? false) !== true) {
            $result['problems'][] = 'El respaldo no está verificado: no se ensaya.';

            return $this->finish($directory, $result);
        }

        $integrity = $verifier->verify($directory, $manifest);

        if ($integrity !== []) {
            $result['problems'] = $integrity;

            return $this->finish($directory, $result);
        }

        $scratch = 'marcos_rehearsal_'.now()->utc()->format('Ymd_His').'_'.Str::lower(Str::random(6));
        $result['scratch_database'] = $scratch;

        $admin = BackupConnections::clone(self::ADMIN_CONNECTION);

        try {
            $admin->statement("CREATE DATABASE \"{$scratch}\"");

            $this->components->info("Restaurando en la base temporal {$scratch}");

            $restore = PostgresClient::for($admin)->restore($directory.'/'.$manifest['dump']['file'], $scratch);

            if ($restore->failed()) {
                $result['problems'][] = 'pg_restore terminó con código '.$restore->exitCode().': '.trim($restore->errorOutput());
            } else {
                $result['tables'] = $this->compareCounts($scratch, $manifest['tables']);

                foreach ($result['tables'] as $table => $counts) {
                    if ($counts['restored'] !== $counts['expected']) {
                        $restored = $counts['restored'] ?? 'ninguna (la tabla no existe)';
                        $result['problems'][] = "La tabla {$table} debería tener {$counts['expected']} filas y el ensayo restauró {$restored}.";
                    }
                }
            }
        } catch (Throwable $exception) {
            $result['problems'][] = 'El ensayo se interrumpió: '.$exception->getMessage();
        } finally {
            BackupConnections::forget(self::SCRATCH_CONNECTION);

            try {
                $admin->statement("DROP DATABASE IF EXISTS \"{$scratch}\" WITH (FORCE)");
                $result['scratch_dropped'] = true;
            } catch (Throwable $exception) {
                $result['scratch_dropped'] = false;
                $result['problems'][] = "No se pudo borrar la base temporal {$scratch}: ".$exception->getMessage();
            }

            BackupConnections::forget(self::ADMIN_CONNECTION);
        }

        $result['passed'] = $result['problems'] === [];

        return $this->finish($directory, $result);
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     * @return array<string, array{expected: int, restored: int|null}>
     */
    private function compareCounts(string $scratch, array $tables): array
    {
        $connection = BackupConnections::clone(self::SCRATCH_CONNECTION, ['database' => $scratch]);
        $schema = $connection->getSchemaBuilder();
        $restoredTables = $schema->getTableListing($schema->getCurrentSchemaName(), false);

        $counts = [];

        foreach ($tables as $table => $entry) {
            $counts[$table] = [
                'expected' => (int) $entry['rows'],
                'restored' => in_array($table, $restoredTables, true) ? $connection->table($table)->count() : null,
            ];
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(string $directory, array $result): int
    {
        $path = $directory.'/'.self::RESULT_FILE;

        file_put_contents($path, json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");

        @chmod($path, 0600);

        if ($result['passed'] === true) {
            $this->components->info('Ensayo aprobado: la restauración coincide con el manifiesto.');

            return self::SUCCESS;
        }

        foreach ($result['problems'] as $problem) {
            $this->components->error($problem);
        }

        $this->components->warn("Ensayo FALLIDO. Resultado guardado en {$path}.");

        return self::FAILURE;
    }

    private function resolveBackup(string $backup): string
    {
        if (str_contains($backup, '/')) {
            return BackupPath::resolve($backup);
        }

        return BackupPath::resolve(Config::string('legacy_backup.path').'/'.$backup);
    }
}
