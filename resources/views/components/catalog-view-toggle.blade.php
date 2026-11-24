@php
    $isInstalledTab = property_exists($this, 'activeTab') && $this->activeTab === 'installed';
    $isPanelView = ($this->catalogView ?? 'list') === 'panel';
@endphp
@unless ($isInstalledTab)
    <div>
        @if ($isPanelView)
            <x-filament::icon-button
                color="gray"
                icon="tabler-list"
                wire:click="toggleCatalogView"
                data-mmr-view-toggle
                :label="trans('pelican-mod-manager::strings.table.view.switch_to_list')"
                :tooltip="trans('pelican-mod-manager::strings.table.view.switch_to_list')"
            />
        @else
            <x-filament::icon-button
                color="gray"
                icon="tabler-layout-grid"
                wire:click="toggleCatalogView"
                data-mmr-view-toggle
                :label="trans('pelican-mod-manager::strings.table.view.switch_to_panel')"
                :tooltip="trans('pelican-mod-manager::strings.table.view.switch_to_panel')"
            />
        @endif
    </div>
@endunless
