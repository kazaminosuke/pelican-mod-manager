<div class="mmr-catalog-sort-toolbar" x-cloak x-show="$wire.activeTab !== 'installed'">
    <label for="mmr-catalog-sort" class="fi-sr-only">{{ trans('pelican-mod-manager::strings.table.sort.label') }}</label>
    <x-filament::input.wrapper>
        <x-filament::input.select id="mmr-catalog-sort" wire:model.live="catalogSort">
            <template x-for="([value, label]) in Object.entries($wire.catalogSortOptions || {})" :key="value">
                <option :value="value" x-text="label"></option>
            </template>
        </x-filament::input.select>
    </x-filament::input.wrapper>
</div>
