<?php

namespace App\Console\LegacyBackup;

/**
 * Path helpers for the legacy backup commands: resolve a user-supplied path
 * to an absolute, normalized one (following symlinks of the part that
 * already exists) and decide whether it falls inside the repository.
 */
final class BackupPath
{
    /**
     * Resolve a path to an absolute, normalized form without requiring it to
     * exist. The deepest existing ancestor is passed through `realpath()` so
     * a symlink pointing into the repository cannot sneak past the guard.
     */
    public static function resolve(string $path, ?string $cwd = null): string
    {
        if (! str_starts_with($path, '/')) {
            $path = rtrim($cwd ?? (string) getcwd(), '/').'/'.$path;
        }

        $normalized = self::normalize($path);

        $existing = $normalized;
        $missing = [];

        while ($existing !== '/' && ! file_exists($existing)) {
            array_unshift($missing, basename($existing));
            $existing = dirname($existing);
        }

        $real = realpath($existing);

        if ($real === false) {
            return $normalized;
        }

        return self::normalize(rtrim($real, '/').'/'.implode('/', $missing));
    }

    /**
     * Determine whether `$path` is `$root` itself or anything below it.
     */
    public static function isInside(string $path, string $root): bool
    {
        $root = rtrim(self::resolve($root), '/');
        $path = rtrim(self::resolve($path), '/');

        return $path === $root || str_starts_with($path.'/', $root.'/');
    }

    /**
     * Collapse `.`/`..` segments and duplicate slashes of an absolute path.
     */
    private static function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
