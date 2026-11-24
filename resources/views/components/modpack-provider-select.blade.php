@if (count($this->providerTabs()) > 1)
    <div class="mmr-catalog-sort-toolbar">
        <label for="mmr-modpack-provider-{{ $this->getId() }}" class="fi-sr-only">
            {{ trans('pelican-mod-manager::strings.table.columns.source') }}
        </label>
        <x-filament::input.wrapper>
            <x-filament::input.select
                id="mmr-modpack-provider-{{ $this->getId() }}"
                wire:model.live="provider"
            >
                @foreach ($this->providerTabs() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>
@endif
