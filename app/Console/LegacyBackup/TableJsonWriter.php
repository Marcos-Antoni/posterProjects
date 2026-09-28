<?php

namespace App\Console\LegacyBackup;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Streams one table into a JSON array file, one row at a time, dropping
 * the configured secret columns. Returns the number of rows written.
 */
class TableJsonWriter
{
    /**
     * @param  list<string>  $redactedColumns
     */
    public function write(Connection $connection, string $table, string $path, array $redactedColumns): int
    {
        $handle = fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo crear {$path}.");
        }

        $rows = 0;

        try {
            fwrite($handle, '[');

            foreach ($connection->table($table)->cursor() as $row) {
                $values = array_diff_key((array) $row, array_flip($redactedColumns));

                fwrite($handle, ($rows === 0 ? "\n" : ",\n").json_encode(
                    $values,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ));

                $rows++;
            }

            fwrite($handle, $rows === 0 ? "]\n" : "\n]\n");
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
