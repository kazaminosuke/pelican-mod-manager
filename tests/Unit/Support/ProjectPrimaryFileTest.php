<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Exception;
use Kazaminosuke\ModManager\Support\ProjectPrimaryFile;
use PHPUnit\Framework\TestCase;

class ProjectPrimaryFileTest extends TestCase
{
    public function test_scalar_files_payload_does_not_throw(): void
    {
        self::assertNull(ProjectPrimaryFile::fromFiles('not-an-array'));
        self::assertNull(ProjectPrimaryFile::fromFiles(null));
        self::assertNull(ProjectPrimaryFile::fromVersion(['files' => 'broken']));
    }

    public function test_scalar_elements_are_ignored(): void
    {
        $file = ProjectPrimaryFile::fromFiles([
            'oops',
            12,
            [
                'primary' => true,
                'filename' => 'sodium.jar',
                'url' => 'https://example.test/sodium.jar',
            ],
        ]);

        self::assertSame('sodium.jar', $file['filename'] ?? null);
    }

    public function test_first_downloadable_file_is_used_when_primary_flag_is_missing(): void
    {
        $file = ProjectPrimaryFile::fromFiles([
            ['filename' => 'a.jar', 'url' => 'https://example.test/a.jar'],
            ['filename' => 'b.jar', 'url' => 'https://example.test/b.jar', 'primary' => true],
        ]);

        self::assertSame('b.jar', $file['filename'] ?? null);

        $fallback = ProjectPrimaryFile::fromFiles([
            ['filename' => 'a.jar', 'url' => 'https://example.test/a.jar'],
        ]);

        self::assertSame('a.jar', $fallback['filename'] ?? null);
    }

    public function test_require_from_files_throws_for_malformed_payloads(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Latest version has no primary file.');

        ProjectPrimaryFile::requireFromFiles(['not-a-file']);
    }
}
