@if ($plan !== null)
    <section class="fi-section" aria-label="{{ __('pelican-mod-manager::strings.curseforge_manual.waiting') }}" style="padding: 1rem">
        <div class="flex flex-col gap-3">
            <h2 class="font-semibold">{{ __('pelican-mod-manager::strings.curseforge_manual.waiting') }}</h2>
            <p>{{ __('pelican-mod-manager::strings.curseforge_manual.release', ['project' => $plan['project_id'], 'file' => $plan['version_id']]) }}</p>
            @if ($failed)
                <p role="alert">{{ __('pelican-mod-manager::strings.curseforge_manual.retry_failed') }}</p>
            @endif
            @if (array_filter(array_column($plan['files'], 'staging_path')) !== [])
                <p>{{ __('pelican-mod-manager::strings.curseforge_manual.upload_instructions') }}</p>
                <div>
                    <x-filament::button tag="a" :href="$filesUrl" target="_blank" rel="noopener noreferrer" color="gray">
                        {{ __('pelican-mod-manager::strings.curseforge_manual.open_files') }}
                    </x-filament::button>
                </div>
            @endif
            <ul class="flex flex-col gap-3">
                @foreach ($plan['files'] as $file)
                    <li wire:key="curseforge-dependency-{{ $file['project_id'] }}-{{ $file['file_id'] }}">
                        <strong>{{ $file['name'] ?? $file['filename'] ?? 'CurseForge' }}</strong>
                        <span>{{ __('pelican-mod-manager::strings.curseforge_manual.release', ['project' => $file['project_id'], 'file' => $file['file_id']]) }}</span>
                        <span>{{ __('pelican-mod-manager::strings.curseforge_manual.' . ($file['required'] ? 'required' : 'optional')) }}</span>
                        @if ($file['filename'] !== null)
                            <div>{{ $file['filename'] }}@if ($file['size'] !== null) · {{ number_format($file['size']) }} bytes @endif</div>
                        @endif
                        @foreach ($file['hashes'] as $algorithm => $hash)
                            <div><code style="overflow-wrap: anywhere">{{ $algorithm }} {{ $hash }}</code></div>
                        @endforeach
                        <div>{{ __('pelican-mod-manager::strings.curseforge_manual.reasons.' . $file['reason']) }}</div>
                        @if ($file['staging_path'] !== null)
                            <div><code>{{ $file['staging_path'] }}</code></div>
                        @endif
                        @if ($file['page'] !== null || $file['staging_path'] !== null)
                            <a class="fi-link" href="{{ $file['page'] ?? ('https://www.curseforge.com/minecraft/search?class=mc-mods&search=' . rawurlencode($file['name'] ?? (string) $file['project_id'])) }}" target="_blank" rel="noopener noreferrer">
                                {{ __('pelican-mod-manager::strings.curseforge_manual.' . ($file['page'] === null ? 'find_file' : 'open_file')) }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="flex flex-wrap gap-2">
                <x-filament::button wire:click="resumeCurseForgeModpack('{{ $plan['token'] }}')" wire:loading.attr="disabled" wire:target="resumeCurseForgeModpack,cancelCurseForgeModpack">
                    {{ __('pelican-mod-manager::strings.curseforge_manual.resume') }}
                </x-filament::button>
                <x-filament::button color="gray" wire:click="cancelCurseForgeModpack('{{ $plan['token'] }}')" wire:confirm="{{ __('pelican-mod-manager::strings.curseforge_manual.cancel_confirm') }}" wire:loading.attr="disabled" wire:target="resumeCurseForgeModpack,cancelCurseForgeModpack">
                    {{ __('pelican-mod-manager::strings.curseforge_manual.cancel') }}
                </x-filament::button>
            </div>
        </div>
    </section>
@endif
