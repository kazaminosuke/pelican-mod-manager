<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use Closure;

/** Request-local Catalog overrides layered onto the existing auto-detection path. */
final class CatalogCompatibilityOverride
{
    /** @var array<string, array{version: string|null, loader: string|null}> */
    private static array $values = [];

    public static function set(Server $server, ?string $version, ?string $loader): void
    {
        $key = self::serverKey($server);
        $version = self::normalize($version);
        $loader = self::normalize($loader);

        if ($version === null && $loader === null) {
            unset(self::$values[$key]);

            return;
        }

        self::$values[$key] = ['version' => $version, 'loader' => $loader];
    }

    public static function version(Server $server): ?string
    {
        return self::$values[self::serverKey($server)]['version'] ?? null;
    }

    public static function loader(Server $server): ?string
    {
        return self::$values[self::serverKey($server)]['loader'] ?? null;
    }

    public static function clear(?Server $server = null): void
    {
        if ($server === null) {
            self::$values = [];

            return;
        }

        unset(self::$values[self::serverKey($server)]);
    }

    public static function without(Server $server, Closure $callback): mixed
    {
        $key = self::serverKey($server);
        $previous = self::$values[$key] ?? null;
        unset(self::$values[$key]);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                self::$values[$key] = $previous;
            }
        }
    }

    private static function normalize(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private static function serverKey(Server $server): string
    {
        // This state never leaves the current request/job, so object identity
        // is sufficient and avoids adding model/database work to every
        // existing resolver call.
        return 'object:'.spl_object_id($server);
    }
}
