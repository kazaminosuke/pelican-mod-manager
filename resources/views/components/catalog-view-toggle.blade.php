@unless (($this->activeTab ?? null) === 'installed')
    @php
        $isPanelView = ($this->catalogView ?? 'list') === 'panel';
        $label = trans('pelican-mod-manager::strings.table.view.'.($isPanelView ? 'switch_to_list' : 'switch_to_panel'));
        $trigger = $this->getTable()->getColumnManagerTriggerAction();
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
        ->extraAttributes(['wire:click' => 'toggleCatalogView', 'data-mmr-view-toggle' => '']) }}
@endunless
