<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\ArchiveSignature;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDelta;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Throwable;
use ZipArchive;

/**
 * A single deployment. Listings are shared while planning, then discarded
 * before the final recheck. Once live writes start, backups remain until the
 * Panel database accepts the new inventory.
 */
final class ModpackDeployment
{
    private array $listings = [];

    public function __construct(
        private readonly WingsRemoteFilesystem $wings,
        private readonly ModpackInventory $inventory,
    ) {}

    /**
     * @param array<string, array{identity: ?string, sha256: ?string, size: ?int, hashes: array, file_id: ?string, project_id: ?string, open: ?callable, url: ?string}> $target
     * @return array<string, mixed>
     */
    public function run(Server $server, DaemonFileRepository $repo, array $release, array $target, ?string $approval, bool $preview, ?string $backup, callable $guard): array
    {
        $this->listings = [];
        $state = $this->inventory->read($server);
        if ($state['recovery'] !== null) {
            throw new ModpackException('A previous modpack change requires recovery. Restore the recorded backup before making further changes.');
        }

        $world = $this->world($server, $repo);
        $owned = $state['inventory']['files'] ?? [];
        $protected = [];
        foreach (array_unique([...array_keys($target), ...array_keys($owned)]) as $path) {
            if (!ModpackPaths::isManaged($path, $world)) {
                $protected[] = $path;
                unset($target[$path], $owned[$path]);
            }
        }
        $this->assertDistinctPaths($target);
        $individual = $this->individuallyManaged($server, $repo);
        $plan = $this->plan($server, $repo, $owned, $target, $individual);
        $summary = $this->summary($plan, $protected);
        if (($release['legacy'] ?? false) && ModpackPaths::isManaged('mods/placeholder.jar', $world)) {
            foreach ($this->listing($server, $repo, 'mods') as $entry) {
                $path = 'mods/'.$entry['name'];
                if (str_ends_with(strtolower($path), '.jar')
                    && !isset($individual[strtolower($path)]) && !isset($plan[$path])) {
                    $summary['mod_conflicts'][] = $path;
                    $summary['conflicts'][] = $path;
                    $summary['mods']['conflict']++;
                    $summary['counts']['conflict']++;
                }
            }
        }
        if ($preview) {
            return [...$summary, 'digest' => $this->fingerprint($state['revision'], $release, $world, $plan, $target)];
        }
        if ($approval !== null && !hash_equals($approval, $this->fingerprint($state['revision'], $release, $world, $plan, $target))) {
            throw new ModpackException('The files or ownership changed since the preview. Generate a new preview.');
        }
        if ($summary['mod_conflicts'] !== []) {
            throw new ModpackException('Conflicting mods were preserved. Resolve the conflicts in the update preview before updating.');
        }

        $guard();
        $token = bin2hex(random_bytes(8));
        $staged = [];
        $applied = [];
        $started = false;
        $recovered = true;
        try {
            foreach ($plan as $path => $change) {
                if (!in_array($change['action'], ['add', 'replace'], true)) {
                    continue;
                }
                [$directory] = ModpackPaths::split($path);
                $temp = '.mod-manager-next-'.$token.'-'.count($staged);
                $staged[$path] = ['temp' => $temp];
                $staged[$path]['sha256'] = $this->stage($server, $repo, $directory, $temp, $path, $target[$path]);
                $guard(false);
            }

            $guard();
            if ($staged !== [] || $summary['counts']['delete'] > 0) {
                $this->listings = [];
                if ($world !== $this->world($server, $repo)
                    || $plan !== $this->plan($server, $repo, $owned, $target, $this->individuallyManaged($server, $repo))) {
                    throw new ModpackException('Server files changed during staging. No modpack files were applied.');
                }
                $guard();
            }
            $originals = [];
            foreach ($plan as $path => $change) {
                if (in_array($change['action'], ['add', 'replace', 'delete'], true)) {
                    $originals[$path] = $change['observed'];
                }
            }
            $this->inventory->begin($server, $state['revision'], $token, $backup, $originals);
            $started = true;
            $next = [];
            foreach ($plan as $path => $change) {
                $action = $change['action'];
                if (in_array($action, ['keep', 'conflict', 'modified'], true) && isset($owned[$path])) {
                    $next[$path] = $owned[$path];
                }
                if (!in_array($action, ['add', 'replace', 'delete'], true)) {
                    continue;
                }
                $guard(false);
                [$directory, $filename] = ModpackPaths::split($path);
                $previous = $action === 'add' ? null : '.mod-manager-prev-'.$token.'-'.count($applied);
                $applied[$path] = ['previous' => $previous, 'original' => $change['observed'], 'new' => $staged[$path]['sha256'] ?? null];
                if ($previous !== null) {
                    $this->wings->move($repo, $server, $directory, $filename, $previous);
                    if ($this->digest($server, $repo, $directory.'/'.$previous) !== $change['observed']) {
                        throw new ModpackException("The backup move of [$path] did not preserve its content.");
                    }
                }
                if ($action !== 'delete') {
                    $this->wings->move($repo, $server, $directory, $staged[$path]['temp'], $filename);
                    $next[$path] = $this->ownership($release, $target[$path], $staged[$path]['sha256']);
                }
            }

            $this->listings = [];
            foreach ($applied as $path => $change) {
                if ($change['new'] === null ? $this->entry($server, $repo, $path) !== null
                    : $this->digest($server, $repo, $path) !== $change['new']) {
                    throw new ModpackException("Wings did not apply [$path] with its verified content.");
                }
            }

            $guard();
            $this->inventory->finish($server, $token, [...$release, 'files' => $next]);
        } catch (Throwable $error) {
            if ($started) {
                try {
                    $guard();
                    $recovered = $this->rollback($server, $repo, $applied);
                } catch (Throwable $rollbackError) {
                    report($rollbackError);
                    $recovered = false;
                }
                if ($recovered) {
                    $this->inventory->abort($server, $token);
                }
            }
            if (!$recovered) {
                throw new ModpackException('Rollback could not be verified. Further updates are blocked pending backup recovery.', 0, $error);
            }

            throw $error;
        } finally {
            foreach ($staged as $path => $stage) {
                $this->wings->deleteQuietly($repo, $server, ModpackPaths::split($path)[0], $stage['temp']);
            }
        }
        foreach ($applied as $path => $change) {
            if ($change['previous'] !== null) {
                $this->wings->deleteQuietly($repo, $server, ModpackPaths::split($path)[0], $change['previous']);
            }
        }

        return $summary;
    }

