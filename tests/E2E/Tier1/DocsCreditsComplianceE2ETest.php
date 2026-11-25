<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;

/**
 * Tier 1 & Boundary Compliance Tests for Feature #22 (License, Credits §6 & Documentation Refresh — M5).
 */
final class DocsCreditsComplianceE2ETest extends E2ETestCase
{
    public function test_license_and_cosmii02_mit_attribution_exist_across_all_required_files(): void
    {
        $root = self::pluginRoot();

        // 1. GPL-3.0 LICENSE intact
        $license = (string) file_get_contents($root.'/LICENSE');
        self::assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $license);
        self::assertStringContainsString('Version 3', $license);

        // 2. cosmii02/pelican-modpackmanager MIT credit in README.md, README.ja.md, docs/modpack-compatibility-audit.md
        foreach ([
            'README.md',
            'README.ja.md',
            'docs/modpack-compatibility-audit.md',
        ] as $relativePath) {
            $contents = (string) file_get_contents($root.'/'.$relativePath);
            self::assertStringContainsString(
                'cosmii02/pelican-modpackmanager',
                $contents,
                "M5 (#22): {$relativePath} must credit cosmii02/pelican-modpackmanager per ORIGINAL_REQUEST §6.",
            );
            self::assertStringContainsString(
                'https://github.com/cosmii02/pelican-modpackmanager',
                $contents,
                "M5 (#22): {$relativePath} must include GitHub URL for cosmii02/pelican-modpackmanager.",
            );
            self::assertMatchesRegularExpression(
                '/(?:cosmii02\/pelican-modpackmanager[\s\S]{0,250}(?<![A-Za-z])MIT(?![A-Za-z])|(?<![A-Za-z])MIT(?![A-Za-z])[\s\S]{0,250}cosmii02\/pelican-modpackmanager)/i',
                $contents,
                "M5 (#22): {$relativePath} must cite the MIT license alongside cosmii02/pelican-modpackmanager.",
            );
        }

