<div @class(['mmr-installed-scan-pending' => $inTable]) role="status">
    <x-filament::badge :color="$color" :icon="$icon">
        @if ($loading)
            <x-filament::loading-indicator />
        @endif
        {{ $message }}
    </x-filament::badge>
</div>
