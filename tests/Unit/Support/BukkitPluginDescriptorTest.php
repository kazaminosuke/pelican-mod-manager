<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\BukkitPluginDescriptor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class BukkitPluginDescriptorTest extends TestCase
{
    public function test_paper_descriptor_wins_for_name_and_version_while_authors_fall_back(): void
    {
        $path = $this->jar([
            'plugin.yml' => "name: LegacyName\nversion: 1.0.0\nauthor: Alice\nwebsite: https://www.spigotmc.org/resources/example.42/\n",
            'paper-plugin.yml' => "name: PaperName\nversion: 2.0.0\n",
        ]);

        try {
            $descriptor = BukkitPluginDescriptor::fromJar($path, 'Example.jar');
        } finally {
            @unlink($path);
        }

        self::assertNotNull($descriptor);
        self::assertSame('PaperName', $descriptor->name);
        self::assertSame('2.0.0', $descriptor->version);
        self::assertSame(['Alice'], $descriptor->authors);
        self::assertSame('42', $descriptor->spigotResourceId());
    }

    public function test_authors_list_and_quoted_scalars_are_parsed(): void
    {
        $parsed = BukkitPluginDescriptor::parseYaml(
            "name: 'World Guard'\nversion: \"7.0.9\"\nauthors: [Alice, Bob]\nwebsite: https://www.spigotmc.org/resources/11431/\n",
        );

        $descriptor = BukkitPluginDescriptor::fromArray($parsed);

        self::assertSame('World Guard', $descriptor->name);
        self::assertSame('7.0.9', $descriptor->version);
        self::assertSame(['Alice', 'Bob'], $descriptor->authors);
        self::assertSame('11431', $descriptor->spigotResourceId());
        self::assertSame('worldguard', $descriptor->normalizedName());
        self::assertSame('7.0.9', $descriptor->normalizedVersion());
    }

    public function test_identity_normalization_strips_punctuation_and_version_prefixes(): void
    {
        self::assertSame('worldguard', BukkitPluginDescriptor::normalizeIdentity('World-Guard'));
        self::assertSame('1.2.3-beta', BukkitPluginDescriptor::normalizeVersion('v1.2.3-beta'));
    }

    /** @param array<string, string> $files */
    private function jar(array $files): string
    {
        self::assertTrue(class_exists(ZipArchive::class));

        $base = tempnam(sys_get_temp_dir(), 'pmm-bukkit-');
        if (is_string($base)) {
            @unlink($base);
        }
        $path = $base.'.jar';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }
}