        // 3. Existing JoanFo1456/resources attribution, GPL-3.0 notice, and cosmii02 MIT copyright notice in README.md and README.ja.md
        foreach (['README.md', 'README.ja.md'] as $readmePath) {
            $readme = (string) file_get_contents($root.'/'.$readmePath);
            self::assertStringContainsString('JoanFo1456/resources', $readme, "{$readmePath} must retain existing JoanFo1456/resources credit.");
            self::assertStringContainsString('GPL-3.0', $readme);
            self::assertMatchesRegularExpression(
                '/(?:Copyright\s*(?:\(c\)|©)?\s*2026\s+cosmii02|2026\s+cosmii02)/i',
                $readme,
                "M5 (#22): {$readmePath} must include the MIT copyright notice (Copyright (c) 2026 cosmii02) for cosmii02/pelican-modpackmanager per ORIGINAL_REQUEST §6.",
            );
        }
    }

    public function test_settings_env_documentation_and_stale_claims_cleanup_across_docs_and_changelog(): void
    {
        $root = self::pluginRoot();

        // 1. Both READMEs document MOD_MANAGER_MODRINTH_TOKEN and MOD_MANAGER_HANGAR_API_KEY
        foreach (['README.md', 'README.ja.md'] as $readmePath) {
            $readme = (string) file_get_contents($root.'/'.$readmePath);
            self::assertStringContainsString('MOD_MANAGER_MODRINTH_TOKEN', $readme, "M5 (#22): {$readmePath} must document MOD_MANAGER_MODRINTH_TOKEN.");
            self::assertStringContainsString('MOD_MANAGER_HANGAR_API_KEY', $readme, "M5 (#22): {$readmePath} must document MOD_MANAGER_HANGAR_API_KEY.");
            self::assertStringContainsString('MOD_MANAGER_CURSEFORGE_API_KEY', $readme);
            self::assertStringContainsString('MOD_MANAGER_GITHUB_TOKEN', $readme);
            self::assertStringNotContainsString('no automated test coverage', strtolower($readme), "M5 (#22): Stale no-test claim must be removed from {$readmePath}.");
            self::assertStringNotContainsString('自動テストが整備されておらず', $readme, "M5 (#22): Stale Japanese no-test claim must be removed from {$readmePath}.");
        }

        // 2. docs/modpack-updates.md removes stale 8 MiB / 2,000-file FTB limit claim
        $updatesDoc = (string) file_get_contents($root.'/docs/modpack-updates.md');
        self::assertStringNotContainsString(
            'ファイル一覧は8 MiB、サーバー向けの管理対象は2,000ファイルまでです',
            $updatesDoc,
            'M5 (#22): Stale 8 MiB / 2,000-file FTB limit claim must be updated in docs/modpack-updates.md.',
        );

        // 3. docs/architecture.md removes deleted Blade file references and stale canEditEggProfile claim
        $archDoc = (string) file_get_contents($root.'/docs/architecture.md');
        self::assertStringNotContainsString('table-swr-cache.blade.php', $archDoc, 'M5 (#22): Deleted table-swr-cache.blade.php reference must be removed from docs/architecture.md.');
        self::assertStringNotContainsString('table-layout.blade.php', $archDoc, 'M5 (#22): Deleted table-layout.blade.php reference must be removed from docs/architecture.md.');
        self::assertStringContainsString('table-swr-cache.js', $archDoc);
        self::assertStringNotContainsString('No automated test coverage yet', $archDoc);

        // 4. Negative guard across ALL docs/*.md: zero references to deleted Blade files
        $docFiles = glob($root.'/docs/*.md') ?: [];
        self::assertNotEmpty($docFiles);
        foreach ($docFiles as $docFile) {
            $body = (string) file_get_contents($docFile);
            self::assertStringNotContainsString('table-swr-cache.blade.php', $body, basename($docFile).' must not cite deleted table-swr-cache.blade.php.');
            self::assertStringNotContainsString('table-layout.blade.php', $body, basename($docFile).' must not cite deleted table-layout.blade.php.');
        }

        // 5. docs/modpack-compatibility-audit.md removes stale unhashed Wings deploy claim
        $auditDoc = (string) file_get_contents($root.'/docs/modpack-compatibility-audit.md');
        self::assertStringNotContainsString(
            '依存ハッシュの全件照合は未対応です',
            $auditDoc,
            'M5 (#22): Stale unhashed dependency claim must be updated in docs/modpack-compatibility-audit.md.',
        );

        // 6. docs/provider-regression-matrix.md removes stale 611-test PHPUnit count
        $matrixDoc = (string) file_get_contents($root.'/docs/provider-regression-matrix.md');
        self::assertStringNotContainsString(
            'PHPUnitは611件',
            $matrixDoc,
            'M5 (#22): Stale 611-test PHPUnit count must be updated in docs/provider-regression-matrix.md.',
        );

        // 7. CHANGELOG.md [Unreleased] has non-empty bullet entries under both ### 日本語 and ### English
        // (allowing optional #### Keep-a-Changelog category headings between ### and - bullets).
        $changelog = (string) file_get_contents($root.'/CHANGELOG.md');
        if (preg_match('/^## \[Unreleased\]\R([\s\S]*?)(?=^## \[|\z)/m', $changelog, $match) !== 1) {
            self::fail('CHANGELOG.md must contain a ## [Unreleased] section.');
        }
        $unreleased = $match[1];

        if (preg_match('/^### 日本語\R([\s\S]*?)(?=^### |\z)/m', $unreleased, $jaMatch) !== 1) {
            self::fail('M5 (#22): CHANGELOG.md [Unreleased] must contain a ### 日本語 subsection.');
        }
        self::assertMatchesRegularExpression(
            '/^\s*-\s+\S+/m',
            $jaMatch[1],
            'M5 (#22): CHANGELOG.md [Unreleased] ### 日本語 must contain at least one bullet entry.',
        );

        if (preg_match('/^### English\R([\s\S]*?)(?=^### |\z)/m', $unreleased, $enMatch) !== 1) {
            self::fail('M5 (#22): CHANGELOG.md [Unreleased] must contain a ### English subsection.');
        }
        self::assertMatchesRegularExpression(
            '/^\s*-\s+\S+/m',
            $enMatch[1],
            'M5 (#22): CHANGELOG.md [Unreleased] ### English must contain at least one bullet entry.',
        );
    }
}