    /**
     * Clear a crash journal only after proving that every touched path
     * is back at its previous value. Restoration itself belongs to Panel.
     */
    public function confirmRestored(Server $server, DaemonFileRepository $repo, string $token): void
    {
        $this->listings = [];
        $journal = $this->inventory->read($server)['recovery'];
        if ($journal === null || !hash_equals($journal['transaction'], $token) || !is_array($journal['originals'] ?? null)) {
            throw new ModpackException('The modpack recovery journal no longer matches this request.');
        }
        $world = $this->world($server, $repo);
        foreach ($journal['originals'] as $path => $expected) {
            if (!ModpackPaths::isManaged($path, $world)) {
                throw new ModpackException("Cannot verify recovery of protected path [$path].");
            }
            $entry = $this->entry($server, $repo, $path);
            $actual = $entry === null ? null : ($this->regular($entry) ? $this->digest($server, $repo, $path) : false);
            if ($actual !== $expected) {
                throw new ModpackException("Restore the previous file at [$path] before clearing the recovery journal.");
            }
        }
        $this->inventory->abort($server, $token);
    }

    private function fingerprint(?string $revision, array $release, string $world, array $plan, array $target): string
    {
        $identities = [];
        foreach (array_keys($plan) as $path) {
            if (isset($target[$path])) {
                $file = $target[$path];
                $identities[$path] = [$file['identity'], $file['sha256'], $file['hashes'], $file['size']];
            }
        }

        return hash('sha256', json_encode([$revision, $release, $world, $plan, $identities], JSON_THROW_ON_ERROR));
    }

