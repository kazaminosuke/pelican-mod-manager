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

    public function __construct(private readonly string $reason)
    {
        if ($reason !== self::PREMIUM && $reason !== self::EXTERNAL) {
            throw new InvalidArgumentException('Unknown download block ['.$reason.'].');
        }

        parent::__construct($reason === self::PREMIUM
            ? 'This resource is premium and cannot be downloaded automatically.'
            : 'This resource is hosted externally and cannot be downloaded automatically.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
