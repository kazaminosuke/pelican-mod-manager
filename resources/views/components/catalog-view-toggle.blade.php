@php
    $table = $this->getTable();
    $hasTriggerGroup = $table->isFilterable() || $table->hasColumnManager();
@endphp

@unless ($this->activeTab === 'installed' || (($searchFallback ?? false) && $hasTriggerGroup))
    @php
        $isPanelView = $this->catalogView === \Kazaminosuke\ModManager\Support\CatalogTileColumns::VIEW_PANEL;
        $label = trans('pelican-mod-manager::strings.table.view.'.($isPanelView ? 'switch_to_list' : 'switch_to_panel'));
        $trigger = $table->getColumnManagerTriggerAction();
        $restoreLegacyView = \Kazaminosuke\ModManager\Support\CatalogTileColumns::storedView() === null;
    @endphp

    <x-filament::icon-button
        :color="$trigger->getColor()"
        :size="$trigger->getSize()"
        :icon-size="$trigger->getIconSize()"
        :icon="$isPanelView ? 'tabler-list' : 'tabler-layout-grid'"
        :label="$label"
        :tooltip="$label"
        wire:click="toggleCatalogView"
        wire:key="mmr-catalog-view-toggle"
        data-mmr-view-toggle
        :x-data="$restoreLegacyView ? '{}' : null"
        :x-init="$restoreLegacyView ? '(() => { try { if (localStorage.getItem(\'pelican-mod-manager.catalog-view\') === \'panel\') { $wire.restoreLegacyCatalogView() } } catch {} })()' : null"
    />
@endunless
