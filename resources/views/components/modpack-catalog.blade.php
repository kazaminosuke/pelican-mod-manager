<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($getState())
        <p class="fi-size-sm" aria-live="polite">
            {{ trans('pelican-mod-manager::strings.modpacks.selected_pack', ['pack' => $selectedTitle ?: $getState()]) }}
        </p>
    @endif

    <div class="fi-ta">
        <div class="fi-ta-content-ctn">
            <table class="fi-ta-table">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell" scope="col">{{ trans('pelican-mod-manager::strings.modpacks.pack') }}</th>
                        <th class="fi-ta-header-cell" scope="col">{{ trans('pelican-mod-manager::strings.table.columns.source') }}</th>
                        <th class="fi-ta-header-cell" scope="col">{{ trans('pelican-mod-manager::strings.version.downloads') }}</th>
                        <th class="fi-ta-header-cell" scope="col"><span class="fi-sr-only">{{ trans('pelican-mod-manager::strings.modpacks.select_pack') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hits as $hit)
                        @php
                            $icon = $hit['icon_url'] ?? '';
                            $icon = is_string($icon) && str_starts_with($icon, 'https://')
                                ? $icon : \Kazaminosuke\ModManager\Support\ProjectIconUrl::placeholderDataUri();
                            $selected = $getState() === $hit['key'];
                        @endphp
                        <tr class="fi-ta-row" wire:key="modpack-{{ $hit['key'] }}">
                            <td class="fi-ta-cell">
                                <div class="flex items-center gap-3 px-3 py-2">
                                    <img src="{{ $icon }}" alt="" width="40" height="40" loading="lazy" decoding="async" class="size-10 shrink-0 rounded object-cover">
                                    <div>
                                        <strong>{{ $hit['title'] ?: $hit['project_id'] }}</strong>
                                        @if (filled($hit['description'] ?? null))
                                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $hit['description'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="fi-ta-cell px-3"><x-filament::badge>{{ $hit['source'] }}</x-filament::badge></td>
                            <td class="fi-ta-cell px-3">{{ isset($hit['downloads']) ? number_format((int) $hit['downloads']) : '—' }}</td>
                            <td class="fi-ta-cell px-3">
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    :color="$selected ? 'success' : 'gray'"
                                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                    :aria-label="trans('pelican-mod-manager::strings.modpacks.'.($selected ? 'pack_selected' : 'select_pack')).' · '.($hit['title'] ?: $hit['project_id'])"
                                    :wire:click="'$set(' . \Illuminate\Support\Js::from($getStatePath()) . ', ' . \Illuminate\Support\Js::from($hit['key']) . ')'"
                                >
                                    {{ trans('pelican-mod-manager::strings.modpacks.'.($selected ? 'pack_selected' : 'select_pack')) }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="fi-ta-cell px-3 py-4 text-sm" colspan="4">{{ $emptyMessage }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($page > 1 || $hasNext)
            <nav class="flex items-center justify-between gap-2 p-3" aria-label="{{ trans('pelican-mod-manager::strings.modpacks.catalog_pagination') }}">
                <x-filament::button type="button" color="gray" size="sm" :disabled="$page <= 1" :wire:click="'$set(' . \Illuminate\Support\Js::from($pageStatePath) . ', ' . ($page - 1) . ')'">
                    {{ trans('pagination.previous') }}
                </x-filament::button>
                <span aria-current="page">{{ $page }}</span>
                <x-filament::button type="button" color="gray" size="sm" :disabled="!$hasNext" :wire:click="'$set(' . \Illuminate\Support\Js::from($pageStatePath) . ', ' . ($page + 1) . ')'">
                    {{ trans('pagination.next') }}
                </x-filament::button>
            </nav>
        @endif
    </div>
</x-dynamic-component>