    private function ownership(array $release, array $item, string $sha256): array
    {
        return ['sha256' => $sha256, 'identity' => $item['identity'], 'provider' => $release['provider'],
            'version_id' => $release['version_id'], 'file_id' => $item['file_id'], 'project_id' => $item['project_id'],
            'size' => $item['size']];
    }

    private function plan(Server $server, DaemonFileRepository $repo, array $owned, array $target, array $individual): array
    {
        return ModpackDelta::plan($owned, $target, function (string $path, bool $tracked) use ($server, $repo, $individual) {
            $entry = $this->entry($server, $repo, $path);
            if ($entry === null) {
                return null;
            }
            if (!$tracked || isset($individual[strtolower($path)]) || !$this->regular($entry)) {
                return false;
            }

            return $this->digest($server, $repo, $path);
        });
    }

    private function summary(array $plan, array $protected): array
    {
        $counts = array_fill_keys(['add', 'replace', 'delete', 'keep', 'conflict', 'modified', 'preserve'], 0);
        $mods = $counts;
        $conflicts = $modConflicts = $paths = [];
        $bytes = $unknown = 0;
        foreach ($plan as $path => $entry) {
            $action = $entry['action'];
            $counts[$action]++;
            $isMod = str_starts_with(strtolower($path), 'mods/');
            if ($isMod) {
                $mods[$action]++;
            }
            if (in_array($action, ['conflict', 'modified'], true)) {
                $conflicts[] = $path;
                if ($isMod) {
                    $modConflicts[] = $path;
                }
            }
            if (in_array($action, ['add', 'replace'], true)) {
                $bytes += $entry['size'] ?? 0;
                $unknown += (int) ($entry['size'] === null);
            }
            if ($action !== 'keep') {
                $paths[$path] = $action;
            }
        }

        return ['counts' => $counts, 'mods' => $mods, 'conflicts' => $conflicts, 'mod_conflicts' => $modConflicts,
            'paths' => $paths, 'protected' => $protected, 'stage_bytes' => $bytes, 'unknown_sizes' => $unknown];
    }

    private function assertDistinctPaths(array $target): void
    {
        $paths = array_change_key_case($target);
        if (count($paths) !== count($target)) {
            throw new ModpackException('The modpack contains case-insensitive duplicate paths.');
        }
        foreach (array_keys($paths) as $path) {
            [$directory] = ModpackPaths::split($path);
            while ($directory !== '') {
                if (isset($paths[$directory])) {
                    throw new ModpackException('A modpack file conflicts with a target directory.');
                }
                [$directory] = ModpackPaths::split($directory);
            }
        }
    }

    private function individuallyManaged(Server $server, DaemonFileRepository $repo): array
    {
        $entry = $this->entry($server, $repo, 'mods/.pelican-mod-manager.json');
        if ($entry === null) {
            return [];
        }
        $metadata = json_decode($this->smallFile($server, $repo, 'mods/.pelican-mod-manager.json', $entry, 4_000_000), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata['installed_mods'] ?? null)) {
            throw new ModpackException('The individual mod inventory is unreadable.');
        }
        $files = [];
        foreach ($metadata['installed_mods'] as $mod) {
            if (is_string($mod['filename'] ?? null)) {
                $files['mods/'.strtolower($mod['filename'])] = true;
            }
        }

