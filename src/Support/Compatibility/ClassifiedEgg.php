<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

/**
 * One installed egg after profile matching. Confidence is independent of the
 * display name: a renamed egg with the official uuid stays high, and a name
 * that disagrees with the variable signature is not selectable.
 */
final class ClassifiedEgg
{
    /**
     * @param  'high'|'medium'|'none'  $confidence
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly ?string $profileId,
        public readonly ?string $loader,
        public readonly ?string $projectType,
        public readonly bool $isProxy,
        public readonly string $matchSource,
        public readonly string $confidence,
        public readonly bool $contradicts,
    ) {}

    public function isSelectable(): bool
    {
        return !$this->contradicts && $this->confidence !== 'none' && !$this->isProxy;
    }
}
