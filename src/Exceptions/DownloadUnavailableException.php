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

    public const UNSUPPORTED_FILE = 'unsupported_file';

    public const UPDATE_REQUESTED = 'update_requested';

    public const RATE_LIMITED = 'rate_limited';

    public const VERSION_UNAVAILABLE = 'version_unavailable';

    public const FETCH_FAILED = 'fetch_failed';

    public const INVALID = 'invalid';

    public const DISTRIBUTION = 'distribution';

    public static function isSupportedReason(mixed $reason): bool
    {
        return is_string($reason) && in_array($reason, [
            self::PREMIUM,
            self::EXTERNAL,
            self::UNSUPPORTED_FILE,
            self::UPDATE_REQUESTED,
            self::RATE_LIMITED,
            self::VERSION_UNAVAILABLE,
            self::FETCH_FAILED,
            self::INVALID,
            self::DISTRIBUTION,
        ], true);
    }

    public function __construct(private readonly string $reason)
    {
        parent::__construct(match ($reason) {
            self::PREMIUM => 'This resource is premium and cannot be downloaded automatically.',
            self::EXTERNAL => 'This resource is hosted externally and cannot be downloaded automatically.',
            self::UNSUPPORTED_FILE => 'This resource distributes a non-JAR file and cannot be installed automatically.',
            self::UPDATE_REQUESTED => 'This JAR is not cached on the SpiGet CDN yet. An update request has been sent to SpiGet.',
            self::RATE_LIMITED => 'The upstream API rate limit was reached while resolving the download.',
            self::VERSION_UNAVAILABLE => 'The selected version could not be downloaded directly without substituting a different version.',
            self::FETCH_FAILED => 'The download could not be verified or retrieved from the upstream source.',
            self::INVALID => 'This resource does not have a usable download address.',
            self::DISTRIBUTION => 'Third-party download is disabled for this file.',
            default => throw new InvalidArgumentException('Unknown download block ['.$reason.'].'),
        });
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
