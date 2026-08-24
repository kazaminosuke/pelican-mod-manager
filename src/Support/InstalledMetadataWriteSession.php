<?php

namespace Kazaminosuke\ModManager\Support;

use Closure;

/**
 * Operation-scoped authoritative metadata snapshot.
 *
 * Bulk updates still persist after every successfully activated project so a
 * later failure cannot discard earlier progress. The in-memory document only
 * advances after that PUT succeeds, which lets subsequent archive collision
 * checks reuse the same authoritative snapshot without another Wings GET.
 */
final class InstalledMetadataWriteSession
{
    /**
     * @param Closure(InstalledMetadataDocument): bool $persist
     */
    public function __construct(
        private InstalledMetadataDocument $document,
        private readonly Closure $persist,
    ) {}

    public function document(): InstalledMetadataDocument
    {
        return $this->document;
    }

    /** @param array<string, mixed> $entry */
    public function upsert(array $entry): bool
    {
        $next = $this->document->withUpsertedInstalledMod($entry);

        if (!(($this->persist)($next))) {
            return false;
        }

        $this->document = $next;

        return true;
    }
}
