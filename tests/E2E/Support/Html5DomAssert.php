<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Support;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Assert;

/**
 * WHATWG HTML5 DOM assertion helpers backed by PHP 8.4's native lexbor parser.
 *
 * Catches real browser HTML5 Adoption Agency Algorithm anchor fragmentation
 * (e.g., nested <a> tags collapsing Filament's <a class="fi-ta-record-content">)
 * and verifies Filament-native Stack/Split tile metadata layout.
 */
final class Html5DomAssert
{
    public static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
    }

    public static function assertNoModrinthAuthorLinks(string $html, string $context = ''): void
    {
        $doc = self::parse($html);
        $offending = [];
        foreach ($doc->querySelectorAll('a[href]') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if (preg_match('#^(?:https?:)?//(?:www\.)?modrinth\.com/user/#i', $href) === 1) {
                $offending[] = $href;
            }
        }

        Assert::assertSame(
            [],
            $offending,
            trim($context.' Expected zero modrinth.com/user/ links in rendered HTML, found: '.implode(', ', $offending)),
        );
    }

    public static function assertExternalProjectLinkPresent(string $html, string $expectedHref, string $context = ''): void
    {
        $doc = self::parse($html);
        $found = false;
        foreach ($doc->querySelectorAll('a[href]') as $anchor) {
            if ($anchor->getAttribute('href') === $expectedHref && $anchor->getAttribute('target') === '_blank') {
                $found = true;
                break;
            }
        }

        Assert::assertTrue(
            $found,
            trim($context." Expected external project link [{$expectedHref}] with target=\"_blank\" in rendered HTML."),
        );
    }

    public static function assertSingleAnchorPerTileCard(string $html, int $expectedTileCount, string $context = ''): void
    {
        $doc = self::parse($html);
        $grid = $doc->querySelector('.fi-ta-content-grid');
        Assert::assertNotNull(
            $grid,
            trim($context.' Expected Filament tile view container (.fi-ta-content-grid).'),
        );

        $recordContents = $grid->querySelectorAll('.fi-ta-record-content');
        Assert::assertSame(
            $expectedTileCount,
            $recordContents->length,
            trim($context." Expected exactly {$expectedTileCount} .fi-ta-record-content node(s) (1 per tile card; >1 per card indicates HTML5 Adoption Agency anchor fragmentation from nested <a>). Found {$recordContents->length}."),
        );

        foreach ($recordContents as $index => $node) {
            Assert::assertInstanceOf(Element::class, $node);
            Assert::assertSame(
                'a',
                strtolower($node->tagName),
                trim($context." Tile card #{$index} .fi-ta-record-content must be an <a> element (recordUrl link), found <{$node->tagName}>."),
            );
        }

        $records = $grid->querySelectorAll('.fi-ta-record');
        if ($records->length > 0) {
            Assert::assertSame(
                $expectedTileCount,
                $records->length,
                trim($context." Expected exactly {$expectedTileCount} .fi-ta-record card(s), found {$records->length}."),
            );

            foreach ($records as $cardIndex => $record) {
                Assert::assertInstanceOf(Element::class, $record);

                $cardAnchors = $record->querySelectorAll('a.fi-ta-record-content');
                Assert::assertSame(
                    1,
                    $cardAnchors->length,
                    trim($context." Tile card #{$cardIndex} (.fi-ta-record) must contain strictly 1 <a class=\"fi-ta-record-content\">, found {$cardAnchors->length}."),
                );

                $nonActionAnchors = 0;
                foreach ($record->querySelectorAll('a') as $anchor) {
                    if ($anchor instanceof Element && $anchor->closest('.fi-ta-actions') === null) {
                        $nonActionAnchors++;
                    }
                }
                Assert::assertSame(
                    1,
                    $nonActionAnchors,
                    trim($context." Tile card #{$cardIndex} (.fi-ta-record) must contain strictly 1 <a> outside .fi-ta-actions (nested or extra <a> detected), found {$nonActionAnchors}."),
                );
            }

            return;
        }

        // Fragment HTML without .fi-ta-record wrappers: total <a> outside .fi-ta-actions must equal $expectedTileCount.
        $nonActionAnchors = 0;
        foreach ($grid->querySelectorAll('a') as $anchor) {
            if ($anchor instanceof Element && $anchor->closest('.fi-ta-actions') === null) {
                $nonActionAnchors++;
            }
        }
        Assert::assertSame(
            $expectedTileCount,
            $nonActionAnchors,
            trim($context." Expected strictly {$expectedTileCount} <a> node(s) inside .fi-ta-content-grid outside .fi-ta-actions (nested or extra <a> detected), found {$nonActionAnchors}."),
        );
    }

    /**
     * Verifies Filament-native tile metadata structure:
     * Outer Stack -> contains nested Stack for bottom metadata where author is on
     * its own row followed by a 2-item Split([downloads, date_modified (alignEnd)])
     * rather than cramming author + downloads + date into a single 3-item horizontal Split.
     */
    public static function assertPureFilamentTileMetadataLayout(string $html, int $expectedTileCount, string $context = ''): void
    {
        $doc = self::parse($html);
        $grid = $doc->querySelector('.fi-ta-content-grid');
        Assert::assertNotNull(
            $grid,
            trim($context.' Expected Filament tile view container (.fi-ta-content-grid).'),
        );

        $cards = $grid->querySelectorAll('.fi-ta-record-content');
        Assert::assertSame(
            $expectedTileCount,
            $cards->length,
            trim($context." Expected {$expectedTileCount} tile card(s) (.fi-ta-record-content), found {$cards->length}."),
        );

        foreach ($cards as $cardIndex => $card) {
            if (!$card instanceof Element) {
                continue;
            }

            $outerStack = $card->querySelector('.fi-ta-stack');
            Assert::assertNotNull(
                $outerStack,
                trim($context." Tile card #{$cardIndex} must render inside a Filament .fi-ta-stack."),
            );

            // Find nested .fi-ta-stack (metadata stack containing author + 2-item split)
            $nestedStacks = $outerStack->querySelectorAll('.fi-ta-stack');
            Assert::assertGreaterThanOrEqual(
                1,
                $nestedStacks->length,
                trim($context." Tile card #{$cardIndex} must place bottom metadata in a nested Filament Stack([author, Split([downloads, date_modified->alignEnd()])]), not a single 3-item Split."),
            );

            $metadataStack = $nestedStacks->item($nestedStacks->length - 1);
            Assert::assertInstanceOf(Element::class, $metadataStack);

            $metadataChildren = self::directElementChildren($metadataStack);
            Assert::assertGreaterThanOrEqual(
                2,
                count($metadataChildren),
                trim($context." Tile card #{$cardIndex} metadata .fi-ta-stack must contain at least 2 direct rows ([author, Split([downloads, date_modified])]), found ".count($metadataChildren).'.'),
            );

            $bottomSplits = $metadataStack->querySelectorAll('.fi-ta-split');
            Assert::assertSame(
                1,
                $bottomSplits->length,
                trim($context." Tile card #{$cardIndex} metadata Stack must contain exactly 1 nested .fi-ta-split for [downloads, date_modified]."),
            );

            $bottomSplit = $bottomSplits->item(0);
            Assert::assertInstanceOf(Element::class, $bottomSplit);

            $nonSplitRows = 0;
            foreach ($metadataChildren as $child) {
                if ($child !== $bottomSplit && $child->querySelector('.fi-ta-split') === null) {
                    $nonSplitRows++;
                }
            }
            Assert::assertGreaterThanOrEqual(
                1,
                $nonSplitRows,
                trim($context." Tile card #{$cardIndex} metadata .fi-ta-stack must include a dedicated author row separate from .fi-ta-split."),
            );

            $splitChildren = self::directElementChildren($bottomSplit);
            Assert::assertSame(
                2,
                count($splitChildren),
                trim($context." Tile card #{$cardIndex} bottom .fi-ta-split must hold strictly 2 items ([downloads, date_modified]), found ".count($splitChildren).'.'),
            );

            $alignedEnd = $bottomSplit->querySelector('.fi-align-end');
            Assert::assertNotNull(
                $alignedEnd,
                trim($context." Tile card #{$cardIndex} bottom .fi-ta-split must right-align date_modified with Filament's alignEnd() (.fi-align-end)."),
            );
        }
    }

    /**
     * @return list<Element>
     */
    private static function directElementChildren(Element $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
