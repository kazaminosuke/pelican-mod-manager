<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * Compares authenticated ownership, a target release and observations of
 * the live filesystem. A false observation is an occupied, untrusted path.
 */
final class ModpackDelta
{
    /**
     * @param array<string, array{sha256: string, identity: string}> $owned
     * @param array<string, array{identity: ?string, sha256: ?string, size: ?int}> $desired
     * @param callable(string, bool): (string|false|null) $observe
     * @return array<string, array{action: string, observed: string|false|null, size: ?int}>
     */
    public static function plan(array $owned, array $desired, callable $observe): array
    {
        $plan = [];
        $paths = array_unique([...array_keys($desired), ...array_keys($owned)]);
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $old = $owned[$path] ?? null;
            $target = $desired[$path] ?? null;
            $mod = str_starts_with($path, 'mods/');
            if ($target === null && !$mod) {
                $plan[$path] = ['action' => 'preserve', 'observed' => null, 'size' => null];

                continue;
            }
            $live = $observe($path, $old !== null);
            $matches = $old !== null && is_string($live) && hash_equals($old['sha256'], $live);
            $sameTarget = $target !== null && (
                ($target['identity'] !== null && $target['identity'] === ($old['identity'] ?? null))
                || (is_string($live) && $target['sha256'] === $live)
            );
            $action = match (true) {
                $target === null && $live === null => 'preserve',
                $target === null && $matches => 'delete',
                $target === null => 'conflict',
                $live === null && $old === null => 'add',
                $matches && $sameTarget => 'keep',
                $matches => 'replace',
                $old !== null && $sameTarget && !$mod => 'modified',
                default => 'conflict',
            };

            $plan[$path] = ['action' => $action, 'observed' => $live, 'size' => $target['size'] ?? null];
        }

        return $plan;
    }
}
