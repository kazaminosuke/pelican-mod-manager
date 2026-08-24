<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataIndex;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use PHPUnit\Framework\TestCase;

final class InstalledMetadataIndexTest extends TestCase
{
    public function test_cold_generation_loads_once_then_reads_only_requested_identities(): void
    {
        $index = new InstalledMetadataIndex(new Repository(new ArrayStore()));
        $loads = 0;
        $load = function () use (&$loads): InstalledMetadataReadResult {
            $loads++;

            return $this->readResult([
                $this->entry('sodium'),
                $this->entry('lithium'),
                $this->entry('ferrite-core'),
            ]);
        };

        $first = $index->getMany(
            $this->server(),
            ProjectType::Mod,
            4,
            ['modrinth:sodium', 'modrinth:missing'],
            $load,
        );
        $second = $index->getMany(
            $this->server(),
            ProjectType::Mod,
            4,
            ['modrinth:lithium'],
            $load,
        );

        self::assertSame(1, $loads);
        self::assertSame(['modrinth:sodium'], array_keys($first));
        self::assertSame(['modrinth:lithium'], array_keys($second));
    }

    public function test_hydration_generation_change_reloads_the_source_document(): void
    {
        $index = new InstalledMetadataIndex(new Repository(new ArrayStore()));
        $loads = 0;
        $load = function () use (&$loads): InstalledMetadataReadResult {
            $loads++;

            return $this->readResult([$this->entry('sodium')]);
        };

        $index->getMany($this->server(), ProjectType::Mod, 1, ['modrinth:sodium'], $load);
        $index->getMany($this->server(), ProjectType::Mod, 2, ['modrinth:sodium'], $load);

        self::assertSame(2, $loads);
    }

    public function test_unavailable_document_never_marks_the_generation_ready(): void
    {
        $index = new InstalledMetadataIndex(new Repository(new ArrayStore()));
        $loads = 0;
        $load = function () use (&$loads): InstalledMetadataReadResult {
            $loads++;

            return new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Unavailable,
            );
        };

        $index->getMany($this->server(), ProjectType::Mod, 1, ['modrinth:sodium'], $load);
        $index->getMany($this->server(), ProjectType::Mod, 1, ['modrinth:sodium'], $load);

        self::assertSame(2, $loads);
    }

    public function test_prime_reuses_a_document_loaded_by_the_installed_tab(): void
    {
        $index = new InstalledMetadataIndex(new Repository(new ArrayStore()));
        $server = $this->server();
        $index->prime($server, ProjectType::Plugin, 7, [$this->entry('viaversion')]);

        $entries = $index->getMany(
            $server,
            ProjectType::Plugin,
            7,
            ['modrinth:viaversion'],
            static fn () => throw new \RuntimeException('The primed generation must not reload.'),
        );

        self::assertSame('viaversion', $entries['modrinth:viaversion']['project_id']);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $server;
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function readResult(array $entries): InstalledMetadataReadResult
    {
        return new InstalledMetadataReadResult(
            InstalledMetadataDocument::empty()->withInstalledMods($entries),
            InstalledMetadataReadStatus::Current,
        );
    }

    /** @return array<string, mixed> */
    private function entry(string $projectId): array
    {
        return [
            'source' => 'modrinth',
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'project_title' => ucfirst($projectId),
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => $projectId.'.jar',
            'installed_at' => '2026-08-25T00:00:00Z',
        ];
    }
}
