<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Computes the final rows for core navigation items after manager rows claim
 * their configured positions.
 *
 * Claims are parked first and therefore always win their requested rows.
 * Core items are then processed in stable sort order. If an item's original
 * row is claimed or has already been allocated to an earlier core item, it
 * keeps moving down until it reaches an unused row. The result is kept per
 * input item rather than keyed by the original sort value, because Pelican
 * legitimately has multiple core items with the same sort value.
 */
final class NavigationRowShifter
{
    /**
     * Return the final row for every core item in its original input order.
     *
     * Duplicate claims reserve consecutive rows. This keeps two manager pages
     * configured with the same value ahead of the core items beneath them,
     * while the manager pages themselves retain their configured sort value.
     *
     * @param  list<int>  $claims
     * @param  list<int>  $current
     * @return list<int>
     */
    public static function finalRowsFor(array $claims, array $current): array
    {
        $claims = array_values(array_filter(
            array_map(static fn (mixed $claim): int => (int) $claim, $claims),
            static fn (int $claim): bool => $claim > 0,
        ));
        $current = array_values(array_map(static fn (mixed $sort): int => (int) $sort, $current));

        if ($claims === [] || $current === []) {
            return $current;
        }

        sort($claims, SORT_NUMERIC);

        /** @var array<int, true> $occupied */
        $occupied = [];

        // Park every claim before moving any core item. A duplicate claim
        // reserves the next row as well, so core items remain below all
        // manager pages even when two manager pages share one configured sort.
        foreach ($claims as $claim) {
            $row = $claim;

            while (isset($occupied[$row])) {
                $row++;
            }

            $occupied[$row] = true;
        }

        $floor = $claims[0];
        $finalRows = $current;
        $entries = [];

        foreach ($current as $index => $value) {
            if ($value >= $floor) {
                $entries[] = ['index' => $index, 'value' => $value];
            }
        }

        // A panel may register items in a different order than their sort.
        // Sort only the entries being moved and use the original index as a
        // stable tie-breaker so relative order remains deterministic.
        usort($entries, static function (array $left, array $right): int {
            return ($left['value'] <=> $right['value'])
                ?: ($left['index'] <=> $right['index']);
        });

        foreach ($entries as $entry) {
            $row = $entry['value'];

            while (isset($occupied[$row])) {
                $row++;
            }

            $finalRows[$entry['index']] = $row;
            $occupied[$row] = true;
        }

        return array_values($finalRows);
    }
}
