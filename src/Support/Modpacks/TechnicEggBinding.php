<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * A Technic server-pack URL that a known Pelican egg actually downloads.
 *
 * The version token is the substring the egg interpolates into MODPACK_VERSION.
 * It is taken from the server archive URL, not from Technic's display version,
 * because those two strings are not the same value.
 */
final class TechnicEggBinding
{
    public function __construct(
        public readonly string $profileId,
        public readonly string $urlPrefix,
        public readonly string $urlSuffix,
        public readonly int $maxVersionLength,
    ) {}

    public function versionFromUrl(string $url): ?string
    {
        if (!str_starts_with($url, $this->urlPrefix) || !str_ends_with($url, $this->urlSuffix)) {
            return null;
        }

        $version = substr($url, strlen($this->urlPrefix), -strlen($this->urlSuffix));
        if ($version === false
            || $version === ''
            || strlen($version) > $this->maxVersionLength
            || str_contains($version, '/')
            || str_contains($version, '\\')
            || str_contains($version, '..')) {
            return null;
        }

        return $version;
    }

    /**
     * Templates copied from the installation scripts in pelican-eggs/minecraft.
     *
     * @return list<self>
     */
    public static function known(): array
    {
        return [
            new self('tekkit', 'https://servers.technicpack.net/Technic/servers/tekkitmain/Tekkit_Server_', '.zip', 20),
            new self('tekkit-classic', 'https://servers.technicpack.net/Technic/servers/tekkit/Tekkit_Server_', '.zip', 20),
            new self('tekkit-2', 'https://servers.technicpack.net/Technic/servers/tekkit-2/Tekkit-2_Server_', '.zip', 10),
            new self('tekkit-legends', 'https://servers.technicpack.net/Technic/servers/tekkit-legends/Tekkit_Legends_Server_v', '.zip', 20),
            new self('tekkit-smp', 'https://servers.technicpack.net/Technic/servers/tekkit-smp/Tekkit-SMP_Server_', '.zip', 10),
            new self('hexxit', 'https://servers.technicpack.net/Technic/servers/hexxit/Hexxit_Server_v', '.zip', 20),
            new self('blightfall', 'https://servers.technicpack.net/Technic/servers/blightfall/Blightfall_Server_', '.zip', 20),
            new self('attack-of-the-b-team', 'https://servers.technicpack.net/Technic/servers/bteam/BTeam_Server_v', '.zip', 20),
            new self('the-1-12-2-pack', 'http://solder.endermedia.com/repository/downloads/the-1122-pack/the-1122-pack_', '.zip', 20),
            new self('the-1-7-10-pack', 'http://solder.endermedia.com/repository/downloads/the-1710-pack/the-1710-pack_', '.zip', 20),
        ];
    }

    /**
     * @return array{0: self, 1: string}|null
     */
    public static function match(string $serverPackUrl): ?array
    {
        $matches = [];
        foreach (self::known() as $binding) {
            $version = $binding->versionFromUrl($serverPackUrl);
            if ($version !== null) {
                $matches[] = [$binding, $version];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
