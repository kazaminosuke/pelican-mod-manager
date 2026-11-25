<?php

namespace Kazaminosuke\ModManager\Services;

use App\Enums\ContainerStatus;
use App\Enums\ServerState;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Contracts\Cache\Repository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;

final class ModpackUpdateWorkflow
{
    private const TTL = 86400;

    // A blank request makes Wings read .pelicanignore or .pteroignore.
    // An explicit comment is a nonempty request with no exclusion patterns.
    private const FULL_BACKUP = ['# Full modpack update backup'];

    public function __construct(
        private readonly Repository $cache,
        private readonly DaemonServerRepository $daemon,
        private readonly InstalledOperationManager $operations,
        private readonly InstalledOperationLease $leases,
    ) {}

    public function review(Server $server): ?array
    {
        $review = $this->cache->get($this->key($server));

        return is_array($review) ? $review : null;
    }

    public function publish(Server $server, string $lease, array $release, array $selection, array $summary, ?array $artifact = null): void
    {
        if (!$this->leases->owns((int) $server->getKey(), ProjectType::Mod, $lease)) {
            throw new ModpackException('The modpack operation no longer owns the server lease.');
        }
        // The file-wait token authorizes only the preflight that just finished.
        unset($selection['manual_token']);
        $this->cache->put($this->key($server), [
            'token' => bin2hex(random_bytes(16)), 'release' => $release, 'selection' => $selection,
            'summary' => $summary, 'artifact' => $artifact, 'created_at' => time(), 'backup_id' => null,
        ], self::TTL);
        $this->operations->awaitModpackReview($server, $lease);
    }

    public function extendTtl(Server $server, string $token, int $ttl): void
    {
        $review = $this->review($server);
        if ($review !== null && hash_equals($review['token'], $token)) {
            $this->cache->put($this->key($server), $review, max(self::TTL, $ttl));
        }
    }

    public function createBackup(Server $server, string $token, InitiateBackupService $backups): void
    {
        $this->leases->run((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK, function () use ($server, $token, $backups): void {
            $review = $this->requiredReview($server, $token);
            $this->assertStopped($server);
            $existing = $this->backup($server, $review);
            if ($existing !== null && (!$existing->completed_at || $existing->is_successful)) {
                return;
            }
            if (($review['summary']['blocking_conflicts'] ?? $review['summary']['mod_conflicts']) !== []) {
                throw new ModpackException('Resolve the blocking files and generate a new preview before creating a backup.');
            }
            try {
                $backup = $backups->setIgnoredFiles(self::FULL_BACKUP)->setIsLocked(false)->handle($server, 'Modpack update');
            } catch (TooManyBackupsException $exception) {
                throw new ModpackException(trans('pelican-mod-manager::strings.modpack_updates.backup_capacity'), 0, $exception);
            }
            $review['backup_id'] = (int) $backup->getKey();
            $this->cache->put($this->key($server), $review, self::TTL);
        });
    }

    public function backup(Server $server, array $review): ?Backup
    {
        if ($review['backup_id'] === null) {
            return null;
        }

        $backup = $server->backups()->find($review['backup_id']);

        return $backup?->ignored_files === self::FULL_BACKUP ? $backup : null;
    }

    public function requireBackup(Server $server, array $review): Backup
    {
        $backup = $this->backup($server, $review);
        if ($backup === null || !$backup->is_successful || $backup->completed_at === null
            || $backup->created_at->getTimestamp() < $review['created_at']) {
            throw new ModpackException('A successful full Pelican backup from this preview is required.');
        }

        return $backup;
    }

    public function lockForApply(Server $server, Backup $backup): bool
    {
        return $server->backups()->whereKey($backup->getKey())->where('is_locked', false)->update(['is_locked' => true]) === 1;
    }

    public function unlockAfterApply(Server $server, string $uuid): void
    {
        $server->backups()->where('uuid', $uuid)->update(['is_locked' => false]);
    }

    public function approve(Server $server, string $token, bool $switch, ?int $startUser): void
    {
        $review = $this->requiredReview($server, $token);
        $this->assertStopped($server);
        $this->requireBackup($server, $review);
        if (($review['summary']['blocking_conflicts'] ?? $review['summary']['mod_conflicts']) !== []) {
            throw new ModpackException('Resolve the blocking files and generate a new preview.');
        }
        if ($review['release']['switch'] && !$switch) {
            throw new ModpackException('Explicit confirmation is required to switch to a different modpack.');
        }

        $release = $review['release'];
        $dispatch = $this->operations->dispatchModpackInstall($server, ProjectType::Mod, $release['provider'],
            $release['pack_id'], $release['version_id'], selection: [
                ...$review['selection'], 'managed_phase' => 'apply', 'review_token' => $token, 'start_user' => $startUser,
            ]);
        if (!$dispatch['dispatched']) {
            throw new ModpackException('The approved modpack update could not be queued.');
        }
    }

    public function requiredReview(Server $server, string $token): array
    {
        $review = $this->review($server);
        if ($review === null || !hash_equals($review['token'], $token)) {
            throw new ModpackException('The modpack preview expired or was replaced. Generate a new preview.');
        }

        return $review;
    }

    public function assertStopped(Server $server, bool $provisioning = false): void
    {
        $fresh = $server->fresh();
        if ($fresh === null || $fresh->status !== ($provisioning ? ServerState::Installing : null)
            || ($provisioning && !$fresh->skip_scripts)
            || $this->daemon->setServer($server)->getState(5) !== ContainerStatus::Offline) {
            throw new ModpackException('Stop the server in Pelican and wait for Wings to confirm it is offline.');
        }
    }

    public function confirmRestored(Server $server, string $token, ModpackDeployment $deployment, DaemonFileRepository $repo): void
    {
        $this->leases->run((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK, function () use ($server, $token, $deployment, $repo): void {
            $this->assertStopped($server);
            $recovery = app(ModpackInventory::class)->read($server)['recovery'];
            $deployment->confirmRestored($server, $repo, $token);
            if (($recovery['unlock_backup'] ?? false) && is_string($recovery['backup'])) {
                $this->unlockAfterApply($server, $recovery['backup']);
            }
            $this->done($server);
            $this->operations->forget($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        });
    }

    public function cancel(Server $server, string $token): void
    {
        $this->leases->run((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK, function () use ($server, $token): void {
            $this->requiredReview($server, $token);
            $this->done($server);
            $this->operations->forget($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        });
    }

    public function done(Server $server): void
    {
        $this->cache->forget($this->key($server));
    }

    private function key(Server $server): string
    {
        return 'mod-manager:modpack-review:'.$server->uuid;
    }
}
