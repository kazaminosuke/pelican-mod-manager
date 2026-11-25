<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Fixtures;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Stateful builder for authentic Modrinth .mrpack, CurseForge server/client ZIPs,
 * CurseForge Core API payloads, authoritative client-only/universal mod JARs,
 * hostile security archives, and FTB release manifests (including 500–1,500 file scale).
 */
final class ModpackArchiveFixtures
{
    private string $tempDir;

    /** @var list<string> */
    private array $createdPaths = [];

    public function __construct(?string $baseDir = null)
    {
        $root = $baseDir ?? sys_get_temp_dir();
        $dir = rtrim($root, '/').'/pmm-e2e-fixtures-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create fixture temp directory [{$dir}].");
        }
        $this->tempDir = $dir;
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function cleanup(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
        $this->createdPaths = [];

        if (is_dir($this->tempDir)) {
            $entries = @scandir($this->tempDir);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $full = $this->tempDir.'/'.$entry;
                    if (is_link($full) || is_file($full)) {
                        @unlink($full);
                    }
                }
            }
            @rmdir($this->tempDir);
        }
    }

    /**
     * Low-level ZIP builder supporting custom Unix modes, CM_STORE uncompressed
     * padding entries (for >8 MiB Range tail probing), and Unix symlink entries.
     *
     * @param  array<string, string|array{content: string, mode?: int, store?: bool, symlink?: bool}>  $entries
     */
    public function buildZip(array $entries, string $prefix = 'fixture-', string $extension = '.zip'): string
    {
        $path = $this->tempDir.'/'.$prefix.bin2hex(random_bytes(6)).$extension;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create ZIP fixture [{$path}].");
        }

        foreach ($entries as $name => $spec) {
            $content = is_array($spec) ? (string) $spec['content'] : $spec;
            $store = is_array($spec) && ($spec['store'] ?? false) === true;
            $symlink = is_array($spec) && ($spec['symlink'] ?? false) === true;
            $mode = is_array($spec) && isset($spec['mode']) ? (int) $spec['mode'] : 0644;

            $zip->addFromString($name, $content);
            if ($store) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            if ($symlink) {
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777 << 16));
            } else {
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ((0100000 | ($mode & 0777)) << 16));
            }
        }

        $zip->close();
        $this->createdPaths[] = $path;

        return $path;
    }

    /**
     * Builds an authentic Modrinth .mrpack archive.
     *
     * @param  array<string, string>  $dependencies
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, string>  $overrides
     * @param  array<string, string>  $serverOverrides
     * @param  array<string, string>  $clientOverrides
     * @param  array<string, mixed>  $options
     */
    public function buildMrpack(
        array $dependencies,
        array $files,
        array $overrides = [],
        array $serverOverrides = [],
        array $clientOverrides = [],
        array $options = [],
    ): string {
        $normalizedFiles = [];
        foreach ($files as $file) {
            $entry = $file;
            if (($options['omit_env'] ?? false) === true) {
                unset($entry['env']);
            }
            $normalizedFiles[] = $entry;
        }

        $index = [
            'formatVersion' => 1,
            'game' => 'minecraft',
            'versionId' => (string) ($options['version_id'] ?? '1.0.0'),
            'name' => (string) ($options['name'] ?? 'E2E Modrinth Pack'),
            'dependencies' => $dependencies,
            'files' => $normalizedFiles,
        ];

        $zipEntries = [
            'modrinth.index.json' => json_encode($index, JSON_THROW_ON_ERROR),
        ];

        foreach ($overrides as $rel => $bytes) {
            $zipEntries['overrides/'.ltrim($rel, '/')] = $bytes;
        }
        foreach ($serverOverrides as $rel => $bytes) {
            $zipEntries['server-overrides/'.ltrim($rel, '/')] = $bytes;
        }
        foreach ($clientOverrides as $rel => $bytes) {
            $zipEntries['client-overrides/'.ltrim($rel, '/')] = $bytes;
        }

        if (isset($options['large_override_bytes']) && (int) $options['large_override_bytes'] > 0) {
            $size = (int) $options['large_override_bytes'];
            $target = (string) ($options['large_override_path'] ?? 'server-overrides/config/large-override.bin');
            $zipEntries[$target] = [
                'content' => str_repeat('O', $size),
                'store' => false,
            ];
        }

        // CM_STORE padding placed AFTER modrinth.index.json so total archive > 8 MiB
        // while EOCD + Central Directory sit in the tail (< 1 MiB) for Range probing.
        if (isset($options['pad_bytes']) && (int) $options['pad_bytes'] > 0) {
            $zipEntries['client-overrides/assets/padding.bin'] = [
                'content' => str_repeat("\x5A", (int) $options['pad_bytes']),
                'store' => true,
            ];
        }

        return $this->buildZip($zipEntries, 'mrpack-', '.mrpack');
    }

    /**
     * RFC 9110 HTTP Range responder for Laravel Http::fake().
     *
     * Handles `Range: bytes=-N` and `Range: bytes=A-B` with `206 Partial Content`,
     * `Accept-Ranges: bytes`, and `Content-Range`, recording total requests & bytes served.
     *
     * @param  array<string, mixed>  $metrics
     *
     * @param-out  array{requests: int, bytes_sent: int, ranges: list<string>}  $metrics
     */
    public static function rangeHttpResponder(string $archiveBytes, array &$metrics, bool $rejectFullGet = false): Closure
    {
        $metrics = [
            'requests' => 0,
            'bytes_sent' => 0,
            'ranges' => [],
        ];
        $total = strlen($archiveBytes);

        return static function (Request $request) use ($archiveBytes, $total, &$metrics, $rejectFullGet): PromiseInterface {
            $metrics['requests']++;
            $psr = $request->toPsrRequest();
            $range = trim($psr->getHeaderLine('Range'));

            if ($range === '') {
                if ($rejectFullGet) {
                    return Http::response('Full download disabled for Range test', 403);
                }
                $metrics['bytes_sent'] += $total;

                return Http::response($archiveBytes, 200, [
                    'Accept-Ranges' => 'bytes',
                    'Content-Length' => (string) $total,
                    'Content-Type' => 'application/octet-stream',
                ]);
            }

            $metrics['ranges'][] = $range;
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
                return Http::response('', 416, ['Content-Range' => "bytes */{$total}"]);
            }

            if ($m[1] === '') {
                $suffix = (int) $m[2];
                $start = max(0, $total - $suffix);
                $end = $total - 1;
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
            }

            if ($start > $end || $start >= $total) {
                return Http::response('', 416, ['Content-Range' => "bytes */{$total}"]);
            }

            $slice = substr($archiveBytes, $start, $end - $start + 1);
            $metrics['bytes_sent'] += strlen($slice);

            return Http::response($slice, 206, [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes {$start}-{$end}/{$total}",
                'Content-Length' => (string) strlen($slice),
                'Content-Type' => 'application/octet-stream',
            ]);
        };
    }

    /**
     * Builds a realistic CurseForge server pack ZIP with 0–3 nested wrapper directories,
     * optional variables.txt, embedded manifest.json, and auxiliary installer/script files.
     *
     * @param  array<string, string>  $managedFiles
     * @param  array<string, mixed>|null  $embeddedManifest
     * @param  array<string, string>  $rootAuxiliaryFiles
     */
    public function buildCurseForgeServerPack(
        array $managedFiles,
        int $wrapperDepth = 1,
        ?string $variablesTxt = null,
        ?array $embeddedManifest = null,
        array $rootAuxiliaryFiles = [],
    ): string {
        $prefixParts = [];
        for ($i = 1; $i <= $wrapperDepth; $i++) {
            $prefixParts[] = 'WrapperLevel'.$i;
        }
        $prefix = $prefixParts === [] ? '' : implode('/', $prefixParts).'/';

        $entries = [];
        if ($variablesTxt !== null) {
            $entries[$prefix.'variables.txt'] = $variablesTxt;
        }
        if ($embeddedManifest !== null) {
            $entries[$prefix.'manifest.json'] = json_encode($embeddedManifest, JSON_THROW_ON_ERROR);
        }
        foreach ($rootAuxiliaryFiles as $rel => $bytes) {
            $entries[$prefix.ltrim($rel, '/')] = $bytes;
        }
        foreach ($managedFiles as $rel => $bytes) {
            $entries[$prefix.ltrim($rel, '/')] = $bytes;
        }

        return $this->buildZip($entries, 'cf-server-pack-');
    }

    /**
     * Builds a CurseForge-hosted FTB installer pack containing an installer script
     * referencing an FTB pack_id / version_id (with zero actual mods/ payload).
     */
    public function buildCurseForgeFtbInstallerPack(int $ftbPackId, int $ftbVersionId, int $wrapperDepth = 1): string
    {
        $prefix = $wrapperDepth > 0 ? str_repeat('PackRoot/', $wrapperDepth) : '';
        $script = "#!/usr/bin/env bash\n# FTB Server Installer\n./serverinstall_{$ftbPackId}_{$ftbVersionId} --auto\n";

        return $this->buildZip([
            $prefix.'install.sh' => $script,
            $prefix."serverinstall_{$ftbPackId}_{$ftbVersionId}" => "#!/bin/sh\necho ftb\n",
            $prefix.'README.txt' => "Run serverinstall_{$ftbPackId}_{$ftbVersionId} to download FTB pack {$ftbPackId} version {$ftbVersionId}.\n",
        ], 'cf-ftb-installer-');
    }

    /**
     * Builds a CurseForge client modpack ZIP containing manifest.json and overrides.
     *
     * @param  list<array{id: string, primary: bool}>  $modLoaders
     * @param  list<array{projectID: int, fileID: int, required?: bool}>  $manifestFiles
     * @param  array<string, string>  $overrides
     */
    public function buildCurseForgeClientPack(
        string $minecraft,
        array $modLoaders,
        array $manifestFiles,
        array $overrides = [],
        string $overridesDir = 'overrides',
        string $name = 'E2E CurseForge Client Pack',
        string $version = '1.0.0',
    ): string {
        $manifest = [
            'minecraft' => [
                'version' => $minecraft,
                'modLoaders' => $modLoaders,
            ],
            'manifestType' => 'minecraftModpack',
            'manifestVersion' => 1,
            'name' => $name,
            'version' => $version,
            'overrides' => $overridesDir,
            'files' => $manifestFiles,
        ];

        $entries = [
            'manifest.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
        ];
        foreach ($overrides as $rel => $bytes) {
            $entries[trim($overridesDir, '/').'/'.ltrim($rel, '/')] = $bytes;
        }

        return $this->buildZip($entries, 'cf-client-pack-');
    }

    /**
     * Constructs an authentic CurseForge Core API File payload.
     *
     * @param  list<string>  $gameVersions
     * @return array<string, mixed>
     */
    public static function curseForgeApiFile(
        int $projectId,
        int $fileId,
        string $fileName,
        string $contentBytes,
        ?string $downloadUrl = null,
        bool $isServerPack = false,
        ?int $serverPackFileId = null,
        ?int $alternateFileId = null,
        ?int $parentProjectFileId = null,
        array $gameVersions = ['1.21.1', 'NeoForge'],
    ): array {
        return [
            'id' => $fileId,
            'gameId' => 432,
            'modId' => $projectId,
            'isAvailable' => true,
            'displayName' => $fileName,
            'fileName' => $fileName,
            'releaseType' => 1,
            'fileStatus' => 4,
            'hashes' => [
                ['value' => sha1($contentBytes), 'algo' => 1],
                ['value' => md5($contentBytes), 'algo' => 2],
            ],
            'fileDate' => '2026-10-15T12:00:00Z',
            'fileLength' => strlen($contentBytes),
            'downloadCount' => 50000,
            'downloadUrl' => $downloadUrl,
            'gameVersions' => $gameVersions,
            'isServerPack' => $isServerPack,
            'serverPackFileId' => $serverPackFileId,
            'alternateFileId' => $alternateFileId ?? 0,
            'parentProjectFileId' => $parentProjectFileId,
        ];
    }

    /**
     * Constructs an authentic CurseForge Core API Mod payload with classId & categories.
     *
     * @param  list<int>  $categoryIds
     * @return array<string, mixed>
     */
    public static function curseForgeApiMod(
        int $projectId,
        string $name,
        int $classId = 6,
        bool $allowModDistribution = true,
        array $categoryIds = [],
        ?string $slug = null,
    ): array {
        $resolvedSlug = $slug ?? strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $section = match ($classId) {
            12 => 'texture-packs',
            6552 => 'shaders',
            6945 => 'data-packs',
            4471 => 'modpacks',
            default => 'mc-mods',
        };

        return [
            'id' => $projectId,
            'gameId' => 432,
            'name' => $name,
            'slug' => trim($resolvedSlug, '-'),
            'classId' => $classId,
            'allowModDistribution' => $allowModDistribution,
            'categories' => array_map(
                static fn (int $catId): array => ['id' => $catId, 'name' => 'Cat '.$catId],
                $categoryIds,
            ),
            'links' => [
                'websiteUrl' => "https://www.curseforge.com/minecraft/{$section}/".trim($resolvedSlug, '-'),
            ],
        ];
    }

    public function buildFabricModJar(string $modId, string $environment = '*', string $version = '1.0.0'): string
    {
        return $this->buildZip([
            'fabric.mod.json' => json_encode([
                'schemaVersion' => 1,
                'id' => $modId,
                'version' => $version,
                'name' => ucfirst($modId),
                'environment' => $environment,
            ], JSON_THROW_ON_ERROR),
            'net/example/'.ucfirst($modId).'.class' => "\xCA\xFE\xBA\xBE\x00\x00\x00\x41",
        ], "fabric-{$modId}-", '.jar');
    }

    public function buildQuiltModJar(string $modId, string $environment = '*', string $version = '1.0.0'): string
    {
        return $this->buildZip([
            'quilt.mod.json' => json_encode([
                'schema_version' => 1,
                'quilt_loader' => [
                    'group' => 'org.example',
                    'id' => $modId,
                    'version' => $version,
                ],
                'minecraft' => [
                    'environment' => $environment,
                ],
            ], JSON_THROW_ON_ERROR),
            'org/example/'.ucfirst($modId).'.class' => "\xCA\xFE\xBA\xBE\x00\x00\x00\x41",
        ], "quilt-{$modId}-", '.jar');
    }

    public function buildForgeModJar(string $modId, bool $clientSideOnly = false, ?string $dependencySide = null): string
    {
        $toml = "modLoader=\"javafml\"\nloaderVersion=\"[47,)\"\nlicense=\"MIT\"\n";
        if ($clientSideOnly) {
            $toml .= "clientSideOnly=true\n";
        }
        $toml .= "\n[[mods]]\nmodId=\"{$modId}\"\nversion=\"1.0.0\"\ndisplayName=\"{$modId}\"\n";
        if ($dependencySide !== null) {
            $toml .= "\n[[dependencies.{$modId}]]\nmodId=\"minecraft\"\nmandatory=true\nside=\"{$dependencySide}\"\n";
        }

        return $this->buildZip([
            'META-INF/mods.toml' => $toml,
            'net/example/'.ucfirst($modId).'.class' => "\xCA\xFE\xBA\xBE\x00\x00\x00\x41",
        ], "forge-{$modId}-", '.jar');
    }

    public function buildNeoForgeModJar(string $modId, bool $clientSideOnly = false, ?string $dependencySide = null): string
    {
        $toml = "modLoader=\"javafml\"\nloaderVersion=\"[4,)\"\nlicense=\"MIT\"\n";
        if ($clientSideOnly) {
            $toml .= "clientSideOnly=true\n";
        }
        $toml .= "\n[[mods]]\nmodId=\"{$modId}\"\nversion=\"1.0.0\"\ndisplayName=\"{$modId}\"\n";
        if ($dependencySide !== null) {
            $toml .= "\n[[dependencies.{$modId}]]\nmodId=\"minecraft\"\ntype=\"required\"\nside=\"{$dependencySide}\"\n";
        }

        return $this->buildZip([
            'META-INF/neoforge.mods.toml' => $toml,
            'net/example/'.ucfirst($modId).'.class' => "\xCA\xFE\xBA\xBE\x00\x00\x00\x41",
        ], "neoforge-{$modId}-", '.jar');
    }

    public function buildZipSlipArchive(string $maliciousEntry = '../../evil.jar'): string
    {
        return $this->buildZip([
            'mods/safe.jar' => "PK\x03\x04safe",
            $maliciousEntry => 'escaped-payload',
        ], 'zipslip-');
    }

    public function buildSymlinkArchive(string $entryName = 'mods/link.jar', string $target = '/etc/passwd'): string
    {
        return $this->buildZip([
            'mods/safe.jar' => "PK\x03\x04safe",
            $entryName => ['content' => $target, 'symlink' => true],
        ], 'symlink-');
    }

    /**
     * @param  array<string, string>  $entries
     */
    public function buildModeZeroArchive(array $entries): string
    {
        $spec = [];
        foreach ($entries as $name => $content) {
            $spec[$name] = ['content' => $content, 'mode' => 0000];
        }

        return $this->buildZip($spec, 'modezero-');
    }

    /**
     * Builds an authentic FTB release manifest JSON string.
     *
     * @param  list<array<string, mixed>>  $targets
     * @param  list<array<string, mixed>>  $files
     */
    public static function buildFtbManifestJson(
        int $packId,
        int $versionId,
        string $versionName,
        array $targets,
        array $files,
    ): string {
        return json_encode([
            'status' => 'success',
            'id' => $versionId,
            'parent' => $packId,
            'name' => $versionName,
            'type' => 'Release',
            'private' => false,
            'targets' => $targets,
            'files' => $files,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Generates a scalable FTB Evolution-style release fixture with $fileCount small
     * server files distributed across mods/, config/, datapacks/, kubejs/, and structurize/,
     * including a realistic 0-byte config file and deterministic URL -> bytes map.
     *
     * @return array{manifest_json: string, files_by_url: array<string, string>, expected_files: array<string, string>}
     */
    public static function buildFtbLargeScaleFixture(
        int $fileCount = 600,
        int $packId = 135,
        int $versionId = 100634,
        string $minecraft = '26.1.2',
        string $loader = 'neoforge',
        string $loaderVersion = '26.1.2.129',
        string $javaVersion = '25.0.2+10-LTS',
    ): array {
        $files = [];
        $filesByUrl = [];
        $expectedFiles = [];

        // File 0: authentic 0-byte config (real StoneBlock 3 / FTB pattern)
        $emptyUrl = "https://files.feed-the-beast.com/pack/{$packId}/v{$versionId}/empty-ironfurnaces.toml";
        $files[] = [
            'id' => 1,
            'path' => './config',
            'name' => 'ironfurnaces.toml',
            'size' => 0,
            'sha1' => sha1(''),
            'hashes' => ['sha256' => hash('sha256', '')],
            'url' => $emptyUrl,
            'clientonly' => false,
            'optional' => false,
        ];
        $filesByUrl[$emptyUrl] = '';
        $expectedFiles['config/ironfurnaces.toml'] = '';

        // File 1: authentic structurize/ blueprint (FTB Inferno / Evolution pattern)
        $structBytes = "STRUCTURIZE-BLUEPRINT-V1-{$packId}";
        $structUrl = "https://files.feed-the-beast.com/pack/{$packId}/v{$versionId}/keep.blueprint";
        $files[] = [
            'id' => 2,
            'path' => './structurize/blueprints',
            'name' => 'keep.blueprint',
            'size' => strlen($structBytes),
            'sha1' => sha1($structBytes),
            'hashes' => ['sha256' => hash('sha256', $structBytes)],
            'url' => $structUrl,
            'clientonly' => false,
            'optional' => false,
        ];
        $filesByUrl[$structUrl] = $structBytes;
        $expectedFiles['structurize/blueprints/keep.blueprint'] = $structBytes;

        $buckets = [
            ['dir' => './mods', 'ext' => '.jar', 'prefix' => "PK\x03\x04MOD-"],
            ['dir' => './config', 'ext' => '.toml', 'prefix' => "# config\nkey="],
            ['dir' => './datapacks', 'ext' => '.zip', 'prefix' => "PK\x03\x04DP-"],
            ['dir' => './kubejs/server_scripts', 'ext' => '.js', 'prefix' => '// script '],
        ];

        for ($i = 2; $i < $fileCount; $i++) {
            $bucket = $buckets[$i % count($buckets)];
            $name = sprintf('item-%04d%s', $i, $bucket['ext']);
            $payload = $bucket['prefix'].str_repeat(chr(65 + ($i % 26)), 64)."-{$i}";
            $url = "https://files.feed-the-beast.com/pack/{$packId}/v{$versionId}/{$name}";
            $relDir = ltrim($bucket['dir'], './');

            $files[] = [
                'id' => $i + 1,
                'path' => $bucket['dir'],
                'name' => $name,
                'size' => strlen($payload),
                'sha1' => sha1($payload),
                'hashes' => ['sha256' => hash('sha256', $payload)],
                'url' => $url,
                'clientonly' => false,
                'optional' => false,
            ];
            $filesByUrl[$url] = $payload;
            $expectedFiles["{$relDir}/{$name}"] = $payload;
        }

        $targets = [
            ['type' => 'game', 'name' => 'minecraft', 'version' => $minecraft],
            ['type' => 'modloader', 'name' => $loader, 'version' => $loaderVersion],
            ['type' => 'runtime', 'name' => 'java', 'version' => $javaVersion],
        ];

        return [
            'manifest_json' => self::buildFtbManifestJson($packId, $versionId, '1.0.0', $targets, $files),
            'files_by_url' => $filesByUrl,
            'expected_files' => $expectedFiles,
        ];
    }
}
