<?php

namespace App\Console\LegacyBackup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Dedicated connections for the backup commands, cloned from the default
 * connection. The export reads through its own connection so it can hold a
 * read-only REPEATABLE READ snapshot shared with `pg_dump`; the rehearsal
 * needs one connection for CREATE/DROP DATABASE and one inside the scratch
 * database.
 */
final class BackupConnections
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function clone(string $name, array $overrides = []): Connection
    {
        Config::set("database.connections.{$name}", [...DB::connection()->getConfig(), ...$overrides, 'name' => $name]);

        DB::purge($name);

        return DB::connection($name);
    }

    public static function forget(string $name): void
    {
        DB::purge($name);

        Config::set("database.connections.{$name}", null);
    }
}
