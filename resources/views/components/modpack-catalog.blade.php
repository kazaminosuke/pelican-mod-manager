@php
    $state = $getState();
    $statePath = $getStatePath();
    $page = max(1, (int) ($page ?? 1));
    $pageSize = max(1, (int) ($pageSize ?? \Kazaminosuke\ModManager\Services\ModpackCatalog::PAGE_SIZE));
    $totalHits = max(0, (int) ($totalHits ?? count($hits)));
    $totalPages = $totalHits > 0 ? (int) ceil($totalHits / $pageSize) : 1;
    $hasNext = (bool) ($hasNext ?? ($page < $totalPages));
    $showingFrom = $hits !== [] ? (($page - 1) * $pageSize) + 1 : 0;
    $showingTo = $hits !== [] ? $showingFrom + count($hits) - 1 : 0;
    $placeholderIcon = \Kazaminosuke\ModManager\Support\ProjectIconUrl::placeholderDataUri();
    $loaderLabels = [
        'fabric' => 'Fabric',
        'forge' => 'Forge',
        'neoforge' => 'NeoForge',
        'quilt' => 'Quilt',
    ];
    $loaderColors = [
        'fabric' => 'info',
        'forge' => 'warning',
        'neoforge' => 'warning',
        'quilt' => 'primary',
    ];
    $formatDate = static function (?string $raw): ?string {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    };
    $resolvedSelectedTitle = is_string($selectedTitle ?? null) && trim($selectedTitle) !== ''
        ? trim($selectedTitle)
        : null;
    if ($resolvedSelectedTitle === null && is_string($state) && $state !== '') {
        foreach ($hits as $candidate) {
            if (($candidate['key'] ?? null) === $state && filled($candidate['title'] ?? null)) {
                $resolvedSelectedTitle = (string) $candidate['title'];
                break;
            }
        }
    }
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="mmr-modpack-catalog">
        @if (is_string($state) && $state !== '')
            <div class="mmr-modpack-selected-banner" role="status" aria-live="polite">
                <x-filament::icon icon="tabler-circle-check" class="mmr-modpack-selected-icon" />
                <span class="mmr-modpack-selected-text">
                    {{ trans('pelican-mod-manager::strings.modpacks.selected_pack', ['pack' => $resolvedSelectedTitle ?: $state]) }}
                </span>
            </div>
        @endif

        <div class="fi-ta">
            <div class="fi-ta-ctn">
                @if ($hits !== [])
                    <div class="fi-ta-content-ctn">
                        <table class="fi-ta-table">
                            <thead>
                                <tr>
                                    <th class="fi-ta-header-cell mmr-modpack-col-icon" scope="col">
                                        <span class="fi-sr-only">{{ trans('pelican-mod-manager::strings.modpacks.pack') }}</span>
                                    </th>
                                    <th class="fi-ta-header-cell mmr-modpack-col-title" scope="col">
                                        {{ trans('pelican-mod-manager::strings.table.columns.title') }}
                                    </th>
                                    <th class="fi-ta-header-cell mmr-modpack-col-compat" scope="col">
                                        {{ trans('pelican-mod-manager::strings.table.columns.compatibility') }}
                                    </th>
                                    <th class="fi-ta-header-cell mmr-modpack-col-stats" scope="col">
                                        {{ trans('pelican-mod-manager::strings.modpacks.stats') }}
                                    </th>
                                    <th class="fi-ta-header-cell mmr-modpack-col-action" scope="col">
                                        <span class="fi-sr-only">{{ trans('pelican-mod-manager::strings.modpacks.select_pack') }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($hits as $hit)
                                    @php
                                        $icon = $hit['icon_url'] ?? '';
                                        $icon = is_string($icon) && str_starts_with($icon, 'https://')
                                            ? $icon
                                            : $placeholderIcon;
                                        $title = trim((string) ($hit['title'] ?? '')) ?: (string) ($hit['project_id'] ?? '');
                                        $description = trim((string) ($hit['description'] ?? ''));
                                        $author = is_string($hit['author'] ?? null) ? trim($hit['author']) : '';
                                        $mcVersions = is_array($hit['minecraft_versions'] ?? null) ? array_values($hit['minecraft_versions']) : [];
                                        $visibleMcVersions = array_slice($mcVersions, 0, 2);
                                        $extraMcVersions = max(0, count($mcVersions) - count($visibleMcVersions));
                                        $loaders = is_array($hit['loaders'] ?? null) ? array_values($hit['loaders']) : [];
                                        $downloads = isset($hit['downloads']) && is_numeric($hit['downloads']) ? (int) $hit['downloads'] : null;
                                        $updatedDate = $formatDate(is_string($hit['date_modified'] ?? null) ? $hit['date_modified'] : null);
                                        $selected = is_string($state) && $state === ($hit['key'] ?? null);
                                    @endphp
                                    <tr
                                        @class(['fi-ta-row', 'mmr-modpack-row', 'mmr-modpack-row-selected' => $selected])
                                        data-mmr-modpack-selected="{{ $selected ? 'true' : 'false' }}"
                                        wire:key="modpack-{{ $hit['key'] }}"
                                    >
                                        <td class="fi-ta-cell mmr-modpack-cell-icon">
                                            <div class="mmr-modpack-icon-wrap">
                                                <img
                                                    src="{{ $icon }}"
                                                    alt=""
                                                    width="40"
                                                    height="40"
                                                    loading="lazy"
                                                    decoding="async"
                                                    class="mmr-modpack-icon"
                                                >
                                            </div>
                                        </td>

                                        <td class="fi-ta-cell mmr-modpack-cell-title">
                                            <div class="mmr-modpack-title-block">
                                                <div class="mmr-modpack-title-line">
                                                    <span class="mmr-modpack-title" title="{{ $title }}">{{ $title }}</span>
                                                </div>
                                                @if ($description !== '')
                                                    <p class="mmr-modpack-description" title="{{ $description }}">{{ $description }}</p>
                                                @endif
                                                @if ($author !== '')
                                                    <span class="mmr-modpack-author">{{ $author }}</span>
                                                @endif
                                            </div>
                                        </td>

                                        <td class="fi-ta-cell mmr-modpack-cell-compat" data-mmr-empty="{{ ($visibleMcVersions === [] && $loaders === []) ? 'true' : 'false' }}">
                                            @if ($visibleMcVersions !== [] || $loaders !== [])
                                                <div class="mmr-modpack-badges">
                                                    @foreach ($visibleMcVersions as $mcVersion)
                                                        <x-filament::badge color="gray" size="sm">{{ $mcVersion }}</x-filament::badge>
                                                    @endforeach
                                                    @if ($extraMcVersions > 0)
                                                        <x-filament::badge color="gray" size="sm" :title="implode(', ', $mcVersions)">
                                                            +{{ $extraMcVersions }}
                                                        </x-filament::badge>
                                                    @endif
                                                    @foreach ($loaders as $loaderSlug)
                                                        @php
                                                            $slug = strtolower((string) $loaderSlug);
                                                            $loaderLabel = $loaderLabels[$slug] ?? ucfirst($slug);
                                                            $loaderColor = $loaderColors[$slug] ?? 'gray';
                                                        @endphp
                                                        <x-filament::badge :color="$loaderColor" size="sm">{{ $loaderLabel }}</x-filament::badge>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="mmr-modpack-muted">—</span>
                                            @endif
                                        </td>

                                        <td class="fi-ta-cell mmr-modpack-cell-stats" data-mmr-empty="{{ ($downloads === null && $updatedDate === null) ? 'true' : 'false' }}">
                                            @if ($downloads !== null || $updatedDate !== null)
                                                <div class="mmr-modpack-stats">
                                                    @if ($downloads !== null)
                                                        <span class="mmr-modpack-stat" title="{{ trans('pelican-mod-manager::strings.table.columns.downloads') }}">
                                                            <span class="mmr-stat-icon" data-mmr-stat-icon="downloads" aria-hidden="true"></span>
                                                            <span>{{ number_format($downloads) }}</span>
                                                        </span>
                                                    @endif
                                                    @if ($updatedDate !== null)
                                                        <span class="mmr-modpack-stat" title="{{ trans('pelican-mod-manager::strings.table.columns.date_modified') }}">
                                                            <span class="mmr-stat-icon" data-mmr-stat-icon="calendar" aria-hidden="true"></span>
                                                            <span>{{ $updatedDate }}</span>
                                                        </span>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="mmr-modpack-muted">—</span>
                                            @endif
                                        </td>

                                        <td class="fi-ta-cell mmr-modpack-cell-action">
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                :color="$selected ? 'success' : 'gray'"
                                                :icon="$selected ? 'tabler-check' : null"
                                                aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                                :aria-label="trans('pelican-mod-manager::strings.modpacks.'.($selected ? 'pack_selected' : 'select_pack')).' · '.$title"
                                                :wire:click="'$set(' . \Illuminate\Support\Js::from($statePath) . ', ' . \Illuminate\Support\Js::from($hit['key']) . ')'"
                                            >
                                                {{ trans('pelican-mod-manager::strings.modpacks.'.($selected ? 'pack_selected' : 'select_pack')) }}
                                            </x-filament::button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="fi-ta-empty-state mmr-modpack-empty">
                        <div class="fi-ta-empty-state-content">
                            <div class="fi-ta-empty-state-icon-bg">
                                <x-filament::icon
                                    :icon="($isError ?? false) ? 'tabler-alert-triangle' : 'tabler-packages'"
                                    class="fi-ta-empty-state-icon"
                                />
                            </div>
                            <p class="fi-ta-empty-state-description">{{ $emptyMessage }}</p>
                        </div>
                    </div>
                @endif

                @if ($hits !== [] && ($page > 1 || $hasNext || $totalHits > 0))
                    <nav class="fi-pagination mmr-modpack-pagination" aria-label="{{ trans('pelican-mod-manager::strings.modpacks.catalog_pagination') }}">
                        <span class="fi-pagination-overview mmr-modpack-pagination-overview">
                            {{ trans('pelican-mod-manager::strings.modpacks.catalog_showing', [
                                'from' => number_format($showingFrom),
                                'to' => number_format($showingTo),
                                'total' => number_format(max($totalHits, $showingTo)),
                            ]) }}
                        </span>

                        @if ($page > 1 || $hasNext)
                            <div class="mmr-modpack-pagination-controls">
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    size="sm"
                                    :disabled="$page <= 1"
                                    :wire:click="'$set(' . \Illuminate\Support\Js::from($pageStatePath) . ', ' . ($page - 1) . ')'"
                                >
                                    {{ html_entity_decode(trans('pagination.previous'), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                                </x-filament::button>
                                <span class="mmr-modpack-pagination-page" aria-current="page">
                                    {{ trans('pelican-mod-manager::strings.modpacks.catalog_page_of', [
                                        'page' => $page,
                                        'pages' => max($page, $totalPages),
                                    ]) }}
                                </span>
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    size="sm"
                                    :disabled="!$hasNext"
                                    :wire:click="'$set(' . \Illuminate\Support\Js::from($pageStatePath) . ', ' . ($page + 1) . ')'"
                                >
                                    {{ html_entity_decode(trans('pagination.next'), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
                                </x-filament::button>
                            </div>
                        @endif
                    </nav>
                @endif
            </div>
        </div>
    </div>
</x-dynamic-component>
