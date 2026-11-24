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
        $stored = session()->get(self::SESSION_KEY);
        if (!is_string($stored)) {
            $stored = request()->cookie(self::COOKIE_KEY);
        }

        return $stored === self::VIEW_PANEL ? self::VIEW_PANEL : self::VIEW_LIST;
    }

    public static function normalizeView(?string $view): string
    {
        return $view === self::VIEW_PANEL ? self::VIEW_PANEL : self::VIEW_LIST;
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, string>  $iconImgAttributes
     * @param  Closure(mixed): string  $formatDate
     * @param  Closure(mixed): string  $dateTooltip
     * @param  Closure(array<string, mixed>, mixed): ?string  $authorUrl
     * @param  Closure(array<string, mixed>): ?string|null  $descriptionFormatter
     * @return list<LayoutComponent>
     */
    public static function columns(
        Closure $iconImgAttributes,
        Closure $formatDate,
        Closure $dateTooltip,
        Closure $authorUrl,
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
                    Stack::make([
                        TextColumn::make('title')
                            ->label('')
                            ->searchable()
                            ->weight(FontWeight::SemiBold)
                            ->lineClamp(1),
                        TextColumn::make('author')
                            ->label('')
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->lineClamp(1)
                            ->url($authorUrl, true),
                    ])->space(1),
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
                    TextColumn::make('downloads')
                        ->label('')
                        ->numeric()
                        ->icon('tabler-download')
                        ->color('gray')
                        ->size(TextSize::Small),
                    TextColumn::make('date_modified')
                        ->label('')
                        ->icon('tabler-calendar')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->alignEnd()
                        ->formatStateUsing($formatDate)
                        ->tooltip($dateTooltip),
                ]),
            ])->space(3),
        ];
    }
}
