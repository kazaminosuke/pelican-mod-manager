<div class="space-y-4">
    @if (count($this->providerTabs()) > 1)
        <x-filament::tabs :label="trans('pelican-mod-manager::strings.table.columns.source')">
            @foreach ($this->providerTabs() as $key => $label)
                <x-filament::tabs.item
                    :active="$provider === $key"
                    wire:click="setProvider({{ \Illuminate\Support\Js::from($key) }})"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @endif

    <div class="mmr-table-scroll-ctn">
        {{ $this->table }}
    </div>
</div>
