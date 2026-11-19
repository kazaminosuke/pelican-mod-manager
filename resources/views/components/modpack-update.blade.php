<div wire:key="modpack-update-review">
    @if ($current['inventory'] !== null || $current['recovery'] !== null || $review !== null)
        <section class="fi-section" style="padding:1rem">
            <div class="flex flex-col gap-3">
                @if ($current['inventory'] !== null)
                    <p>
                        <strong>{{ __('pelican-mod-manager::strings.modpack_updates.current') }}</strong>
                        {{ $current['inventory']['title'] ?? $current['inventory']['pack_id'] }}
                        · {{ $current['inventory']['version_name'] ?? $current['inventory']['version_id'] ?? '—' }}
                        @if ($current['revision'] === null)
                            · {{ __('pelican-mod-manager::strings.modpack_updates.legacy') }}
                        @endif
                    </p>
                @endif
                @if ($current['recovery'] !== null)
                    <p role="alert">{{ __('pelican-mod-manager::strings.modpack_updates.recovery') }}
                        {{ $current['recovery']['backup'] ?? '' }}</p>
                    @if ($canRecover)
                        <div>
                            <x-filament::button wire:click="confirmModpackBackupRestored('{{ $current['recovery']['transaction'] }}')" wire:loading.attr="disabled">
                                {{ __('pelican-mod-manager::strings.modpack_updates.confirm_restored') }}
                            </x-filament::button>
                        </div>
                    @endif
                @endif
                @if ($review !== null)
                    <h2 class="font-semibold">{{ __('pelican-mod-manager::strings.modpack_updates.preview') }}</h2>
                    <p>{{ $review['release']['title'] }} · {{ $review['release']['version_name'] }} ({{ $review['release']['version_id'] }})</p>
                    <p>{{ implode(' · ', array_filter([$review['release']['minecraft_version'], $review['release']['loader'], $review['release']['loader_version'], $review['release']['java'], $review['release']['egg']])) }}</p>
                    <p>{{ __('pelican-mod-manager::strings.modpack_updates.counts', [
                        'add' => $review['summary']['mods']['add'], 'replace' => $review['summary']['mods']['replace'],
                        'remove' => $review['summary']['mods']['delete'], 'keep' => $review['summary']['counts']['keep'] + $review['summary']['counts']['preserve'],
                        'conflicts' => count($review['summary']['conflicts']), 'protected' => count($review['summary']['protected']),
                    ]) }}</p>
                    <p>{{ __('pelican-mod-manager::strings.modpack_updates.space', [
                        'size' => number_format($review['summary']['stage_bytes'] / (1024 * 1024), 2),
                        'unknown' => $review['summary']['unknown_sizes'],
                    ]) }}</p>
                    @if ($review['summary']['paths'] !== [] || $review['summary']['conflicts'] !== [] || $review['summary']['protected'] !== [])
                        <details>
                            <summary>{{ __('pelican-mod-manager::strings.modpack_updates.files') }}</summary>
                            <ul>
                                @foreach (array_slice(array_unique([...array_keys($review['summary']['paths']), ...$review['summary']['conflicts'], ...$review['summary']['protected']]), 0, 100) as $path)
                                    <li><code>{{ $path }}</code> · {{ __('pelican-mod-manager::strings.modpack_updates.actions.'.($review['summary']['paths'][$path] ?? (in_array($path, $review['summary']['protected'], true) ? 'preserve' : 'conflict'))) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if ($review['summary']['mod_conflicts'] !== [])
                        <p role="alert">{{ __('pelican-mod-manager::strings.modpack_updates.blocked') }}</p>
                    @endif
                    @if ($review['release']['switch'])
                        <label><input type="checkbox" wire:model="confirmModpackSwitch"> {{ __('pelican-mod-manager::strings.modpack_updates.confirm_switch') }}</label>
                    @endif
                    @if ($canStart)
                        <label><input type="checkbox" wire:model="startAfterModpackUpdate"> {{ __('pelican-mod-manager::strings.modpack_updates.start') }}</label>
                    @endif
                    <p>{{ __('pelican-mod-manager::strings.modpack_updates.backup_status.'.($backup?->completed_at !== null
                        ? ($backup->is_successful ? 'ready' : 'failed') : ($backup === null ? 'required' : 'creating'))) }}</p>
                    <div class="flex flex-wrap gap-2">
                        @if ($canBackup && ($backup === null || ($backup->completed_at !== null && !$backup->is_successful)))
                            <x-filament::button wire:click="createModpackUpdateBackup('{{ $review['token'] }}')" wire:loading.attr="disabled">
                                {{ __('pelican-mod-manager::strings.modpack_updates.backup') }}
                            </x-filament::button>
                        @endif
                        @if ($backup !== null && $backup->completed_at === null)
                            <x-filament::button color="gray" wire:click="$refresh">
                                {{ __('pelican-mod-manager::strings.modpack_updates.refresh') }}
                            </x-filament::button>
                        @endif
                        @if ($backup?->completed_at !== null && $backup->is_successful && $review['summary']['mod_conflicts'] === [])
                            <x-filament::button wire:click="applyModpackUpdate('{{ $review['token'] }}')" wire:loading.attr="disabled">
                                {{ __('pelican-mod-manager::strings.modpack_updates.apply') }}
                            </x-filament::button>
                        @endif
                        <x-filament::button color="gray" wire:click="cancelModpackUpdate('{{ $review['token'] }}')" wire:loading.attr="disabled">
                            {{ __('pelican-mod-manager::strings.modpack_updates.cancel') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
