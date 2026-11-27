<?php

namespace Kazaminosuke\ModManager\Support;

use Closure;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Component as LayoutComponent;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Shared Filament-native tile layout (Stack / Split + contentGrid) used across
 * Mod, Plugin, Datapack, Resource Pack, and Modpack catalogs.
 *
 * Intentionally omits Minecraft version and loader metadata from tile cards so
 * tiles stay uncluttered and consistent; table view and filters retain them.
 */
final class CatalogTileColumns
{
    public const VIEW_LIST = 'list';

    public const VIEW_PANEL = 'panel';

    public const SESSION_KEY = 'pelican-mod-manager.catalog-view';

    public const COOKIE_KEY = 'mmr_catalog_view';

    public const COOKIE_MINUTES = 525600;

    public const VIEW_CHANGED_EVENT = 'mmr-catalog-view-changed';

    /**
     * @return array<string, int>
     */
    public static function contentGrid(): array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    public static function resolveInitialView(): string
    {
        return self::storedView() ?? self::VIEW_LIST;
    }

    public static function storedView(): ?string
    {
        $stored = session()->get(self::SESSION_KEY);
        if (!is_string($stored)) {
            $stored = request()->cookie(self::COOKIE_KEY);
        }

        return is_string($stored) ? self::normalizeView($stored) : null;
    }

    public static function normalizeView(mixed $view): string
    {
        return $view === self::VIEW_PANEL ? self::VIEW_PANEL : self::VIEW_LIST;
    }

    public static function storeView(string $view): string
    {
        $normalized = self::normalizeView($view);

        session()->put(self::SESSION_KEY, $normalized);
        Cookie::queue(self::COOKIE_KEY, $normalized, self::COOKIE_MINUTES);

        return $normalized;
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, string>  $iconImgAttributes
     * @param  Closure(mixed): string  $formatDate
     * @param  Closure(mixed): string  $dateTooltip
     * @param  Closure(array<string, mixed>): ?string|null  $descriptionFormatter
     * @return list<LayoutComponent>
     */
    public static function columns(
        Closure $iconImgAttributes,
        Closure $formatDate,
        Closure $dateTooltip,
        ?Closure $descriptionFormatter = null,
    ): array {
        return [
            Stack::make([
                Split::make([
                    ImageColumn::make('icon_url')
                        ->label('')
                        ->defaultImageUrl(ProjectIconUrl::placeholderDataUri())
                        ->imageSize(40)
                        ->square()
                        ->grow(false)
                        ->extraImgAttributes($iconImgAttributes),
                    TextColumn::make('title')
                        ->label(trans('pelican-mod-manager::strings.table.columns.title'))
                        ->searchable()
                        ->weight(FontWeight::SemiBold)
                        ->lineClamp(1),
                ]),
                TextColumn::make('description')
                    ->label('')
                    ->wrap()
                    ->lineClamp(2)
                    ->color('gray')
                    ->size(TextSize::Small)
                    ->state(static function (array $record) use ($descriptionFormatter): ?string {
                        if ($descriptionFormatter !== null) {
                            return $descriptionFormatter($record);
                        }

                        $description = $record['description'] ?? null;
                        if (!is_string($description) || trim($description) === '') {
                            return null;
                        }

                        return Str::limit($description, CatalogFields::DESCRIPTION_MAX_LENGTH, '...');
                    }),
                Split::make([
                    TextColumn::make('author')
                        ->label(trans('pelican-mod-manager::strings.table.columns.author'))
                        ->icon('tabler-user')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->lineClamp(1)
                        ->toggleable(),
                    Split::make([
                        TextColumn::make('downloads')
                            ->label(trans('pelican-mod-manager::strings.table.columns.downloads'))
                            ->numeric()
                            ->icon('tabler-download')
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->toggleable(),
                        TextColumn::make('date_modified')
                            ->label(trans('pelican-mod-manager::strings.table.columns.date_modified'))
                            ->icon('tabler-calendar')
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->state(static function (array $record) use ($formatDate): ?string {
                                $formatted = $formatDate($record['date_modified'] ?? null);

                                return trim($formatted) !== '' ? $formatted : null;
                            })
                            ->tooltip(static function (array $record) use ($dateTooltip): ?string {
                                $tooltip = $dateTooltip($record['date_modified'] ?? null);

                                return trim($tooltip) !== '' ? $tooltip : null;
                            })
                            ->toggleable(),
                    ])->extraAttributes(['class' => 'mmr-tile-stat-values']),
                ])->extraAttributes(['class' => 'mmr-tile-stats']),
            ])->space(3),
        ];
    }
}
