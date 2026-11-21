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
                    <p role="alert">{{ __('pelican-mod-manager::strings.modpack_updates.'.($current['recovery']['backup'] === null ? 'recovery_initial' : 'recovery')) }}
                        {{ $current['recovery']['backup'] ?? '' }}</p>
                    @if ($current['recovery']['backup'] === null)
                        <ul>
                            @foreach (array_keys($current['recovery']['originals'] ?? []) as $path)
                                <li><code>{{ $path }}</code></li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($canRecover)
                        <div>
                            <x-filament::button wire:click="confirmModpackBackupRestored('{{ $current['recovery']['transaction'] }}')" wire:loading.attr="disabled">
                                {{ __('pelican-mod-manager::strings.modpack_updates.confirm_restored') }}
                            </x-filament::button>
                        </div>
                    @endif
                @endif
                @if ($review !== null)
                    @php($blocking = $review['summary']['blocking_conflicts'] ?? $review['summary']['mod_conflicts'])
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
                    @if ($blocking !== [])
                        <p role="alert">{{ __('pelican-mod-manager::strings.modpack_updates.blocked') }}</p>
                    @endif
                    @if ($review['summary']['conflicts'] !== [])
                        <details {{ $blocking !== [] ? 'open' : '' }}>
                            <summary>{{ __('pelican-mod-manager::strings.modpack_updates.conflict_files') }}</summary>
                            <ul>
                                @foreach ($review['summary']['conflicts'] as $path)
                                    <li><code>{{ $path }}</code></li>
                                @endforeach
                            </ul>
                            @if (array_diff($review['summary']['conflicts'], $blocking) !== [])
                                <p>{{ __('pelican-mod-manager::strings.modpack_updates.config_conflicts') }}</p>
                            @endif
                        </details>
                    @endif
                    @foreach ($review['summary']['warnings'] ?? [] as $warning)
                        @if (str_starts_with($warning, 'override-too-large:') || str_starts_with($warning, 'no-download:'))
                            <p role="status">{{ __('pelican-mod-manager::strings.modpack_updates.omitted_file', ['path' => substr($warning, strpos($warning, ':') + 1)]) }}</p>
                        @endif
                    @endforeach
                    @if ($review['summary']['paths'] !== [] || $review['summary']['protected'] !== [])
                        <details>
                            <summary>{{ __('pelican-mod-manager::strings.modpack_updates.files') }}</summary>
                            <ul>
                                @foreach (array_slice(array_diff(array_unique([...array_keys($review['summary']['paths']), ...$review['summary']['protected']]), $review['summary']['conflicts']), 0, 100) as $path)
                                    <li><code>{{ $path }}</code> · {{ __('pelican-mod-manager::strings.modpack_updates.actions.'.($review['summary']['paths'][$path] ?? 'preserve')) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if ($review['release']['switch'])
                        <label><input type="checkbox" wire:model="confirmModpackSwitch"> {{ __('pelican-mod-manager::strings.modpack_updates.confirm_switch') }}</label>
                    @endif
                    @if ($canStart)
                        <label><input type="checkbox" wire:model="startAfterModpackUpdate"> {{ __('pelican-mod-manager::strings.modpack_updates.start') }}</label>
                    @endif
                    <p>{{ __('pelican-mod-manager::strings.modpack_updates.backup_status.'.($backup?->completed_at !== null
                        ? ($backup->is_successful ? 'ready' : 'failed') : ($backup === null ? 'required' : 'creating'))) }}</p>
                    @if ($canViewBackups)
                        <p><a class="fi-link" href="{{ \App\Filament\Server\Resources\Backups\BackupResource::getUrl() }}">{{ __('pelican-mod-manager::strings.modpack_updates.open_backups') }}</a></p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @if ($canBackup && $blocking === [] && ($backup === null || ($backup->completed_at !== null && !$backup->is_successful)))
                            <x-filament::button wire:click="createModpackUpdateBackup('{{ $review['token'] }}')" wire:loading.attr="disabled">
                                {{ __('pelican-mod-manager::strings.modpack_updates.backup') }}
                            </x-filament::button>
                        @endif
                        @if ($backup !== null && $backup->completed_at === null)
                            <x-filament::button color="gray" wire:click="$refresh">
                                {{ __('pelican-mod-manager::strings.modpack_updates.refresh') }}
                            </x-filament::button>
                        @endif
                        @if ($backup?->completed_at !== null && $backup->is_successful && $blocking === [])
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
