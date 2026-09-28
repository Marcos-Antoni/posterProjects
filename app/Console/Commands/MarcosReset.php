<?php

namespace App\Console\Commands;

use App\Console\LegacyBackup\BackupPath;
use App\Console\LegacyBackup\ManifestVerifier;
use App\Enums\TokenName;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\text;

/**
 * The one destructive operation of Marcos OS (legacy-data-export spec "The
 * Clean-Slate Reset Is Guarded", design D13). It refuses to run unless:
 *
 * - the environment is not `testing` (or the suite forced it through config),
 * - a backup exists whose manifest is verified AND still verifies (every
 *   SHA-256 and row count), whose restore rehearsal passed for that very dump,
 *   and which is younger than 24 hours,
 * - the owner exists, and
 * - the exact confirmation phrase is typed.
 *
 * Then, in ONE transaction, it drops the legacy JIRA tables (the reset-only
 * migration in `database/migrations/marcos-reset`), empties every Marcos OS
 * and habit table, deletes every other user, session and token, and keeps the
 * owner with its `mcp` and `mobile` tokens so both integrations reconnect.
 */
#[Signature('marcos:reset
    {--owner= : Correo del propietario que se conserva (obligatorio si hay más de un usuario)}
    {--backup= : Carpeta del respaldo (ruta absoluta o nombre dentro de legacy_backup.path); por defecto, el más reciente}
    {--confirm= : La frase de confirmación, para correrlo sin preguntar}')]
#[Description('Reinicia la base a Marcos OS limpio, solo con un respaldo verificado y ensayado de menos de 24 horas.')]
class MarcosReset extends Command
{
    public const CONFIRMATION_PHRASE = 'BORRAR Y EMPEZAR DE CERO';

    public const RESET_MIGRATIONS_PATH = 'database/migrations/marcos-reset';

    public const RESULT_FILE = 'reset.json';

    /**
     * Tolerated clock skew for a backup's `created_at` ahead of now.
     */
    public const CLOCK_SKEW_MINUTES = 5;

    /**
     * Tables emptied by the reset, children first. `users` and
     * `personal_access_tokens` are handled separately (the owner and its two
     * tokens stay).
     *
     * @var list<string>
     */
    public const EMPTIED_TABLES = [
        'item_dependencies',
        'item_two_minute_history',
        'milestone_evidence',
        'focus_sessions',
        'retirements',
        'control_map_entries',
        'control_plans',
        'items',
        'plans',
        'objectives',
        'habit_entries',
        'habit_days',
        'habits',
        'qr_login_passes',
        'sessions',
        'password_reset_tokens',
    ];

