<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataWriteSession;
use PHPUnit\Framework\TestCase;

final class InstalledMetadataWriteSessionTest extends TestCase
{
    public function test_successful_put_advances_the_authoritative_snapshot(): void
    {
        $persisted = [];
        $session = new InstalledMetadataWriteSession(
            InstalledMetadataDocument::empty(),
            function (InstalledMetadataDocument $document) use (&$persisted): bool {
                $persisted[] = $document;

                return true;
            },
        );

        self::assertTrue($session->upsert($this->entry('sodium', 'sodium.jar')));
        self::assertCount(1, $persisted);
        self::assertSame('sodium', $session->document()->installedMods()[0]['project_id']);
    }

    public function test_failed_put_does_not_advance_the_authoritative_snapshot(): void
    {
        $initial = InstalledMetadataDocument::empty()->withInstalledMods([
            $this->entry('sodium', 'sodium.jar'),
        ]);
        $session = new InstalledMetadataWriteSession($initial, fn (): bool => false);

        self::assertFalse($session->upsert($this->entry('lithium', 'lithium.jar')));
        self::assertSame($initial, $session->document());
        self::assertSame(['sodium'], array_column($session->document()->installedMods(), 'project_id'));
    }

    /** @return array<string, mixed> */
    private function entry(string $projectId, string $filename): array
    {
        return [
            'source' => 'modrinth',
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'project_title' => ucfirst($projectId),
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => $filename,
            'installed_at' => '2026-08-25T00:00:00Z',
        ];
    }
}
