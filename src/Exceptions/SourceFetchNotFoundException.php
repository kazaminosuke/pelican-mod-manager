<?php

namespace Kazaminosuke\ModManager\Exceptions;

use RuntimeException;

/**
 * Signals that the upstream source definitively answered a lookup with
 * "not found" (for example, a file hash that matches no known project).
 *
 * A definitive miss is a normal result, not an outage: SourceCache must not
 * record a retry-cooldown failure marker for it, and the miss itself is not
 * persisted, so a project published upstream later can still match on the
 * next scan.
 */
final class SourceFetchNotFoundException extends RuntimeException {}
