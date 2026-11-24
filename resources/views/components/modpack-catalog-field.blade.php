<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @livewire('pelican-mod-manager.modpack-catalog-table', [
        'provider' => $provider,
        'query' => $query,
        'minecraft' => $minecraft,
        'loader' => $loader,
        'serverId' => $serverId,
        'selectedKey' => $getState(),
        'targetStatePath' => $getStatePath(),
        'titleStatePath' => $titleStatePath,
    ], key($tableKey))
</x-dynamic-component>