        return $files;
    }

    private function world(Server $server, DaemonFileRepository $repo): string
    {
        $entry = $this->entry($server, $repo, 'server.properties');
        if ($entry === null) {
            return 'world';
        }
        $properties = $this->smallFile($server, $repo, 'server.properties', $entry, 262144);
        $world = 'world';
        foreach (preg_split('/\r\n|\r|\n/', $properties) as $line) {
            $line = ltrim($line, " \t\x0c\xef\xbb\xbf");
            if ($line === '' || str_contains('#!', $line[0])) {
                continue;
            }
            // Java properties can escape keys and continue values onto the next line.
            // Refuse ambiguous keys or a continued line rather than guessing a world.
            if (str_ends_with($line, '\\') || preg_match('/^[^\s=:]*\\\\/', $line) === 1) {
                throw new ModpackException('The world directory cannot be safely resolved from server.properties.');
            }
            if (preg_match('/^level-name(?:\s*[:=]\s*|\s+)(.*)$/D', $line, $match) === 1 || $line === 'level-name') {
                $world = trim($match[1] ?? '');
                if ($world === '' || ModpackPaths::safeRelative($world) !== $world || str_contains($world, ':')) {
                    throw new ModpackException('The custom world path is unsafe or unsupported.');
                }
            }
        }

        return $world;
    }

    private function smallFile(Server $server, DaemonFileRepository $repo, string $path, array $entry, int $limit): string
    {
        if (!$this->regular($entry) || ($this->wings->listedFileSize($entry) ?? PHP_INT_MAX) > $limit) {
            throw new ModpackException("Cannot safely read [$path].");
        }
        $stream = $this->wings->readStream($repo, $server, $path);
        try {
            $body = '';
            while (!$stream->eof() && strlen($body) <= $limit) {
                $chunk = $stream->read($limit + 1 - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            if (strlen($body) !== $this->wings->listedFileSize($entry)) {
                throw new ModpackException("An incomplete file was read at [$path].");
            }

            return $body;
        } finally {
            $stream->close();
        }
    }

    private function regular(array $entry): bool
    {
        return ($entry['file'] ?? false) === true && ($entry['symlink'] ?? true) === false;
    }

    private function entry(Server $server, DaemonFileRepository $repo, string $path): ?array
    {
        [$directory, $filename] = ModpackPaths::split($path);
        if ($directory !== '' && !isset($this->listings[$directory])) {
            $parent = $this->entry($server, $repo, $directory);
            if ($parent === null) {
                return null;
            }
            if (($parent['directory'] ?? false) !== true || ($parent['symlink'] ?? true) !== false || $parent['name'] !== basename($directory)) {
                throw new ModpackException("The parent directory [$directory] is not safe to update.");
            }
            // Protect worlds stored under a pack directory even if a server mod
            // loads them separately from the level-name setting.
            $this->listing($server, $repo, $directory);
            foreach (['level.dat', 'level.dat_old', 'session.lock', 'uid.dat'] as $marker) {
                if (isset($this->listings[$directory][$marker])) {
                    throw new ModpackException("The directory [$directory] contains world data.");
                }
            }
        }

        $entry = $this->listing($server, $repo, $directory)[strtolower($filename)] ?? null;
        if ($entry !== null && $entry['name'] !== $filename) {
            throw new ModpackException("The path [$path] differs in case from an existing path.");
        }

        return $entry;
    }

    private function listing(Server $server, DaemonFileRepository $repo, string $directory): array
    {
        if (!isset($this->listings[$directory])) {
            $entries = [];
            foreach ($this->wings->listDirectory($repo, $server, $directory) as $entry) {
                $key = strtolower($entry['name']);
                if (isset($entries[$key])) {
                    throw new ModpackException("The directory [$directory] has ambiguous file names.");
                }
                $entries[$key] = $entry;
            }
            $this->listings[$directory] = $entries;
        }

        return $this->listings[$directory];
    }

    private function digest(Server $server, DaemonFileRepository $repo, string $path): string
    {
        return $this->inspect($server, $repo, $path)['sha256'];
    }

    private function stage(Server $server, DaemonFileRepository $repo, string $directory, string $temp, string $path, array $item): string
    {
        if ($item['open'] !== null) {
            $stream = ($item['open'])();
            try {
                $this->wings->putStream($repo, $server, $directory.'/'.$temp, $stream);
            } finally {
                $stream->close();
            }
        } else {
            $this->wings->pullForeground($repo, $server, $item['url'], $directory, $temp);
        }
        $expected = $item['hashes'];
        if ($item['sha256'] !== null) {
            $expected['sha256'] = $item['sha256'];
        }
        $jar = preg_match('/\.(jar|zip)$/iD', $path) === 1;

        return $this->inspect($server, $repo, $directory.'/'.$temp, $expected, $item['size'], $jar)['sha256'];
    }

    private function inspect(Server $server, DaemonFileRepository $repo, string $path, array $expected = [], ?int $size = null, bool $archive = false): array
    {
        $stream = $this->wings->readStream($repo, $server, $path);
        $algorithms = ['sha256' => hash_init('sha256')];
        foreach (array_intersect(array_keys($expected), ['sha1', 'sha512', 'md5']) as $algorithm) {
            $algorithms[$algorithm] = hash_init($algorithm);
        }
        $bytes = 0;
        $copy = null;
        try {
            if ($archive && $expected === []) {
                $copy = tmpfile();
                if ($copy === false) {
                    throw new ModpackException('Cannot allocate a temporary file to validate the archive.');
                }
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(262144);
                if ($chunk === '' && !$stream->eof()) {
                    throw new ModpackException("Failed to read [$path].");
                }
                if ($bytes === 0 && $archive && !ArchiveSignature::isZip($chunk)) {
                    throw new ModpackException("File [$path] is not an archive.");
                }
                $bytes += strlen($chunk);
                if ($bytes > ($size ?? 1024 ** 3)) {
                    throw new ModpackException("File [$path] exceeds its expected size.");
                }
                if (is_resource($copy) && fwrite($copy, $chunk) !== strlen($chunk)) {
                    throw new ModpackException('Cannot write a temporary copy for archive validation.');
                }
                foreach ($algorithms as $context) {
                    hash_update($context, $chunk);
                }
            }
            if (($size !== null && $bytes !== $size) || ($archive && $bytes < 4)) {
                throw new ModpackException("File [$path] does not match its expected size.");
            }
            if (is_resource($copy)) {
                $zip = new ZipArchive();
                if ($zip->open(stream_get_meta_data($copy)['uri'], ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                    throw new ModpackException("File [$path] does not have a valid ZIP structure.");
                }
                $zip->close();
            }
            $actual = array_map(hash_final(...), $algorithms);
            foreach ($expected as $algorithm => $hash) {
                if (isset($actual[$algorithm]) && !hash_equals($hash, $actual[$algorithm])) {
                    throw new ModpackException("File [$path] does not match its published hash.");
                }
            }

            return $actual;
        } finally {
            $stream->close();
            if (is_resource($copy)) {
                fclose($copy);
            }
        }
    }

    private function rollback(Server $server, DaemonFileRepository $repo, array $changes): bool
    {
        $ok = true;
        foreach (array_reverse($changes, true) as $path => $change) {
            try {
                $this->listings = [];
                [$directory, $filename] = ModpackPaths::split($path);
                $entry = $this->entry($server, $repo, $path);
                $current = $entry === null ? null : ($this->regular($entry) ? $this->digest($server, $repo, $path) : false);
                if ($current === $change['original']) {
                    continue;
                }
                if ($current !== null) {
                    if ($current !== $change['new']) {
                        throw new ModpackException("File [$path] changed during rollback.");
                    }
                    $this->wings->delete($repo, $server, $directory, $filename);
                }
                if ($change['previous'] === null) {
                    $this->listings = [];
                    if ($this->entry($server, $repo, $path) !== null) {
                        throw new ModpackException("New file [$path] could not be removed.");
                    }
                } else {
                    $this->wings->move($repo, $server, $directory, $change['previous'], $filename);
                    if ($this->digest($server, $repo, $path) !== $change['original']) {
                        throw new ModpackException("File [$path] could not be restored.");
                    }
                }
            } catch (Throwable $error) {
                report($error);
                $ok = false;
            }
        }

        return $ok;
    }
}
