<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Models\Egg;
use App\Models\Server;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Throwable;

/**
 * Optional first step on the admin create-server wizard.
 */
final class ModpackCreationStep
{
    /** @var array<string, CompatibilityResult|null> */
    private static array $results = [];

    public static function step(): Step
    {
        return Step::make('modpack')
            ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.create_step'))
            ->schema([
                Toggle::make('mm_modpack')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.use_modpack'))
                    ->helperText(fn (): string => trans('pelican-mod-manager::strings.modpacks.use_modpack_helper'))
                    ->live()
                    ->default(false),
                Select::make('mm_project')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.pack'))
                    ->searchable()
                    ->live()
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->getSearchResultsUsing(function (string $search): array {
                        return app(ModpackCatalog::class)->searchOptions(new Server(), $search);
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        $options = app(ModpackCatalog::class)->searchOptions(new Server(), (string) $value);

                        return $options[$value] ?? (is_string($value) ? $value : null);
                    })
                    ->afterStateUpdated(function (Set $set): void {
                        $set('mm_version', null);
                        $set('mm_egg', null);
                    }),
                Select::make('mm_version')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.version'))
                    ->live()
                    ->required(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->options(fn (Get $get): array => app(ModpackCatalog::class)->versionOptions((string) $get('mm_project')))
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        $set('mm_egg', null);
                        self::apply($set, $get, null);
                    }),
                Select::make('mm_egg')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.choose_egg'))
                    ->helperText(fn (): string => trans('pelican-mod-manager::strings.modpacks.choose_egg_helper'))
                    ->live()
                    ->required(function (Get $get): bool {
                        $result = self::result($get, null);

                        return $result !== null && $result->disposition === CompatibilityDisposition::Ambiguous;
                    })
                    ->visible(function (Get $get): bool {
                        $result = self::result($get, null);

                        return $result !== null && $result->disposition === CompatibilityDisposition::Ambiguous;
                    })
                    ->options(function (Get $get): array {
                        $result = self::result($get, null);
                        $options = [];
                        foreach ($result->alternatives ?? [] as $egg) {
                            $options[(string) $egg->id] = $egg->name.' · '.($egg->profileId ?? $egg->loader ?? 'custom').' · '.$egg->confidence;
                        }

                        return $options;
                    })
                    ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                        self::apply($set, $get, $state);
                    }),
                TextEntry::make('mm_summary')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.plan'))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->state(function (Get $get): string {
                        $result = self::result($get, $get('mm_egg'));
                        if ($result === null) {
                            return trans('pelican-mod-manager::strings.modpacks.plan_unavailable');
                        }

                        $lines = array_filter([
                            $result->provider,
                            $result->minecraftVersion !== null ? 'Minecraft '.$result->minecraftVersion : null,
                            $result->loader,
                            $result->selected?->name,
                            $result->reasonDetail,
                        ]);

                        return implode("\n", $lines);
                    }),
                Hidden::make('mm_guard')
                    ->dehydrated(false)
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if (!(bool) $get('mm_modpack') || !filled($get('mm_project'))) {
                                return;
                            }

                            $result = self::result($get, $get('mm_egg'));
                            if ($result === null || $result->disposition === CompatibilityDisposition::Unsupported) {
                                $fail($result?->reasonDetail ?: (string) trans('pelican-mod-manager::strings.modpacks.plan_unavailable'));
                            }
                        },
                    ]),
            ]);
    }

    private static function apply(Set $set, Get $get, mixed $explicitEggId): void
    {
        $result = self::result($get, $explicitEggId);
        if ($result === null || $result->selected === null || $result->disposition === CompatibilityDisposition::Ambiguous) {
            return;
        }

        if (!in_array($result->disposition, [CompatibilityDisposition::Compatible, CompatibilityDisposition::EggChange], true)) {
            return;
        }

        $egg = Egg::query()->with('variables')->find($result->selected->id);
        if ($egg === null) {
            return;
        }

        $set('egg_id', $egg->getKey());
        $startup = collect($egg->startup_commands ?? [])->first();
        if (is_string($startup) && $startup !== '') {
            $set('startup', $startup);
            $set('select_startup', $startup);
        }

        $images = is_array($egg->docker_images) ? $egg->docker_images : [];
        $image = JavaRuntimeImage::fromEggImages($images, $result->minecraftVersion);
        if ($image !== null) {
            $set('image', $image);
            $set('select_image', $image);
        }

        $set('skip_scripts', false);
        $rows = [];
        $environment = [];
        foreach ($egg->variables->sortBy('sort')->values() as $variable) {
            $name = (string) $variable->env_variable;
            $value = $result->variables[$name] ?? (string) $variable->default_value;
            $row = $variable->toArray();
            $row['variable_value'] = $value;
            $row['variable_id'] = $variable->getKey();
            $rows[] = $row;
            $environment[$name] = $value;
        }
        $set('server_variables', $rows);
        $set('environment', $environment);
    }

    private static function result(Get $get, mixed $explicitEggId): ?CompatibilityResult
    {
        $project = (string) $get('mm_project');
        $version = $get('mm_version');
        if ($project === '' || $version === null || $version === '') {
            return null;
        }

        $explicit = is_scalar($explicitEggId) && (string) $explicitEggId !== '' ? (string) $explicitEggId : null;
        $key = $project.'|'.$version.'|'.($explicit ?? '');
        if (array_key_exists($key, self::$results)) {
            return self::$results[$key];
        }

        try {
            $parts = explode(':', $project, 2);
            if (count($parts) !== 2) {
                return self::$results[$key] = null;
            }

            $descriptor = app(ModpackCatalog::class)->describe(new Server(), $parts[0], $parts[1], (string) $version);
            if ($descriptor === null) {
                return self::$results[$key] = null;
            }

            $keyValue = config('pelican-mod-manager.curseforge_api_key');

            return self::$results[$key] = app(ModpackProvisioningPlanner::class)->select(
                $descriptor,
                app(InstalledEggCatalog::class)->all(),
                EggCompatibilityEngine::CONTEXT_CREATE,
                null,
                [],
                $explicit,
                $explicit === null,
                is_string($keyValue) ? $keyValue : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return self::$results[$key] = null;
        }
    }
}
