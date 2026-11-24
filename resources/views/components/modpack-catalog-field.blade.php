<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @livewire('pelican-mod-manager.modpack-catalog-table', [
        'mode' => $mode,
        'provider' => $provider,
        'query' => $query,
        'minecraft' => $minecraft,
        'loader' => $loader,
        'serverId' => $serverId,
        'selectedKey' => $getState(),
        'selectedVersion' => $selectedVersion,
        'targetStatePath' => $getStatePath(),
        'titleStatePath' => $titleStatePath,
        'versionStatePath' => $versionStatePath,
    ], key($tableKey))
</x-dynamic-component>
