<?php

namespace App\Console\LegacyBackup;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

/**
 * Runs `pg_dump` / `pg_restore` against the application's PostgreSQL
 * server. Arguments are passed as an argv array (never through a shell)
 * and the password travels in the child's `PGPASSWORD` environment
 * variable, so it never appears in the process list or in the output.
 */
final class PostgresClient
{
    /**
     * @param  array<string, mixed>  $config  A parsed Laravel `pgsql` connection config.
     */
    public function __construct(private readonly array $config) {}

    public static function for(Connection $connection): self
    {
        return new self($connection->getConfig());
    }

    /**
     * Write a custom-format dump of the configured database, optionally
     * pinned to an exported snapshot so it matches the manifest counts.
     */
    public function dump(string $file, ?string $snapshot = null): ProcessResult
    {
        $command = [
            Config::string('legacy_backup.pg_dump'),
            '--format=custom',
            '--no-password',
            '--file='.$file,
            ...$this->connectionArguments((string) $this->config['database']),
        ];

        if ($snapshot !== null) {
            $command[] = '--snapshot='.$snapshot;
        }

        return $this->run($command);
    }

    /**
     * Restore a custom-format dump into `$database`.
     */
    public function restore(string $file, string $database): ProcessResult
    {
        return $this->run([
            Config::string('legacy_backup.pg_restore'),
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            '--no-password',
            ...$this->connectionArguments($database),
            $file,
        ]);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ProcessResult
    {
        return Process::env($this->environment())
            ->timeout(Config::integer('legacy_backup.timeout'))
            ->run($command);
    }

    /**
     * @return list<string>
     */
    private function connectionArguments(string $database): array
    {
        return [
            '--host='.$this->config['host'],
            '--port='.$this->config['port'],
            '--username='.$this->config['username'],
            '--dbname='.$database,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return array_filter([
            'PGPASSWORD' => (string) ($this->config['password'] ?? ''),
            'PGSSLMODE' => (string) ($this->config['sslmode'] ?? ''),
        ], fn (string $value): bool => $value !== '');
    }
}
