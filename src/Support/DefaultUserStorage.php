<?php

namespace Tihloh\Prefab\Users\Support;

use PDO;
use RuntimeException;
use Tihloh\Prefab\Users\Contracts\UserProviderInterface;
use Tihloh\Prefab\Users\Mapping\UserMap;
use Tihloh\Prefab\Users\Repositories\PdoUserProvider;

/**
 * Zero-configuration storage used only when Prefab Users cannot resolve an
 * explicit provider, configured database, or shared Prefab database capability.
 */
final class DefaultUserStorage
{
    public static function provider(array $config = []): UserProviderInterface
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException(
                'Prefab Users could not resolve a configured database and the automatic SQLite fallback requires ext-pdo_sqlite.'
            );
        }

        $path = self::path($config);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Prefab Users could not create storage directory: {$directory}");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $table = (string) ($config['table'] ?? 'users');
        self::assertIdentifier($table);
        self::ensureSchema($pdo, $table);

        return new PdoUserProvider($pdo, new UserMap(table: $table));
    }

    public static function path(array $config = []): string
    {
        $configured = $config['sqlite_path'] ?? getenv('PREFAB_USERS_SQLITE_PATH') ?: null;
        if (is_string($configured) && trim($configured) !== '') {
            return self::absolutePath($configured);
        }

        $root = getcwd() ?: '.';
        if (basename(str_replace('\\', '/', $root)) === 'public') {
            $root = dirname($root);
        }

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'prefab' . DIRECTORY_SEPARATOR . 'users.sqlite';
    }

    private static function ensureSchema(PDO $pdo, string $table): void
    {
        $pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NULL, email TEXT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1)',
            $table,
        ));
    }

    private static function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path)) {
            return $path;
        }

        $root = getcwd() ?: '.';
        if (basename(str_replace('\\', '/', $root)) === 'public') {
            $root = dirname($root);
        }

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Unsafe SQL identifier: {$identifier}");
        }
    }
}
