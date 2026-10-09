<?php

namespace Kazaminosuke\ModManager\Exceptions;

use Exception;
use InvalidArgumentException;

/**
 * A version exists, but the source will not attach an automatic download.
 *
 * Premium and externally hosted files are rejected on purpose. Callers must
 * not report that as a missing file URL.
 */
final class DownloadUnavailableException extends Exception
{
    public const PREMIUM = 'premium';

    public const EXTERNAL = 'external';

    public const INVALID = 'invalid';

    public const DISTRIBUTION = 'distribution';

    public function __construct(private readonly string $reason)
    {
        if (!in_array($reason, [self::PREMIUM, self::EXTERNAL, self::INVALID, self::DISTRIBUTION], true)) {
            throw new InvalidArgumentException('Unknown download block ['.$reason.'].');
        }

        parent::__construct(match ($reason) {
            self::PREMIUM => 'This resource is premium and cannot be downloaded automatically.',
            self::EXTERNAL => 'This resource is hosted externally and cannot be downloaded automatically.',
            self::INVALID => 'This resource does not have a usable download address.',
            self::DISTRIBUTION => 'Third-party download is disabled for this file.',
        });
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
