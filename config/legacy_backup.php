<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Location
    |--------------------------------------------------------------------------
    |
    | Base directory for `marcos:export-legacy`. Every export creates its own
    | timestamped folder inside it. Backups never enter version control: the
    | commands refuse any path inside the application base path.
    |
    */

    'path' => env('LEGACY_BACKUP_PATH', '/root/backups/posterprojects'),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Client Binaries
    |--------------------------------------------------------------------------
    |
    | The client major version must be greater than or equal to the server's.
    |
    */

    'pg_dump' => env('LEGACY_BACKUP_PG_DUMP', 'pg_dump'),

    'pg_restore' => env('LEGACY_BACKUP_PG_RESTORE', 'pg_restore'),

    /*
    |--------------------------------------------------------------------------
    | Process Timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('LEGACY_BACKUP_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Secrets Stripped From The JSON Export
    |--------------------------------------------------------------------------
    |
    | The native dump keeps every byte (it is what a restore uses). The
    | per-table JSON files are the human-readable copy, so they never carry
    | password hashes, token hashes or session identifiers.
    |
    | `redacted_columns`: columns removed from a table's JSON rows.
    | `excluded_tables`: tables whose rows are credentials or throwaway
    | framework state; they get no JSON file (their rows live only in the
    | dump) but are still counted in the manifest and the rehearsal.
    |
    */

    'redacted_columns' => [
        'users' => ['password', 'remember_token'],
        'personal_access_tokens' => ['token'],
        'qr_login_passes' => ['token_hash'],
    ],

    'excluded_tables' => [
        'sessions' => 'Los identificadores de sesión son credenciales.',
        'password_reset_tokens' => 'Contiene hashes de tokens de restablecimiento.',
        'cache' => 'Estado temporal del framework.',
        'cache_locks' => 'Estado temporal del framework.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Clean-Slate Reset In The Test Environment
    |--------------------------------------------------------------------------
    |
    | `marcos:reset` refuses to run when the environment is `testing` unless
    | the test suite itself flips this flag with `Config::set()`. It is
    | deliberately not read from the environment, so no `.env` can enable it.
    |
    */

    'reset_allow_testing' => false,

];
