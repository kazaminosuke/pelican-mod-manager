<?php

namespace Kazaminosuke\ModManager\Facades;

use App\Models\Server;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledScanResult;

/**
 * @method static ?string getMinecraftVersion(Server $server)
 * @method static string getHashScanCacheKey(Server $server, ?ProjectType $type = null)
 * @method static string getProjectFolder(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, ?ProjectType $type = null)
 * @method static string getDatapackWorldName(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository)
 * @method static InstalledScanResult scanAndImportModsResult(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, ?ProjectType $type = null)
 * @method static InstalledMetadataReadResult getInstalledMetadataReadResult(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, ?ProjectType $type = null)
 * @method static bool saveModMetadata(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, string $projectId, string $projectSlug, string $projectTitle, string $versionId, string $versionNumber, string $filename, ?string $author = null, ?ProjectType $type = null, ProjectSourceKey $source = ProjectSourceKey::Modrinth)
 * @method static bool saveInstalledMetadataDocument(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, InstalledMetadataDocument $document, ?ProjectType $type = null)
 * @method static bool removeModMetadata(Server $server, \App\Repositories\Daemon\DaemonFileRepository $fileRepository, string $projectId, ?ProjectType $type = null, ProjectSourceKey $source = ProjectSourceKey::Modrinth)
 *
 * @see InstalledProjectService
 */
class ModManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InstalledProjectService::class;
    }
}
