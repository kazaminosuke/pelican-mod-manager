<?php

namespace Kazaminosuke\ModManager\Contracts;

/**
 * Optional installed-file identification from archive metadata such as
 * `plugin.yml`, used when the source has no complete hash reverse lookup.
 *
 * Implementations must return a normalized version payload only for a
 * high-confidence unique match. Ambiguous candidates must return null.
 */
interface ArchiveMetadataIdentificationInterface
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $hashes
     * @return array<string, mixed>|null
     */
    public function identifyFromArchiveMetadata(array $metadata, array $hashes): ?array;
}
