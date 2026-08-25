<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\NavigationRowShifter;
use PHPUnit\Framework\TestCase;

final class NavigationRowShifterTest extends TestCase
{
    public function test_no_claims_leave_core_rows_unchanged(): void
    {
        self::assertSame([1, 10, 11], NavigationRowShifter::finalRowsFor([], [1, 10, 11]));
    }

    public function test_non_positive_claims_are_ignored(): void
    {
        self::assertSame([1, 10, 11], NavigationRowShifter::finalRowsFor([0, -3], [1, 10, 11]));
    }

    public function test_free_claim_below_core_values_shifts_nothing(): void
    {
        self::assertSame([5, 10], NavigationRowShifter::finalRowsFor([3], [5, 10]));
    }

    public function test_mod_ten_and_datapack_eleven_cascade_all_core_items(): void
    {
        // Mod claims 10 and Datapack claims 11. Startup moves to 12, then
        // the two core items originally sharing 11 receive distinct rows.
        self::assertSame(
            [9, 12, 13, 14],
            NavigationRowShifter::finalRowsFor([10, 11], [9, 10, 11, 11]),
        );
    }

    public function test_duplicate_manager_claims_reserve_each_claim_row(): void
    {
        // Mod and Plugin both claim 10. The core rows begin after both claims
        // even though the manager pages retain the same configured sort.
        self::assertSame(
            [12, 13, 14],
            NavigationRowShifter::finalRowsFor([10, 10], [10, 11, 12]),
        );
    }

    public function test_three_contiguous_claims_push_contiguous_core_rows(): void
    {
        self::assertSame(
            [13, 14, 15, 16, 17],
            NavigationRowShifter::finalRowsFor([10, 11, 12], [10, 11, 12, 13, 14]),
        );
    }

    public function test_a_moved_item_cannot_reuse_a_row_taken_by_a_later_claim(): void
    {
        self::assertSame(
            [11, 13, 14, 15],
            NavigationRowShifter::finalRowsFor([10, 12], [10, 11, 12, 13]),
        );
    }

    public function test_core_items_are_processed_by_sort_and_keep_stable_input_order(): void
    {
        self::assertSame(
            [12, 11, 13],
            NavigationRowShifter::finalRowsFor([10], [11, 10, 11]),
        );
    }

    public function test_duplicate_core_sorts_receive_distinct_final_rows(): void
    {
        self::assertSame(
            [11, 12, 13],
            NavigationRowShifter::finalRowsFor([10], [10, 11, 11]),
        );
    }

    public function test_rows_below_the_first_claim_are_not_rewritten(): void
    {
        self::assertSame(
            [1, 2, 4, 5, 6, 7, 8, 9, 11, 12],
            NavigationRowShifter::finalRowsFor([10], [1, 2, 4, 5, 6, 7, 8, 9, 10, 11]),
        );
    }
}
