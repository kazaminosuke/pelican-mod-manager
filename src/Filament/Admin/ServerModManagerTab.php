<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;

/**
 * The root-admin-only tab added to Admin > Server > Edit > Mod Manager.
 *
 * The fields remain outside the Server model state. Filament's standard
 * EditRecord save flow calls the tab's relationship callback, which saves the
 * separate settings row in the same transaction without adding fake columns
 * to the Server update payload.
 */
final class ServerModManagerTab
{
    /** @var array<string, string> */
    private const TYPE_ENABLED_FIELDS = [
        'mod' => 'mod_manager_mod_enabled',
        'plugin' => 'mod_manager_plugin_enabled',
        'datapack' => 'mod_manager_datapack_enabled',
    ];

    /** @var array<string, string> */
    private const NAVIGATION_SORT_FIELDS = [
        'mod' => 'mod_manager_mod_navigation_sort',
        'plugin' => 'mod_manager_plugin_navigation_sort',
        'datapack' => 'mod_manager_datapack_navigation_sort',
    ];

    /** @var array<string, string> */
    private const PERMISSION_FIELDS = [
        'allow_user_egg_profile_edit' => 'mod_manager_allow_user_egg_profile_edit',
        'allow_user_project_install' => 'mod_manager_allow_user_project_install',
        'allow_user_project_update' => 'mod_manager_allow_user_project_update',
        'allow_user_project_delete' => 'mod_manager_allow_user_project_delete',
    ];