    /**
     * Execute the console command.
     */
    public function handle(ManifestVerifier $verifier): int
    {
        if (app()->environment('testing') && Config::get('legacy_backup.reset_allow_testing') !== true) {
            return $this->refuse('En el entorno testing el reinicio solo corre si la suite de pruebas lo fuerza.');
        }

        try {
            $directory = $this->resolveBackup();
            $this->ensureBackupIsUsable($directory, $verifier);
            $owner = $this->resolveOwner();
        } catch (RuntimeException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $phrase = $this->option('confirm') ?? text(
            label: 'Escribí la frase exacta para confirmar: '.self::CONFIRMATION_PHRASE,
            hint: "Se borran todos los datos salvo el usuario {$owner->email} y sus tokens mcp y mobile.",
        );

        if ($phrase !== self::CONFIRMATION_PHRASE) {
            return $this->refuse('La frase no coincide. No se tocó nada.');
        }

        try {
            DB::transaction(function () use ($owner): void {
                $this->dropLegacyTables();
                $this->emptyDomainTables($owner);
            });
        } catch (Throwable $exception) {
            return $this->refuse('El reinicio falló y se deshizo por completo: '.$exception->getMessage());
        }

        $this->recordResult($directory, $owner);

        $this->components->info("Marcos OS quedó limpio. Se conservó {$owner->email} con sus tokens mcp y mobile.");

        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException
     */
    private function resolveBackup(): string
    {
        $option = $this->option('backup');
        $base = BackupPath::resolve(Config::string('legacy_backup.path'));

        if (is_string($option) && $option !== '') {
            return str_contains($option, '/') ? BackupPath::resolve($option) : BackupPath::resolve($base.'/'.$option);
        }

        $candidates = is_dir($base) ? File::directories($base) : [];

        $candidates = array_values(array_filter($candidates, fn (string $directory): bool => is_file($directory.'/'.ManifestVerifier::MANIFEST)));

        if ($candidates === []) {
            throw new RuntimeException("No hay ningún respaldo en {$base}. Corré marcos:export-legacy y marcos:rehearse-restore primero.");
        }

        sort($candidates);

        return end($candidates);
    }

    /**
     * @throws RuntimeException
     */
    private function ensureBackupIsUsable(string $directory, ManifestVerifier $verifier): void
    {
        $manifest = ManifestVerifier::read($directory);

        if ($manifest === null) {
            throw new RuntimeException("No hay un manifiesto válido en {$directory}.");
        }

        if (($manifest['verified'] ?? false) !== true) {
            throw new RuntimeException("El respaldo {$directory} no está verificado.");
        }

        $problems = $verifier->verify($directory, $manifest);

        if ($problems !== []) {
            throw new RuntimeException("El respaldo {$directory} ya no verifica: ".implode(' ', $problems));
        }

        $rehearsal = $this->readJson($directory.'/'.RehearseRestore::RESULT_FILE);

        if ($rehearsal === null
            || ($rehearsal['passed'] ?? false) !== true
            || ! hash_equals((string) ($manifest['dump']['sha256'] ?? ''), (string) ($rehearsal['dump_sha256'] ?? ''))) {
            throw new RuntimeException("El respaldo {$directory} no pasó el ensayo de restauración. Corré marcos:rehearse-restore.");
        }

        $createdAt = self::parseManifestMoment($manifest['created_at'] ?? null);

        if ($createdAt === null) {
            throw new RuntimeException("El respaldo {$directory} no tiene una fecha de creación legible (created_at debe ser ISO-8601, como la escribe marcos:export-legacy). No se puede saber si es reciente.");
        }

        if ($createdAt->gt(now()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            throw new RuntimeException("El respaldo {$directory} tiene fecha futura ({$createdAt->toIso8601String()}): no se puede saber si es reciente. Revisá el reloj y exportá uno nuevo.");
        }

        if ($createdAt->lt(now()->subHours(24))) {
            throw new RuntimeException("El respaldo {$directory} tiene más de 24 horas. Exportá y ensayá uno nuevo.");
        }
    }

    /**
     * Strictly parse the manifest's `created_at` as the ISO-8601 moment
     * `marcos:export-legacy` writes (`2026-09-28T03:21:33+00:00`, or with `Z`).
     * Never lenient: empty, relative words ("now", "tomorrow"), timestamps,
     * other formats and impossible dates are all null.
     */
    public static function parseManifestMoment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }

        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);

        if ($moment === false || $moment->format('Y-m-d\TH:i:sP') !== $normalized) {
            return null;
        }

        return CarbonImmutable::instance($moment);
    }

    /**
     * @throws RuntimeException
     */
    private function resolveOwner(): User
    {
        $email = $this->option('owner');

        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first()
                ?? throw new RuntimeException("No existe ningún usuario {$email}.");
        }

        if (User::query()->count() !== 1) {
            throw new RuntimeException('Hay más de un usuario: indicá el propietario con --owner=correo.');
        }

        return User::query()->firstOrFail();
    }

    /**
     * Run the reset-only migration that drops the retired JIRA tables.
     *
     * @throws RuntimeException
     */
    private function dropLegacyTables(): void
    {
        $status = Artisan::call('migrate', [
            '--path' => self::RESET_MIGRATIONS_PATH,
            '--force' => true,
        ]);

        if ($status !== self::SUCCESS) {
            throw new RuntimeException(trim(Artisan::output()));
        }
    }

    private function emptyDomainTables(User $owner): void
    {
        foreach (self::EMPTIED_TABLES as $table) {
            DB::table($table)->delete();
        }

        DB::table('personal_access_tokens')
            ->where(function ($query) use ($owner): void {
                $query->where('tokenable_type', '!=', $owner->getMorphClass())
                    ->orWhere('tokenable_id', '!=', $owner->id)
                    ->orWhereNotIn('name', [TokenName::Mcp->value, TokenName::Mobile->value]);
            })
            ->delete();

        DB::table('users')->where('id', '!=', $owner->id)->delete();
    }

    private function recordResult(string $directory, User $owner): void
    {
        @file_put_contents($directory.'/'.self::RESULT_FILE, json_encode([
            'reset_at' => now()->utc()->toIso8601String(),
            'owner' => $owner->email,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);
        $this->components->warn('No se tocó nada.');

        return self::FAILURE;
    }
}
