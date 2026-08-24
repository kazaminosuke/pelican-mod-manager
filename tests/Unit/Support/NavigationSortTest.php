<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\NavigationSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NavigationSortTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_accepts_only_bounded_integers(mixed $value, int $expected): void
    {
        self::assertSame($expected, NavigationSort::nullable($value));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function validValues(): iterable
    {
        yield 'minimum integer' => [1, 1];
        yield 'maximum integer' => [1_000, 1_000];
        yield 'integer string' => ['42', 42];
        yield 'trimmed integer string' => [' 12 ', 12];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_values_that_could_overflow_or_escape_the_domain(mixed $value): void
    {
        self::assertNull(NavigationSort::nullable($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'null inherits' => [null];
        yield 'empty inherits' => [''];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [1_001];
        yield 'overflowing integer string' => ['999999999999999999999999'];
        yield 'decimal' => ['1.5'];
        yield 'boolean' => [true];
        yield 'non numeric' => ['first'];
    }
}
