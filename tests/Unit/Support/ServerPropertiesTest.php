<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\ServerProperties;
use PHPUnit\Framework\TestCase;

final class ServerPropertiesTest extends TestCase
{
    public function test_updates_existing_values_without_reordering_unrelated_lines(): void
    {
        $content = "# Keep this comment\r\nlevel-name=world\r\nresource-pack = old\r\nresource-pack-sha1=oldhash\r\n";

        self::assertSame(
            "# Keep this comment\r\nlevel-name=world\r\nresource-pack = https://example.test/pack.zip\r\nresource-pack-sha1=0123456789abcdef0123456789abcdef01234567\r\n",
            ServerProperties::withResourcePack(
                $content,
                'https://example.test/pack.zip',
                '0123456789abcdef0123456789abcdef01234567',
            ),
        );
    }

    public function test_adds_missing_values_using_the_existing_newline_style(): void
    {
        self::assertSame(
            "motd=Example\nresource-pack=https://example.test/pack.zip\nresource-pack-sha1=0123456789abcdef0123456789abcdef01234567",
            ServerProperties::withResourcePack(
                "motd=Example",
                'https://example.test/pack.zip',
                '0123456789abcdef0123456789abcdef01234567',
            ),
        );
    }

    public function test_clears_existing_values_without_removing_the_keys(): void
    {
        self::assertSame(
            "resource-pack=\nresource-pack-sha1=\n",
            ServerProperties::withResourcePack(
                "resource-pack=https://example.test/pack.zip\nresource-pack-sha1=0123456789abcdef0123456789abcdef01234567\n",
                null,
                null,
            ),
        );
    }
}
