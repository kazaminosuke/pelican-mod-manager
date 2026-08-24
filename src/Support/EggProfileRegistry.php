<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Loads and indexes resources/egg-profiles.json (plus an optional operator
 * extra file - see config('pelican-mod-manager.egg_profiles_extra_path')),
 * and implements the pure matching primitives EggProfileResolver's cascade
 * calls in order (uuid -> update_url -> name+signature -> name -> signature).
 *
 * Parsing happens once per process and is cached in a static property - this
 * class has no constructor state, matching Support\CacheVersion's plain-
 * static shape so it can be called from Enums\ProjectType/MinecraftLoader's
 * own static methods without needing container resolution.
 *
 * Signature collisions (a variable_signatures entry that appears in more than
 * one profile - e.g. Paper and Folia share the same four Pterodactyl
 * variable names) are computed once at load time. A colliding signature is
 * never a safe sole match: see findByNonCollidingSignature().
 */
final class EggProfileRegistry
{
    /** @var array<int, EggProfile>|null */
    private static ?array $profiles = null;

    /** @var array<string, true>|null Signature keys (imploded, sorted, uppercased) that appear in more than one profile. */
    private static ?array $collidingSignatureKeys = null;

    public static function findByUuid(?string $uuid): ?EggProfile
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        foreach (self::load() as $profile) {
            if (in_array($uuid, $profile->uuids, true)) {
                return $profile;
            }
        }

        return null;
    }

    public static function findByUpdateUrl(?string $updateUrl): ?EggProfile
    {
        if ($updateUrl === null || $updateUrl === '') {
            return null;
        }

        foreach (self::load() as $profile) {
            foreach ($profile->updateUrlContains as $fragment) {
                if ($fragment !== '' && str_contains($updateUrl, $fragment)) {
                    return $profile;
                }
            }
        }

        return null;
    }

    /**
     * Tier 4 (exact): the egg's normalized name AND variable signature must
     * both point at the same profile. This is the tier that resolves
     * Pterodactyl-family eggs (no uuid, no update_url) safely even when
     * their signature alone collides with another profile's - the name is
     * what disambiguates Folia from Paper here.
     *
     * @param array<int, string> $signature already uppercased/sorted
     */
    public static function findByNameAndSignature(string $normalizedName, array $signature): ?EggProfile
    {
        if ($normalizedName === '' || $signature === []) {
            return null;
        }

        $key = self::signatureKey($signature);

        foreach (self::load() as $profile) {
            if (!in_array($normalizedName, $profile->nameAliases, true)) {
                continue;
            }

            foreach ($profile->variableSignatures as $candidate) {
                if (self::signatureKey($candidate) === $key) {
                    return $profile;
                }
            }
        }

        return null;
    }

    /**
     * Tier 5 (high confidence): the normalized name uniquely identifies one
     * profile, independent of variable signature. Returns null (not a
     * random pick) when more than one profile shares the alias.
     */
    public static function findByNameUnique(string $normalizedName): ?EggProfile
    {
        if ($normalizedName === '') {
            return null;
        }

        $matches = [];
        foreach (self::load() as $profile) {
            if (in_array($normalizedName, $profile->nameAliases, true)) {
                $matches[] = $profile;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Tier 6 (medium confidence, "renamed egg" rescue): the variable
     * signature uniquely identifies one profile AND that signature is not
     * one of the known-colliding ones. Deliberately refuses to answer for a
     * colliding signature even when only one profile happens to carry it in
     * THIS call's data - collision status is a property of the whole
     * profile set, not of a single lookup, so a signature that is unsafe in
     * general is always rejected here rather than sometimes accepted.
     *
     * @param array<int, string> $signature already uppercased/sorted
     */
    public static function findByNonCollidingSignature(array $signature): ?EggProfile
    {
        if ($signature === [] || self::isCollidingSignature($signature)) {
            return null;
        }

        $key = self::signatureKey($signature);
        $matches = [];
        foreach (self::load() as $profile) {
            foreach ($profile->variableSignatures as $candidate) {
                if (self::signatureKey($candidate) === $key) {
                    $matches[] = $profile;

                    break;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<int, string> $signature */
    public static function isCollidingSignature(array $signature): bool
    {
        if ($signature === []) {
            return false;
        }

        self::load();

        return isset(self::$collidingSignatureKeys[self::signatureKey($signature)]);
    }

    public static function normalizeName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?? '');
    }

    /**
     * @param  array<int, string>  $variableNames
     * @return list<string>
     */
    public static function normalizeSignature(array $variableNames): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($name): string => strtoupper((string) $name),
            $variableNames,
        ))));
        sort($normalized);

        return $normalized;
    }

    /** Test-only: forces the next call to reload from disk. */
    public static function clear(): void
    {
        self::$profiles = null;
        self::$collidingSignatureKeys = null;
    }

    /**
     * Test-only: injects a profile set directly, bypassing the JSON file
     * load entirely (and therefore plugin_path()/base_path(), which need a
     * fully bootstrapped Laravel Application this test suite deliberately
     * never sets up - see tests/bootstrap.php). Recomputes signature
     * collisions the same way load() does, so a seeded set exercises the
     * exact same collision-safety behavior as the real bundled file.
     *
     * @param array<int, array<string, mixed>> $profilesData same shape as egg-profiles.json's "profiles" array
     */
    public static function seed(array $profilesData): void
    {
        $profiles = array_values(array_map(
            fn (array $entry): EggProfile => EggProfile::fromArray($entry),
            $profilesData,
        ));

        self::$profiles = $profiles;
        self::$collidingSignatureKeys = self::computeCollisions($profiles);
    }

    /** @return array<int, EggProfile> */
    private static function load(): array
    {
        if (self::$profiles !== null) {
            return self::$profiles;
        }

        $profiles = self::loadFile(plugin_path('pelican-mod-manager', 'resources', 'egg-profiles.json'));

        $extraPath = config('pelican-mod-manager.egg_profiles_extra_path');
        if (is_string($extraPath) && $extraPath !== '') {
            $profiles = array_merge($profiles, self::loadFile($extraPath));
        }

        self::$profiles = $profiles;
        self::$collidingSignatureKeys = self::computeCollisions($profiles);

        return $profiles;
    }

    /** @return array<int, EggProfile> */
    private static function loadFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !is_array($decoded['profiles'] ?? null)) {
            return [];
        }

        return array_values(array_map(
            fn (array $entry): EggProfile => EggProfile::fromArray($entry),
            $decoded['profiles'],
        ));
    }

    /**
     * @param array<int, EggProfile> $profiles
     * @return array<string, true>
     */
    private static function computeCollisions(array $profiles): array
    {
        /** @var array<string, array<string, true>> $owners signature key => set of profile ids that carry it */
        $owners = [];

        foreach ($profiles as $profile) {
            foreach ($profile->variableSignatures as $signature) {
                if ($signature === []) {
                    continue;
                }

                $owners[self::signatureKey($signature)][$profile->id] = true;
            }
        }

        $colliding = [];
        foreach ($owners as $key => $ownerIds) {
            if (count($ownerIds) > 1) {
                $colliding[$key] = true;
            }
        }

        return $colliding;
    }

    /** @param array<int, string> $signature */
    private static function signatureKey(array $signature): string
    {
        return implode('|', self::normalizeSignature($signature));
    }
}
