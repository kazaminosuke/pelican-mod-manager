<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Kazaminosuke\ModManager\Models\ModManagerBackgroundJob;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\ArchiveSignature;
use Kazaminosuke\ModManager\Support\DiagnosticCheck;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use ReflectionClass;
use Throwable;

/**
 * Read-only checks for provider reachability, egg classification, and cache health.
 * Nothing here installs a pack or writes a server file.
 */
final class ModManagerDiagnostics
{
    public function __construct(
        private readonly InstalledEggCatalog $eggs,
    ) {}

    /**
     * @return list<array{id: string, status: string, summary: string, details: array<string, mixed>}>
     */
    public function run(?Server $server = null): array
    {
        $checks = [
            $this->httpJson('modrinth', 'https://api.modrinth.com/v2/tag/game_version', 'Modrinth API'),
            $this->curseForge(),
            $this->httpJson('spiget', 'https://api.spiget.org/v2/resources/2', 'SpiGet API'),
            $this->spigetCdn(),
            $this->httpJson('ftb', 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/5', 'FTB pack API'),
            $this->httpJson('technic', 'https://api.technicpack.net/modpack/tekkit?build=recommended', 'Technic pack API'),
            $this->httpJson('atlauncher', 'https://api.atlauncher.com/v1/packs/simple', 'ATLauncher API'),
            $this->cacheSchema(),
            $this->backgroundJobs(),
            $this->tempDirectory(),
        ];

        if ($server !== null) {
            $checks[] = $this->serverEgg($server);
        }

        return array_map(static fn (DiagnosticCheck $check): array => $check->toArray(), $checks);
    }

    private function curseForge(): DiagnosticCheck
    {
        $key = config('pelican-mod-manager.curseforge_api_key');
        if (!is_string($key) || trim($key) === '') {
            return new DiagnosticCheck('curseforge', 'warning', 'CurseForge API key is not configured.');
        }

        try {
            $response = UpstreamHttp::json(['x-api-key' => trim($key)])
                ->timeout(8)
                ->connectTimeout(3)
                ->get('https://api.curseforge.com/v1/games/432');
            if ($response->successful()) {
                return new DiagnosticCheck('curseforge', 'pass', 'CurseForge API responded.');
            }

            return new DiagnosticCheck('curseforge', 'fail', 'CurseForge API returned HTTP '.$response->status().'.');
        } catch (Throwable $exception) {
            return new DiagnosticCheck('curseforge', 'fail', 'CurseForge API could not be reached.');
        }
    }

    private function spigetCdn(): DiagnosticCheck
    {
        try {
            $resource = UpstreamHttp::json()->timeout(8)->connectTimeout(3)
                ->get('https://api.spiget.org/v2/resources/2')
                ->throw()
                ->json();
            $type = strtolower((string) (is_array($resource['file'] ?? null) ? ($resource['file']['type'] ?? '') : ''));
            if (preg_match('/^\.[a-z0-9]{1,8}$/', $type) !== 1) {
                return new DiagnosticCheck('spiget_cdn', 'warning', 'The sample resource has no hosted file type, so the CDN object was not requested.');
            }

            $response = UpstreamHttp::json()
                ->withHeaders(['Range' => 'bytes=0-3'])
                ->timeout(8)
                ->connectTimeout(3)
                ->get('https://cdn.spiget.org/file/spiget-resources/2'.$type);
            $body = (string) $response->body();
            if (!$response->successful() || !ArchiveSignature::isZip($body)) {
                return new DiagnosticCheck('spiget_cdn', 'fail', 'The SpiGet CDN sample was not a ZIP or JAR.');
            }

            return new DiagnosticCheck('spiget_cdn', 'pass', 'SpiGet CDN returned a ZIP or JAR signature for the current file of resource 2.');
        } catch (Throwable) {
            return new DiagnosticCheck('spiget_cdn', 'warning', 'SpiGet CDN sample could not be validated.');
        }
    }

    private function httpJson(string $id, string $url, string $label): DiagnosticCheck
    {
        try {
            $response = UpstreamHttp::json()->timeout(8)->connectTimeout(3)->get($url);
            if ($response->successful() && is_array($response->json())) {
                return new DiagnosticCheck($id, 'pass', $label.' responded.');
            }

            return new DiagnosticCheck($id, 'fail', $label.' returned HTTP '.$response->status().'.');
        } catch (Throwable) {
            return new DiagnosticCheck($id, 'fail', $label.' could not be reached.');
        }
    }

    private function cacheSchema(): DiagnosticCheck
    {
        $schema = (new ReflectionClass(SpigotSource::class))->getConstant('CACHE_SCHEMA');

        return new DiagnosticCheck(
            'cache_schema',
            $schema === 4 ? 'pass' : 'warning',
            'Source cache schema is '.var_export($schema, true).'.',
            ['schema' => $schema],
        );
    }

    private function backgroundJobs(): DiagnosticCheck
    {
        try {
            $pending = ModManagerBackgroundJob::query()
                ->where('available_at', '<=', now()->subHour())
                ->count();
        } catch (Throwable) {
            return new DiagnosticCheck('background_jobs', 'warning', 'Mod Manager background job storage could not be read.');
        }

        if ($pending > 0) {
            return new DiagnosticCheck('background_jobs', 'warning', $pending.' Mod Manager background job(s) have been waiting for more than an hour.', [
                'stale' => $pending,
            ]);
        }

        return new DiagnosticCheck('background_jobs', 'pass', 'No stale Mod Manager background jobs.');
    }

    private function tempDirectory(): DiagnosticCheck
    {
        $directory = sys_get_temp_dir();

        return is_writable($directory)
            ? new DiagnosticCheck('temp_directory', 'pass', 'The panel temporary directory is writable.')
            : new DiagnosticCheck('temp_directory', 'fail', 'The panel temporary directory is not writable.');
    }

    private function serverEgg(Server $server): DiagnosticCheck
    {
        $server->loadMissing('egg.variables');
        $egg = $server->egg;
        if ($egg === null) {
            return new DiagnosticCheck('server_egg', 'warning', 'This server has no egg.');
        }

        $classified = $this->eggs->one($egg);

        return new DiagnosticCheck('server_egg', 'pass', 'Classified '.$classified->name.' as '.($classified->profileId ?? 'unresolved').'.', [
            'egg_id' => $classified->id,
            'name' => $classified->name,
            'profile_id' => $classified->profileId,
            'loader' => $classified->loader,
            'project_type' => $classified->projectType,
            'confidence' => $classified->confidence,
            'match_source' => $classified->matchSource,
            'contradicts' => $classified->contradicts,
        ]);
    }
}
