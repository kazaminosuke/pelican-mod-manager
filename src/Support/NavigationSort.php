<?php

namespace Kazaminosuke\ModManager\Support;

/** Shared validation boundary for database-backed navigation positions. */
final class NavigationSort
{
    public const MIN_VALUE = 1;

    public const MAX_VALUE = 1_000;

    public static function nullable(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[+-]?\d+$/D', trim($value)) === 1) {
            $validated = filter_var(trim($value), FILTER_VALIDATE_INT);

            if ($validated === false) {
                return null;
            }

            $integer = $validated;
        } else {
            return null;
        }

        return $integer >= self::MIN_VALUE && $integer <= self::MAX_VALUE
            ? $integer
            : null;
    }
}
