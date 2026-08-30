<?php

namespace Kazaminosuke\ModManager\Filament\Filters;

use Closure;
use Filament\Tables\Filters\SelectFilter;

/**
 * Select filter variant that can ignore automatically detected values when
 * Filament calculates the filters trigger badge.
 *
 * Catalog compatibility defaults are part of the server context rather than
 * user-selected catalog constraints. They remain in the filter state and
 * provider query, but do not make the filter badge look active until the user
 * changes that context.
 */
final class CatalogSelectFilter extends SelectFilter
{
    /**
     * @var array<int, string>|Closure
     */
    protected array|Closure $activeCountExcludedValues = [];

    /**
     * Values that should not count as an active filter by themselves.
     *
     * @param  array<int, string>|Closure  $values
     */
    public function activeCountExcludedValues(array|Closure $values): static
    {
        $this->activeCountExcludedValues = $values;

        return $this;
    }

    public function getActiveCount(): int
    {
        $state = $this->getState();
        $selected = $this->normaliseValues(
            $this->isMultiple() ? ($state['values'] ?? []) : ($state['value'] ?? null),
        );
        $excluded = $this->normaliseValues($this->evaluate($this->activeCountExcludedValues));

        sort($selected, SORT_STRING);
        sort($excluded, SORT_STRING);

        return $selected === $excluded ? 0 : 1;
    }

    /**
     * @return array<int, string>
     */
    private function normaliseValues(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        ), static fn (string $value): bool => $value !== '')));
    }
}
