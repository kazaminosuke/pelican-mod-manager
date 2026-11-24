<div x-cloak x-show="$wire.activeTab !== 'installed'">
    <x-filament::icon-button
        color="gray"
        icon="tabler-layout-grid"
        wire:click="toggleCatalogView"
        data-mmr-view-toggle
        x-show="$wire.catalogView !== 'panel'"
        :label="trans('pelican-mod-manager::strings.table.view.switch_to_panel')"
        :tooltip="trans('pelican-mod-manager::strings.table.view.switch_to_panel')"
    />
    <x-filament::icon-button
        color="gray"
        icon="tabler-list"
        wire:click="toggleCatalogView"
        data-mmr-view-toggle
        x-show="$wire.catalogView === 'panel'"
        :label="trans('pelican-mod-manager::strings.table.view.switch_to_list')"
        :tooltip="trans('pelican-mod-manager::strings.table.view.switch_to_list')"
    />
</div>