    public static function make(): Tab
    {
        return Tab::make('mod_manager')
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.tab'))
            ->icon('tabler-packages')
            ->visible(fn (): bool => self::isRootAdmin())
            // EditRecord calls this during its normal form getState() flow.
            // Keeping it on the tab means hidden tabs are saved too, while
            // every value stays out of the Server model's update payload.
            ->saveRelationshipsUsing(function (Tab $component): void {
                self::saveFromLivewire($component);
            })
            ->saveRelationshipsWhenHidden()
            ->schema([
                Section::make(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.access'))
                    ->description(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.access_helper'))
                    // EditServer renders tabs inside a six-column grid; without
                    // an explicit span every top-level section is squeezed into
                    // a single ~106px cell. Full-width sections restore normal
                    // Filament form proportions, and each section's own
                    // responsive columns() then decides the field layout.
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 2, '2xl' => 4])
                    ->schema([
                        self::typeToggle(ProjectType::Mod),
                        self::typeToggle(ProjectType::Plugin),
                        self::typeToggle(ProjectType::Datapack),
                    ]),
                Section::make(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.navigation'))
                    ->description(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.navigation_helper'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        self::navigationSortInput(ProjectType::Mod),
                        self::navigationSortInput(ProjectType::Plugin),
                        self::navigationSortInput(ProjectType::Datapack),
                    ]),
                Section::make(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.permissions'))
                    ->description(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.permissions_helper'))
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_egg_profile_edit'])
                            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.allow_user_egg_profile_edit'))
                            ->options(fn (): array => self::permissionOptions('allow_user_egg_profile_edit'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_egg_profile_edit'))
                            ->inline()
                            ->dehydrated(false),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_project_install'])
                            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_install'))
                            ->options(fn (): array => self::permissionOptions('allow_user_project_install'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_project_install'))
                            ->inline()
                            ->dehydrated(false),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_project_update'])
                            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_update'))
                            ->options(fn (): array => self::permissionOptions('allow_user_project_update'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_project_update'))
                            ->inline()
                            ->dehydrated(false),
                        ToggleButtons::make(self::PERMISSION_FIELDS['allow_user_project_delete'])
                            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_delete'))
                            ->options(fn (): array => self::permissionOptions('allow_user_project_delete'))
                            ->formatStateUsing(fn (Server $record): string => self::overrideState($record, 'allow_user_project_delete'))
                            ->inline()
                            ->dehydrated(false),
                    ]),
                Section::make(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.egg_profile'))
                    ->description(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.egg_profile_helper'))
                    ->columnSpanFull()
                    ->schema([
                        Actions::make([
                            Action::make('edit_egg_profile')
                                ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.edit_egg_profile'))
                                ->icon('tabler-egg')
                                ->schema(ModManagerPlugin::eggProfileFormSchema(includeEggSelect: false))
                                ->fillForm(function (Server $record): array {
                                    $record->loadMissing('egg');

                                    return ModManagerPlugin::eggProfileDefaults($record->egg);
                                })
                                ->action(function (Server $record, array $data): void {
                                    self::authorizeRootAdmin();
                                    $record->loadMissing('egg');

                                    if ($record->egg === null) {
                                        return;
                                    }

                                    $data['egg_id'] = $record->egg->getKey();
                                    ModManagerPlugin::saveEggProfile($data);
                                    EggProfileResolver::clear();
                                }),
                        ]),
                    ]),
            ]);
    }

    /**
     * Save the non-Server state through EditRecord's standard Save action.
     *
     * @param array<string, mixed> $data
     */
    public static function save(Server $server, array $data): void
    {
        self::authorizeRootAdmin();

        $repository = app(ServerModManagerSettingRepository::class);
        $current = $repository->forServer($server);
        $attributes = [];

        foreach (self::TYPE_ENABLED_FIELDS as $type => $field) {
            $attributes[$type.'_enabled'] = array_key_exists($field, $data)
                ? self::decodeBoolean($data[$field])
                : ($current?->{$type.'_enabled'} ?? true);
        }

        foreach (self::NAVIGATION_SORT_FIELDS as $type => $field) {
            $attributes[$type.'_navigation_sort'] = array_key_exists($field, $data)
                ? self::decodeNavigationSort($data[$field])
                : $current?->{$type.'_navigation_sort'};
        }

        foreach (self::PERMISSION_FIELDS as $globalKey => $field) {
            $attributes[$globalKey] = array_key_exists($field, $data)
                ? self::decodeOverride($data[$field])
                : $current?->{$globalKey};
        }

        $repository->save($server, $attributes);
    }

    /** @return array<string, string> */
    public static function permissionOptions(string $globalKey): array
    {
        $global = app(ServerModManagerSettings::class)->global($globalKey);
        $globalState = $global
            ? trans('pelican-minecraft-modrinth::strings.server_mod_manager.on')
            : trans('pelican-minecraft-modrinth::strings.server_mod_manager.off');

        return [
            'inherit' => trans('pelican-minecraft-modrinth::strings.server_mod_manager.inherit', ['state' => $globalState]),
            'allow' => trans('pelican-minecraft-modrinth::strings.server_mod_manager.allow'),
            'deny' => trans('pelican-minecraft-modrinth::strings.server_mod_manager.deny'),
        ];
    }

    private static function typeToggle(ProjectType $type): Toggle
    {
        $field = self::TYPE_ENABLED_FIELDS[$type->value];

        return Toggle::make($field)
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.type_enabled', ['type' => $type->getLabel()]))
            ->helperText(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.type_enabled_helper'))
            ->formatStateUsing(fn (Server $record): bool => app(ServerModManagerSettings::class)->configuredTypeEnabled($record, $type))
            ->visible(fn (Server $record): bool => self::isTypeVisible($record, $type))
            ->dehydrated(false);
    }

    private static function navigationSortInput(ProjectType $type): TextInput
    {
        $field = self::NAVIGATION_SORT_FIELDS[$type->value];

        return TextInput::make($field)
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.navigation_sort', ['type' => $type->getLabel()]))
            ->helperText(fn (): string => trans('pelican-minecraft-modrinth::strings.server_mod_manager.navigation_sort_helper'))
            ->placeholder(fn (Server $record): string => (string) app(ServerModManagerSettings::class)->navigationSort($record, $type))
            ->formatStateUsing(fn (Server $record): ?int => app(ServerModManagerSettings::class)->navigationSortOverride($record, $type))
            ->numeric()
            ->nullable()
            ->visible(fn (Server $record): bool => self::isTypeVisible($record, $type))
            ->dehydrated(false);
    }

    private static function saveFromLivewire(Tab $component): void
    {
        $livewire = $component->getLivewire();

        if (!method_exists($livewire, 'getRecord')) {
            return;
        }

        $record = $livewire->getRecord();

        if ($record instanceof Server) {
            self::save($record, (array) data_get($livewire, 'data', []));
        }
    }

    private static function isTypeVisible(Server $server, ProjectType $type): bool
    {
        if ($type === ProjectType::Datapack) {
            return ProjectType::supportsDatapacks($server);
        }

        $detected = ProjectType::fromServer($server);

        return $detected === null || $detected === $type;
    }

    private static function overrideState(Server $server, string $globalKey): string
    {
        return match (app(ServerModManagerSettings::class)->override($server, $globalKey)) {
            true => 'allow',
            false => 'deny',
            default => 'inherit',
        };
    }

    private static function decodeBoolean(mixed $value): bool
    {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    private static function decodeNavigationSort(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function decodeOverride(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'allow' => true,
            false, 0, '0', 'deny' => false,
            default => null,
        };
    }

    private static function isRootAdmin(): bool
    {
        return (bool) user()?->isRootAdmin();
    }

    private static function authorizeRootAdmin(): void
    {
        abort_unless(self::isRootAdmin(), 403);
    }
}
