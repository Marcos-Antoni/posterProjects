<?php

namespace App\Console\LegacyBackup;

use JsonException;

/**
 * Checks a backup folder against its manifest: every file must still hash
 * to its recorded SHA-256 and every table's JSON must hold exactly the row
 * count that was live when the export ran. Any problem leaves the backup
 * unverified.
 */
class ManifestVerifier
{
    public const MANIFEST = 'manifest.json';

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string> Problems found, in Spanish; empty when the backup is sound.
     */
    public function verify(string $directory, array $manifest): array
    {
        $problems = [];

        $dump = $manifest['dump'] ?? null;

        if (! is_array($dump)) {
            $problems[] = 'El manifiesto no describe el volcado de la base de datos.';
        } else {
            $problems = [...$problems, ...$this->checkFile($directory, $dump, 'del volcado')];
        }

        $tables = $manifest['tables'] ?? null;

        if (! is_array($tables) || $tables === []) {
            return [...$problems, 'El manifiesto no lista ninguna tabla.'];
        }

        foreach ($tables as $table => $entry) {
            $json = $entry['json'] ?? null;

            if ($json === null) {
                continue;
            }

            $fileProblems = $this->checkFile($directory, $json, "de la tabla {$table}");

            if ($fileProblems !== []) {
                $problems = [...$problems, ...$fileProblems];

                continue;
            }

            $exported = $this->countJsonRows($directory.'/'.$json['file']);

            if ($exported === null) {
                $problems[] = "El JSON de la tabla {$table} no es un arreglo válido.";

                continue;
            }

            $live = (int) $entry['rows'];

            if ($exported !== $live || (int) $json['rows'] !== $live) {
                $problems[] = "La tabla {$table} tiene {$live} filas en la base y {$exported} en el JSON exportado.";
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function write(string $directory, array $manifest): void
    {
        $path = $directory.'/'.self::MANIFEST;

        file_put_contents($path, json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");

        @chmod($path, 0600);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $directory): ?array
    {
        $path = $directory.'/'.self::MANIFEST;

        if (! is_file($path)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function checkFile(string $directory, array $entry, string $label): array
    {
        $path = $directory.'/'.($entry['file'] ?? '');

        if (! isset($entry['file']) || ! is_file($path)) {
            return ["Falta el archivo {$label}."];
        }

        if (! hash_equals((string) ($entry['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            return ["El SHA-256 {$label} no coincide con el manifiesto."];
        }

        return [];
    }

    private function countJsonRows(string $path): ?int
    {
        try {
            $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($rows) && array_is_list($rows) ? count($rows) : null;
    }
}
