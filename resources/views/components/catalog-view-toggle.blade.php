@unless (($this->activeTab ?? null) === 'installed')
    @php
        $isPanelView = ($this->catalogView ?? 'list') === 'panel';
        $label = trans('pelican-mod-manager::strings.table.view.'.($isPanelView ? 'switch_to_list' : 'switch_to_panel'));
        $trigger = $this->getTable()->getColumnManagerTriggerAction();
        $attributes = ['wire:click' => 'toggleCatalogView', 'data-mmr-view-toggle' => ''];
        if (\Kazaminosuke\ModManager\Support\CatalogTileColumns::storedView() === null) {
            $attributes['x-data'] = '{}';
            $attributes['x-init'] = "(() => { try { if (localStorage.getItem('pelican-mod-manager.catalog-view') === 'panel') { \$wire.restoreLegacyCatalogView() } } catch {} })()";
        }
    @endphp

    {{ \Filament\Actions\Action::make('toggleCatalogView')
        ->iconButton()
        ->color($trigger->getColor())
        ->size($trigger->getSize())
        ->iconSize($trigger->getIconSize())
        ->icon($isPanelView ? 'tabler-list' : 'tabler-layout-grid')
        ->label($label)
        ->tooltip($label)
        ->livewireClickHandlerEnabled(false)
        ->extraAttributes($attributes) }}
@endunless
