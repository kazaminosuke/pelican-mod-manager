    (() => {
        'use strict';

        if (window.__mmrTableSwrCacheV9) {
            window.__mmrTableSwrCacheV9.activate();

            return;
        }

        // V1 stored sanitized copies of whole Filament table fragments. V9
        // stores display values only, and catalog row actions are CSS-mask
        // buttons rather than per-row inline SVGs.
        const SCHEMA_VERSION = 10;
        const STORAGE_PREFIX = `mmr-table-swr:v${SCHEMA_VERSION}:`;
        const INDEX_KEY = `${STORAGE_PREFIX}index`;
        const DEBUG_STORAGE_KEY = 'mmrSwrDebug';
        // This exact URI is the server-side ImageColumn fallback. It is safe to
        // retain in sessionStorage; no arbitrary data URI is ever accepted.
        const PROJECT_ICON_PLACEHOLDER_DATA_URI = document.currentScript?.dataset.mmrProjectIconPlaceholder ?? '';
        const TTL_MS = 10 * 60 * 1000;
        const MAX_ENTRIES = 20;
        const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn[data-mmr-swr-scope]';
        const CELL_SELECTOR = 'td[data-mmr-swr-cell]';
        const ROW_ACTION_SELECTOR = '[data-mmr-swr-row-action]';
        const ROW_ACTION_DEFINITIONS = Object.freeze({
            versions: {},
            install_latest: {},
            update: {},
            installed: { disabled: true },
            uninstall: {},
        });
        const ROW_ACTION_COLORS = new Set(['info', 'success', 'warning', 'danger']);
        const controllers = new WeakMap();
        const heldContentControllers = new WeakMap();
        const heldPaginationControllers = new WeakMap();
        const activeComponents = new WeakSet();
        const contextByComponent = new WeakMap();
        const contexts = new Set();
        const pendingScanContexts = new Set();
        const morphUnsubscribes = [];
        let scanFrame = null;
        let debugSequence = 0;

        const storage = {
            get(key) {
                try {
                    return window.sessionStorage.getItem(key);
                } catch (_error) {
                    return null;
                }
            },

            set(key, value) {
                try {
                    window.sessionStorage.setItem(key, value);

                    return true;
                } catch (_error) {
                    return false;
                }
            },

            remove(key) {
                try {
                    window.sessionStorage.removeItem(key);
                } catch (_error) {
                    // Disabled/private storage must never affect table usage.
                }
            },
        };

        const parseJson = (value, fallback = null) => {
            try {
                return JSON.parse(value);
            } catch (_error) {
                return fallback;
            }
        };

        const normalize = (value) => {
            if (value === null || ['string', 'number', 'boolean'].includes(typeof value)) {
                return value;
            }

            if (Array.isArray(value)) {
                return value.map(normalize);
            }

            if (typeof value === 'object') {
                return Object.keys(value)
                    .sort()
                    .reduce((result, key) => {
                        const normalized = normalize(value[key]);

                        if (normalized !== undefined) {
                            result[key] = normalized;
                        }

                        return result;
                    }, {});
            }

            return undefined;
        };

        const stableStringify = (value) => JSON.stringify(normalize(value));

        // Two independent 32-bit hashes avoid persisting search/filter input
        // verbatim while keeping accidental key collisions negligible.
        const digest = (value) => {
            let fnv = 0x811c9dc5;
            let djb = 0x1505;

            for (let index = 0; index < value.length; index++) {
                const code = value.charCodeAt(index);
                fnv ^= code;
                fnv = Math.imul(fnv, 0x01000193);
                djb = Math.imul(djb, 33) ^ code;
            }

            return `${(fnv >>> 0).toString(16).padStart(8, '0')}${(djb >>> 0).toString(16).padStart(8, '0')}-${value.length}`;
        };

        // Temporary, opt-in browser diagnostics. This deliberately logs only
        // state hashes and DOM structure, never search/filter text or cell data.
        const debugEnabled = () => {
            try {
                return window.localStorage.getItem(DEBUG_STORAGE_KEY) === '1';
            } catch (_error) {
                return false;
            }
        };

        const debugLog = (event, detail = {}) => {
            if (!debugEnabled()) {
                return;
            }

            console.info(`[mmr-swr +${Math.round(window.performance?.now?.() ?? 0)}ms] ${event}`, detail);
        };

        const valueDigest = (value) => digest(stableStringify(value) ?? '');

        const readIndex = () => {
            const index = parseJson(storage.get(INDEX_KEY), []);

            return Array.isArray(index) ? index : [];
        };

        const writeIndex = (index) => storage.set(INDEX_KEY, JSON.stringify(index));

        const prune = (now = Date.now(), keepKey = null) => {
            const entries = readIndex()
                .filter((entry) => {
                    const valid = entry
                        && typeof entry.key === 'string'
                        && Number(entry.expiresAt) > now
                        && storage.get(entry.key) !== null;

                    if (!valid && entry && typeof entry.key === 'string') {
                        storage.remove(entry.key);
                    }

                    return valid;
                })
                .sort((left, right) => Number(right.lastAccessedAt) - Number(left.lastAccessedAt));

            while (entries.length > MAX_ENTRIES) {
                const entry = entries.pop();

                if (entry?.key !== keepKey) {
                    storage.remove(entry.key);
                }
            }

            writeIndex(entries);

            return entries;
        };

        const touchIndex = (key, expiresAt, now = Date.now()) => {
            const index = prune(now, key).filter((entry) => entry.key !== key);
            index.unshift({ key, expiresAt, lastAccessedAt: now });

            while (index.length > MAX_ENTRIES) {
                const entry = index.pop();
                storage.remove(entry.key);
            }

            writeIndex(index);
        };

        const evictOldest = (exceptKey = null) => {
            const entries = readIndex()
                .filter((entry) => entry?.key !== exceptKey)
                .sort((left, right) => Number(left.lastAccessedAt) - Number(right.lastAccessedAt));
            const oldest = entries.shift();

            if (!oldest?.key) {
                return false;
            }

            storage.remove(oldest.key);
            writeIndex(readIndex().filter((entry) => entry?.key !== oldest.key));

            return true;
        };

        const saveEntry = (key, entry) => {
            const encoded = JSON.stringify(entry);

            for (let attempt = 0; attempt <= MAX_ENTRIES; attempt++) {
                if (storage.set(key, encoded)) {
                    touchIndex(key, entry.expiresAt, entry.lastAccessedAt);

                    return;
                }

                if (!evictOldest(key)) {
                    return;
                }
            }
        };

        const inspectCache = (key) => {
            const now = Date.now();
            const indexEntry = readIndex().find((entry) => entry?.key === key);

            if (!indexEntry) {
                return { available: false, reason: 'cache-miss' };
            }

            if (Number(indexEntry.expiresAt) <= now) {
                return {
                    available: false,
                    reason: 'expired',
                    expiresAgoMs: now - Number(indexEntry.expiresAt),
                };
            }

            if (storage.get(key) === null) {
                return { available: false, reason: 'cache-storage-missing' };
            }

            return { available: true };
        };

        const loadEntry = (key) => {
            const now = Date.now();
            const entry = parseJson(storage.get(key));

            if (
                !entry
                || entry.schema !== SCHEMA_VERSION
                || entry.digest !== key.slice(STORAGE_PREFIX.length)
                || Number(entry.expiresAt) <= now
                || !entry.projection
                || !Array.isArray(entry.projection.rows)
            ) {
                storage.remove(key);
                writeIndex(readIndex().filter((item) => item?.key !== key));

                return null;
            }

            entry.lastAccessedAt = now;
            storage.set(key, JSON.stringify(entry));
            touchIndex(key, entry.expiresAt, now);

            return entry;
        };

        const readScope = (wrapper) => {
            const raw = wrapper.dataset.mmrSwrScope ?? '';
            const parsed = parseJson(raw);

            return parsed && typeof parsed === 'object' ? parsed : raw;
        };

        const findWire = (wrapper) => {
            const componentElement = wrapper.closest('[wire\\:id]');
            const componentId = componentElement?.getAttribute('wire:id');

            if (!componentId || !window.Livewire?.find) {
                return null;
            }

            try {
                return window.Livewire.find(componentId) ?? null;
            } catch (_error) {
                return null;
            }
        };

        const getWireValue = (wire, property, fallback = null) => {
            try {
                const value = wire?.$get?.(property);

                return value === undefined ? fallback : value;
            } catch (_error) {
                return fallback;
            }
        };

        const describeViewState = (wire) => ({
            activeTab: String(getWireValue(wire, 'activeTab', '') ?? ''),
            paginators: normalize(getWireValue(wire, 'paginators', {})) ?? {},
            catalogSort: String(getWireValue(wire, 'catalogSort', 'downloads') ?? 'downloads'),
            minecraftVersionOverride: getWireValue(wire, 'minecraftVersionOverride', null),
            loaderOverride: getWireValue(wire, 'loaderOverride', null),
            perPage: getWireValue(wire, 'tableRecordsPerPage', null),
            tableSearchDigest: valueDigest(getWireValue(wire, 'tableSearch', '')),
            tableFiltersDigest: valueDigest(getWireValue(wire, 'tableFilters', {})),
            tableColumnsDigest: valueDigest(getWireValue(wire, 'tableColumns', {})),
            tableColumnSearchesDigest: valueDigest(getWireValue(wire, 'tableColumnSearches', {})),
        });

        const equalStateValue = (left, right) => stableStringify(left) === stableStringify(right);

        const describeTransition = (previous, target) => {
            if (!previous || !target) {
                return {
                    type: 'unknown',
                    changed: ['unknown'],
                    from: previous ?? null,
                    to: target ?? null,
                };
            }

            const changed = Object.keys(target).filter((key) => !equalStateValue(previous[key], target[key]));
            const type = changed.includes('activeTab')
                ? 'active-tab'
                : changed.includes('paginators')
                    ? 'pagination'
                    : changed.includes('tableSearchDigest')
                        ? 'search'
                        : changed.includes('tableFiltersDigest')
                            ? 'filters'
                            : changed.includes('catalogSort')
                                ? 'sort'
                                : changed.includes('minecraftVersionOverride') || changed.includes('loaderOverride')
                                    ? 'compatibility'
                                : changed.includes('tableColumnsDigest')
                                    ? 'columns'
                                    : 'other';

            return {
                type,
                changed,
                from: {
                    activeTab: previous.activeTab,
                    paginators: previous.paginators,
                },
                to: {
                    activeTab: target.activeTab,
                    paginators: target.paginators,
                },
            };
        };

        const buildKey = (wrapper, wire) => {
            const state = stableStringify({
                schema: SCHEMA_VERSION,
                scope: readScope(wrapper),
                activeTab: getWireValue(wire, 'activeTab'),
                tableSearch: getWireValue(wire, 'tableSearch', ''),
                catalogSort: getWireValue(wire, 'catalogSort', 'downloads'),
                minecraftVersionOverride: getWireValue(wire, 'minecraftVersionOverride', null),
                loaderOverride: getWireValue(wire, 'loaderOverride', null),
                tableFilters: getWireValue(wire, 'tableFilters', {}),
                paginators: getWireValue(wire, 'paginators', {}),
                perPage: getWireValue(wire, 'tableRecordsPerPage'),
                tableColumns: getWireValue(wire, 'tableColumns', {}),
                tableColumnSearches: getWireValue(wire, 'tableColumnSearches', {}),
                locale: document.documentElement.lang || 'en',
            });

            return `${STORAGE_PREFIX}${digest(state)}`;
        };

        const cacheKeyDigest = (key) => key?.slice(STORAGE_PREFIX.length) ?? null;

        const getContent = (wrapper) => wrapper.querySelector('.fi-ta-content-ctn');
        const getPagination = (wrapper) => wrapper.querySelector('.fi-pagination');
        const getPaginationItems = (pagination) => Array.from(
            pagination?.querySelector('.fi-pagination-items')?.children ?? [],
        ).filter((item) => item.matches('.fi-pagination-item'));
        const paginationItemRole = (item) => item.getAttribute('rel')
            ?? (item.dataset.mmrPaginationPlaceholder === 'previous'
                ? 'prev'
                : item.dataset.mmrPaginationPlaceholder === 'next'
                    ? 'next'
                    : null);
        const paginationItemLabel = (item) => item.querySelector('.fi-pagination-item-label')?.textContent.trim()
            ?? item.textContent.trim().replace(/\s+/g, ' ');
        const getRows = (content) => Array.from(content?.querySelectorAll('tbody > tr.fi-ta-row') ?? []);
        const getRowActionElements = (row) => Array.from(row.querySelectorAll(ROW_ACTION_SELECTOR));
        const getRowActionContainer = (row) => row.querySelector('.fi-ta-actions');

        const isRowActionDescriptor = (descriptor) => (
            !!descriptor
            && typeof descriptor.type === 'string'
            && typeof descriptor.color === 'string'
            && typeof descriptor.disabled === 'boolean'
            && Object.hasOwn(ROW_ACTION_DEFINITIONS, descriptor.type)
            && ROW_ACTION_COLORS.has(descriptor.color)
            && ((ROW_ACTION_DEFINITIONS[descriptor.type].disabled === true) === descriptor.disabled)
        );

        const getRowActionDescriptors = (row) => {
            const descriptors = getRowActionElements(row).map((action) => {
                const type = action.dataset.mmrSwrRowAction;
                const definition = ROW_ACTION_DEFINITIONS[type];

                return {
                    type,
                    color: action.dataset.mmrSwrRowActionColor,
                    disabled: definition?.disabled === true
                        || action.disabled
                        || action.classList.contains('fi-disabled'),
                };
            });

            return descriptors.every(isRowActionDescriptor) ? descriptors : null;
        };

        const actionDescriptorsMatch = (current, cached) => Array.isArray(current)
            && Array.isArray(cached)
            && current.length === cached.length
            && current.every((action, index) => action.type === cached[index]?.type
                && action.color === cached[index]?.color
                && action.disabled === cached[index]?.disabled);

        const createProjectedRowAction = (descriptor) => {
            const action = document.createElement('button');
            action.type = 'button';
            action.className = 'fi-icon-btn fi-ac-icon-btn-action fi-size-sm mmr-row-action'
                +(descriptor.disabled ? ' fi-disabled' : '');
            action.dataset.mmrSwrRowAction = descriptor.type;
            action.dataset.mmrSwrRowActionColor = descriptor.color;
            action.dataset.mmrSwrActionProjection = descriptor.type;
            action.setAttribute('aria-hidden', 'true');
            action.tabIndex = -1;
            if (descriptor.disabled) {
                action.disabled = true;
            }

            const icon = document.createElement('span');
            icon.className = 'fi-icon fi-size-xl mmr-row-action-icon';
            icon.setAttribute('aria-hidden', 'true');
            action.append(icon);

            return action;
        };

        const projectRowActions = (content, projection) => {
            const rows = getRows(content);

            for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                const row = rows[rowIndex];
                const cachedActions = projection.rows[rowIndex].actions;
                const container = getRowActionContainer(row);

                if (cachedActions.length === 0) {
                    if (container && !actionDescriptorsMatch(getRowActionDescriptors(row), cachedActions)) {
                        container.replaceChildren();
                    }

                    continue;
                }

                if (!container) {
                    return false;
                }

                if (actionDescriptorsMatch(getRowActionDescriptors(row), cachedActions)) {
                    continue;
                }

                const projectedActions = document.createDocumentFragment();
                cachedActions.forEach((descriptor) => projectedActions.append(createProjectedRowAction(descriptor)));
                container.replaceChildren(projectedActions);
            }

            return true;
        };

        const getController = (wrapper) => {
            let controller = controllers.get(wrapper);

            if (!controller) {
                controller = {
                    holding: false,
                    holdKey: null,
                    heldContent: null,
                    heldPagination: null,
                    heldTable: null,
                    clearAfterMorph: false,
                    scrollTimer: null,
                    lastRenderedView: null,
                    pendingLoad: null,
                };
                controllers.set(wrapper, controller);
                bindScrollTracking(wrapper, controller);
            }

            return controller;
        };

        // Color classes are state, not cell structure. Ignoring them lets an
        // Installed source badge change color without incorrectly rejecting a
        // structurally identical cached row.
        const structuralClasses = (element) => Array.from(element.classList)
            .filter((className) => className !== 'fi-color' && !className.startsWith('fi-color-'))
            .sort();

        const shapeSignature = (root) => {
            const parts = [];

            const visit = (element) => {
                // Record URLs and the Modrinth author URL wrap otherwise
                // identical text in <a> elements. Those links are inert while
                // stale, so they are not part of the display-value structure.
                const isTransparentWrapper = element.matches('a, .fi-ta-col');

                if (!isTransparentWrapper) {
                    parts.push(`<${element.tagName.toLowerCase()}.${structuralClasses(element).join('.')}>`);
                }

                Array.from(element.children).forEach(visit);

                if (!isTransparentWrapper) {
                    parts.push('</>');
                }
            };

            visit(root);

            return parts.join('');
        };

        const textNodes = (root) => {
            const nodes = [];
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            let node = walker.nextNode();

            while (node) {
                const parent = node.parentElement;

                if (
                    parent
                    && !parent.closest('svg, script, style')
                    && node.nodeValue.trim() !== ''
                ) {
                    nodes.push(node);
                }

                node = walker.nextNode();
            }

            return nodes;
        };

        const safeImage = (image) => {
            const source = image.getAttribute('src');

            if (!source) {
                return { src: null, alt: image.getAttribute('alt') ?? '' };
            }

            if (source === PROJECT_ICON_PLACEHOLDER_DATA_URI) {
                return { src: source, alt: image.getAttribute('alt') ?? '' };
            }

            try {
                const url = new URL(source, window.location.origin);

                // Do not write signed or credential-bearing image URLs into
                // sessionStorage. Two public source CDNs use stable numeric
                // cache-busting/size parameters, so allow only those exact
                // shapes rather than rejecting an entire table projection.
                if (
                    !['http:', 'https:'].includes(url.protocol)
                    || url.username
                    || url.password
                    || url.hash
                ) {
                    return null;
                }

                if (url.search && !isSafePublicImageQuery(url)) {
                    return null;
                }

                return { src: url.href, alt: image.getAttribute('alt') ?? '' };
            } catch (_error) {
                return null;
            }
        };

        const isSafePublicImageQuery = (url) => {
            if (url.protocol !== 'https:' || url.port) {
                return false;
            }

            const parameters = Array.from(url.searchParams.entries());
            const numeric = (value) => /^\d+$/.test(value);

            if (url.hostname === 'hangarcdn.papermc.io') {
                return parameters.length === 1
                    && parameters[0][0] === 'v'
                    && numeric(parameters[0][1]);
            }

            if (url.hostname === 'avatars.githubusercontent.com') {
                const names = new Set();

                return parameters.length > 0
                    && parameters.every(([name, value]) => {
                        if (!['v', 's'].includes(name) || names.has(name) || !numeric(value)) {
                            return false;
                        }

                        names.add(name);

                        return true;
                    });
            }

            return false;
        };

        const badgeColors = (badge) => Array.from(badge.classList)
            .filter((className) => className === 'fi-color' || className.startsWith('fi-color-'))
            .sort();

        const captureCell = (cell) => {
            const images = Array.from(cell.querySelectorAll('img')).map(safeImage);

            if (images.some((image) => image === null)) {
                return null;
            }

            return {
                name: cell.dataset.mmrSwrCell,
                signature: shapeSignature(cell),
                text: textNodes(cell).map((node) => node.nodeValue),
                images,
                badges: Array.from(cell.querySelectorAll('.fi-badge')).map(badgeColors),
            };
        };

        const captureProjection = (content) => {
            const rows = getRows(content);

            if (rows.length === 0) {
                return null;
            }

            const projectedRows = rows.map((row) => {
                const cells = Array.from(row.querySelectorAll(CELL_SELECTOR)).map(captureCell);
                const actions = getRowActionDescriptors(row);

                if (cells.length === 0 || cells.some((cell) => cell === null) || actions === null) {
                    return null;
                }

                return {
                    cells,
                    // Cache only stable action type/color descriptors. HTML is
                    // intentionally never retained or restored from storage.
                    actions,
                };
            });

            if (projectedRows.some((row) => row === null)) {
                return null;
            }

            const firstRow = rows[0];

            return {
                rowCount: rows.length,
                // This catches a column-manager mutation before per-row work
                // starts, even when it happened without changing the cache key.
                columns: Array.from(firstRow.querySelectorAll(CELL_SELECTOR))
                    .map((cell) => cell.dataset.mmrSwrCell)
                    .join('|'),
                rows: projectedRows,
            };
        };

        const applyBadgeColors = (badge, colors) => {
            Array.from(badge.classList)
                .filter((className) => className === 'fi-color' || className.startsWith('fi-color-'))
                .forEach((className) => badge.classList.remove(className));
            colors.forEach((className) => badge.classList.add(className));
        };

        const applyCell = (cell, projection) => {
            if (
                cell.dataset.mmrSwrCell !== projection.name
                || shapeSignature(cell) !== projection.signature
            ) {
                return false;
            }

            const currentTextNodes = textNodes(cell);
            const currentImages = Array.from(cell.querySelectorAll('img'));
            const currentBadges = Array.from(cell.querySelectorAll('.fi-badge'));

            if (
                currentTextNodes.length !== projection.text.length
                || currentImages.length !== projection.images.length
                || currentBadges.length !== projection.badges.length
            ) {
                return false;
            }

            currentTextNodes.forEach((node, index) => {
                node.nodeValue = projection.text[index];
            });

            currentImages.forEach((image, index) => {
                const cachedImage = projection.images[index];
                const currentSrc = image.getAttribute('src');

                if (cachedImage.src) {
                    if (currentSrc !== cachedImage.src) {
                        image.setAttribute('src', cachedImage.src);
                    }
                } else if (currentSrc) {
                    image.removeAttribute('src');
                }

                image.setAttribute('alt', cachedImage.alt);
            });

            currentBadges.forEach((badge, index) => applyBadgeColors(badge, projection.badges[index]));

            return true;
        };

        const projectionFailure = (reason, detail = {}) => ({ ok: false, reason, detail });

        const canApplyProjection = (content, projection, withDiagnostics = false) => {
            const rows = getRows(content);

            if (
                !projection
                || !Array.isArray(projection.rows)
                || rows.length !== projection.rowCount
                || rows.length !== projection.rows.length
            ) {
                return projectionFailure(
                    'row-count-mismatch',
                    withDiagnostics
                        ? {
                            currentRowCount: rows.length,
                            cachedRowCount: projection?.rowCount ?? null,
                            cachedRowsLength: Array.isArray(projection?.rows) ? projection.rows.length : null,
                        }
                        : {},
                );
            }

            const columns = Array.from(rows[0]?.querySelectorAll(CELL_SELECTOR) ?? [])
                .map((cell) => cell.dataset.mmrSwrCell)
                .join('|');

            if (columns !== projection.columns) {
                return projectionFailure(
                    'column-order-mismatch',
                    withDiagnostics
                        ? {
                            currentColumns: columns,
                            cachedColumns: projection.columns,
                        }
                        : {},
                );
            }

            for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                const row = rows[rowIndex];
                const rowProjection = projection.rows[rowIndex];
                const cells = Array.from(row.querySelectorAll(CELL_SELECTOR));

                if (!rowProjection || !Array.isArray(rowProjection.cells) || cells.length !== rowProjection.cells.length) {
                    return projectionFailure(
                        'cell-count-mismatch',
                        withDiagnostics
                            ? {
                                row: rowIndex + 1,
                                currentCellCount: cells.length,
                                cachedCellCount: Array.isArray(rowProjection?.cells) ? rowProjection.cells.length : null,
                            }
                            : {},
                    );
                }

                if (!Array.isArray(rowProjection.actions) || !rowProjection.actions.every(isRowActionDescriptor)) {
                    return projectionFailure(
                        'row-actions-invalid',
                        withDiagnostics
                            ? {
                                row: rowIndex + 1,
                                cachedActions: rowProjection?.actions ?? null,
                            }
                            : {},
                    );
                }

                if (rowProjection.actions.length > 0 && !getRowActionContainer(row)) {
                    return projectionFailure(
                        'row-actions-container-missing',
                        withDiagnostics ? { row: rowIndex + 1 } : {},
                    );
                }

                for (let cellIndex = 0; cellIndex < cells.length; cellIndex++) {
                    const cell = cells[cellIndex];
                    const cellProjection = rowProjection.cells[cellIndex];

                    if (!cellProjection) {
                        return projectionFailure(
                            'cell-projection-missing',
                            withDiagnostics
                                ? {
                                    row: rowIndex + 1,
                                    cellIndex: cellIndex + 1,
                                    currentCell: cell.dataset.mmrSwrCell ?? null,
                                }
                                : {},
                        );
                    }

                    const currentName = cell.dataset.mmrSwrCell ?? null;
                    const currentSignature = shapeSignature(cell);
                    const currentTextNodeCount = textNodes(cell).length;
                    const currentImageCount = cell.querySelectorAll('img').length;
                    const currentBadgeCount = cell.querySelectorAll('.fi-badge').length;
                    const cachedName = cellProjection.name ?? null;
                    const cachedSignature = cellProjection.signature ?? null;
                    const cachedTextNodeCount = Array.isArray(cellProjection.text) ? cellProjection.text.length : null;
                    const cachedImageCount = Array.isArray(cellProjection.images) ? cellProjection.images.length : null;
                    const cachedBadgeCount = Array.isArray(cellProjection.badges) ? cellProjection.badges.length : null;

                    if (
                        currentName !== cachedName
                        || currentSignature !== cachedSignature
                        || currentTextNodeCount !== cachedTextNodeCount
                        || currentImageCount !== cachedImageCount
                        || currentBadgeCount !== cachedBadgeCount
                    ) {
                        return projectionFailure(
                            'cell-shape-mismatch',
                            withDiagnostics
                                ? {
                                    row: rowIndex + 1,
                                    cellIndex: cellIndex + 1,
                                    cell: currentName,
                                    current: {
                                        name: currentName,
                                        signature: currentSignature,
                                        textNodeCount: currentTextNodeCount,
                                        imageCount: currentImageCount,
                                        badgeCount: currentBadgeCount,
                                    },
                                    cached: {
                                        name: cachedName,
                                        signature: cachedSignature,
                                        textNodeCount: cachedTextNodeCount,
                                        imageCount: cachedImageCount,
                                        badgeCount: cachedBadgeCount,
                                    },
                                }
                                : {},
                        );
                    }
                }
            }

            return { ok: true };
        };

        const applyProjection = (content, projection, withDiagnostics = false) => {
            const compatibility = canApplyProjection(content, projection, withDiagnostics);

            if (!compatibility.ok) {
                return compatibility;
            }

            const rows = getRows(content);

            for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                const cells = Array.from(rows[rowIndex].querySelectorAll(CELL_SELECTOR));

                for (let cellIndex = 0; cellIndex < cells.length; cellIndex++) {
                    if (!applyCell(cells[cellIndex], projection.rows[rowIndex].cells[cellIndex])) {
                        return projectionFailure('cell-apply-mismatch', {
                            row: rowIndex + 1,
                            cellIndex: cellIndex + 1,
                            cell: cells[cellIndex].dataset.mmrSwrCell ?? null,
                        });
                    }
                }
            }

            return { ok: true };
        };

        // The paginator itself remains Filament's real DOM while a cached table
        // is held. Cache only the small amount of state that can safely be
        // projected into that existing node: its overview text and which page
        // item is current. The item identity check prevents us from relabeling
        // a paginator whose page-window has genuinely changed.
        const capturePaginationProjection = (pagination) => {
            const overview = pagination?.querySelector('.fi-pagination-overview');
            const items = getPaginationItems(pagination);

            if (!overview || items.length === 0) {
                return null;
            }

            return {
                overview: overview.textContent.trim(),
                items: items.map((item) => ({
                    role: paginationItemRole(item),
                    label: paginationItemLabel(item),
                    active: item.classList.contains('fi-active'),
                })),
            };
        };

        const applyPaginationProjection = (pagination, projection) => {
            if (!pagination || !projection || !Array.isArray(projection.items)) {
                return { overviewApplied: false, stateApplied: false, reason: 'projection-missing' };
            }

            const overview = pagination.querySelector('.fi-pagination-overview');

            if (overview && typeof projection.overview === 'string') {
                // table-layout's normal refresh hook will reapply its safe
                // Japanese wrapping markup after this morph. Setting raw text
                // first makes the target count visible before fresh rows arrive.
                overview.textContent = projection.overview;
                delete overview.dataset.mmrPaginationOverview;
            }

            const items = getPaginationItems(pagination);

            if (
                items.length !== projection.items.length
                || items.some((item, index) => {
                    const target = projection.items[index];

                    return paginationItemRole(item) !== target.role
                        || paginationItemLabel(item) !== target.label;
                })
            ) {
                return {
                    overviewApplied: overview !== null,
                    stateApplied: false,
                    reason: 'item-structure-mismatch',
                };
            }

            items.forEach((item, index) => {
                const active = projection.items[index].active === true;
                const button = item.querySelector(':scope > button.fi-pagination-item-btn');

                item.classList.toggle('fi-active', active);

                if (button) {
                    if (active) {
                        button.setAttribute('aria-current', 'page');
                    } else {
                        button.removeAttribute('aria-current');
                    }
                }
            });

            return { overviewApplied: overview !== null, stateApplied: true, reason: null };
        };

        const capture = (wrapper, key) => {
            const content = getContent(wrapper);

            if (!content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const projection = captureProjection(content);

            if (!projection) {
                // Empty results and unsupported/mismatched layouts must never
                // replace a valid preview for a different cached condition.
                storage.remove(key);
                writeIndex(readIndex().filter((entry) => entry?.key !== key));

                return;
            }

            const pagination = capturePaginationProjection(getPagination(wrapper));
            const now = Date.now();

            saveEntry(key, {
                schema: SCHEMA_VERSION,
                digest: key.slice(STORAGE_PREFIX.length),
                createdAt: now,
                lastAccessedAt: now,
                expiresAt: now + TTL_MS,
                projection,
                pagination,
                scrollTop: content.scrollTop,
                scrollLeft: content.scrollLeft,
            });
        };

        const rememberScrollPosition = (wrapper, content) => {
            const wire = findWire(wrapper);

            if (!wire || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const key = buildKey(wrapper, wire);
            const entry = loadEntry(key);

            if (!entry) {
                return;
            }

            entry.scrollTop = content.scrollTop;
            entry.scrollLeft = content.scrollLeft;
            entry.lastAccessedAt = Date.now();
            saveEntry(key, entry);
        };

        const bindScrollTracking = (wrapper, controller) => {
            wrapper.addEventListener('scroll', (event) => {
                const content = event.target;

                if (
                    controller.holding
                    || !(content instanceof HTMLElement)
                    || !content.matches('.fi-ta-content-ctn')
                ) {
                    return;
                }

                window.clearTimeout(controller.scrollTimer);
                controller.scrollTimer = window.setTimeout(
                    () => rememberScrollPosition(wrapper, content),
                    100,
                );
            }, true);
        };

        const setHeldState = (controller, active) => {
            [controller.heldContent, controller.heldPagination]
                .filter((element) => element?.isConnected)
                .forEach((element) => {
                    if (active) {
                        element.setAttribute('inert', '');
                        element.setAttribute('aria-busy', 'true');
                        element.dataset.mmrSwrStale = 'true';
                    } else {
                        element.removeAttribute('inert');
                        element.removeAttribute('aria-busy');
                        delete element.dataset.mmrSwrStale;
                    }
                });

            if (controller.heldTable?.isConnected) {
                if (active) {
                    // Filament adds fi-loading to its root whenever records are
                    // deferred. Its CSS pulses the entire table, which is useful
                    // for an uncached load but defeats a stable stale preview.
                    controller.heldTable.classList.remove('fi-loading');
                    controller.heldTable.dataset.mmrSwrStale = 'true';
                } else {
                    delete controller.heldTable.dataset.mmrSwrStale;
                }
            }

        };

        const stopHolding = (controller, clearImmediately = true) => {
            controller.holding = false;
            controller.holdKey = null;

            if (clearImmediately) {
                setHeldState(controller, false);
                heldContentControllers.delete(controller.heldContent);
                heldPaginationControllers.delete(controller.heldPagination);
                controller.heldContent = null;
                controller.heldPagination = null;
                controller.heldTable = null;
                controller.clearAfterMorph = false;
            } else {
                controller.clearAfterMorph = true;
            }
        };

        const holdCachedProjection = (wrapper) => {
            const controller = getController(wrapper);
            const content = getContent(wrapper);
            const wire = findWire(wrapper);
            const key = wire ? buildKey(wrapper, wire) : null;
            const debugActive = debugEnabled();
            const targetView = debugActive && wire ? describeViewState(wire) : null;
            const debugContext = debugActive
                ? {
                    id: ++debugSequence,
                    startedAt: window.performance?.now?.() ?? 0,
                    targetView,
                    transition: describeTransition(controller.lastRenderedView, targetView),
                    cacheKey: cacheKeyDigest(key),
                }
                : null;

            if (debugContext) {
                controller.pendingLoad = debugContext;
                debugLog('deferred-load-started', {
                    request: debugContext.id,
                    transition: debugContext.transition,
                    target: debugContext.targetView,
                    cacheKey: debugContext.cacheKey,
                });
            }

            const reject = (reason, detail = {}) => {
                stopHolding(controller);
                debugLog('cached-projection-rejected', {
                    request: debugContext?.id ?? null,
                    reason,
                    detail,
                    transition: debugContext?.transition ?? null,
                    target: debugContext?.targetView ?? targetView,
                    cacheKey: debugContext?.cacheKey ?? cacheKeyDigest(key),
                });

                return false;
            };

            if (!content) {
                return reject('content-missing');
            }

            if (!wire) {
                return reject('wire-missing');
            }

            if (!key) {
                return reject('cache-key-missing');
            }

            if (content.querySelector('.fi-ta-table-loading-ctn')) {
                return reject('current-content-loading');
            }

            if (controller.holding && controller.holdKey === key) {
                if (debugContext) {
                    debugContext.held = true;
                }

                debugLog('cached-projection-already-held', {
                    request: debugContext?.id ?? null,
                    transition: debugContext?.transition ?? null,
                    cacheKey: cacheKeyDigest(key),
                });

                return true;
            }

            stopHolding(controller);

            const cache = inspectCache(key);

            if (!cache.available) {
                return reject(cache.reason, cache);
            }

            const entry = loadEntry(key);

            if (!entry) {
                return reject('cache-entry-invalid');
            }

            const applied = applyProjection(content, entry.projection, debugActive);

            if (!applied.ok) {
                return reject(applied.reason, applied.detail);
            }

            // Unlike the prior blank fallback, render target action descriptors
            // directly into the existing action region. The cache holds only
            // type/color values; the fresh Filament response still replaces this
            // non-interactive projection with the authoritative action DOM.
            if (!projectRowActions(content, entry.projection)) {
                return reject('row-actions-projection-failed');
            }

            const pagination = getPagination(wrapper);
            const paginationProjection = applyPaginationProjection(pagination, entry.pagination);

            content.scrollTop = Number(entry.scrollTop || 0);
            content.scrollLeft = Number(entry.scrollLeft || 0);
            controller.holding = true;
            controller.holdKey = key;
            controller.heldContent = content;
            controller.heldPagination = pagination;
            controller.heldTable = content.closest('.fi-ta');
            heldContentControllers.set(content, controller);

            if (pagination) {
                heldPaginationControllers.set(pagination, controller);
            }

            setHeldState(controller, true);

            // Nothing to re-measure: the row viewport's height is fixed in
            // CSS, so projected values cannot resize it however differently
            // they wrap, and the paginator keeps its place inside that box.

            if (debugContext) {
                debugContext.held = true;
            }

            debugLog('cached-projection-held', {
                request: debugContext?.id ?? null,
                transition: debugContext?.transition ?? null,
                target: debugContext?.targetView ?? targetView,
                cacheKey: cacheKeyDigest(key),
                rowCount: entry.projection.rowCount,
                paginationOverviewApplied: paginationProjection.overviewApplied,
                paginationStateApplied: paginationProjection.stateApplied,
                paginationProjectionReason: paginationProjection.reason,
            });

            return true;
        };

        const wrappersIn = (root) => {
            if (!(root instanceof Element)) {
                return [];
            }

            return [
                ...(root.matches(WRAPPER_SELECTOR) ? [root] : []),
                ...root.querySelectorAll(WRAPPER_SELECTOR),
            ];
        };

        const isIncomingLoading = (toEl) => toEl?.querySelector?.('.fi-ta-table-loading-ctn') !== null;

        const prepareMorph = (root, toEl) => {
            const loading = isIncomingLoading(toEl);

            wrappersIn(root).forEach((wrapper) => {
                const controller = getController(wrapper);

                if (loading) {
                    holdCachedProjection(wrapper);
                } else if (controller.holding) {
                    // Do not skip the fresh response. Keeping the inert marker
                    // through this synchronous morph avoids re-enabling stale
                    // row actions for even one paint.
                    stopHolding(controller, false);
                }
            });
        };

        const skipHeldContentUpdate = (element) => heldContentControllers.get(element)?.holding === true;

        const skipHeldPaginationRemoval = (element) => heldPaginationControllers.get(element)?.holding === true;

        const finishMorph = (root) => {
            wrappersIn(root).forEach((wrapper) => {
                const controller = getController(wrapper);

                if (controller.clearAfterMorph) {
                    setHeldState(controller, false);
                    heldContentControllers.delete(controller.heldContent);
                    heldPaginationControllers.delete(controller.heldPagination);
                    controller.heldContent = null;
                    controller.heldPagination = null;
                    controller.heldTable = null;
                    controller.clearAfterMorph = false;
                } else if (controller.holding) {
                    // The intermediate response may have restored fi-loading on
                    // the table root. Remove it before the browser can paint.
                    setHeldState(controller, true);
                }

            });

        };

        const processWrapper = (wrapper) => {
            if (!(wrapper instanceof HTMLElement) || !wrapper.matches(WRAPPER_SELECTOR)) {
                return;
            }

            const controller = getController(wrapper);

            if (controller.holding) {
                return;
            }

            const wire = findWire(wrapper);
            const content = getContent(wrapper);

            if (!wire || !content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const key = buildKey(wrapper, wire);
            const pendingLoad = controller.pendingLoad;

            if (pendingLoad) {
                debugLog('deferred-load-finished', {
                    request: pendingLoad.id,
                    durationMs: Math.round((window.performance?.now?.() ?? 0) - pendingLoad.startedAt),
                    heldCachedProjection: pendingLoad.held === true,
                    transition: pendingLoad.transition,
                    activeTab: getWireValue(wire, 'activeTab', null),
                    paginators: normalize(getWireValue(wire, 'paginators', {})) ?? {},
                    rowCount: getRows(content).length,
                    empty: content.querySelector('.fi-ta-empty-state') !== null,
                });
                controller.pendingLoad = null;
            }

            controller.lastRenderedView = debugEnabled() ? describeViewState(wire) : null;

            // The Installed tab's icon/downloads/date_modified enrichment is
            // deliberately non-blocking (see ModManagerPage::pollEnrichment()):
            // this exact morph can be a real, complete render where those
            // fields are still the PROJECT_ICON_PLACEHOLDER_DATA_URI placeholder because
            // the background fetch hasn't landed yet, not a loading state
            // .fi-ta-table-loading-ctn would catch. Caching that placeholder
            // snapshot here would make it the "last known good" projection
            // replayed by holdCachedProjection() on every later navigation
            // until this exact component instance happens to re-render after
            // enrichment finishes and overwrites it - which is what made
            // Installed-tab icons stay blank until leaving the page and
            // coming back started a fresh component (and a fresh, already-
            // resolved records() read). Skipping the write here instead
            // leaves whichever snapshot was last captured while nothing was
            // pending in place, so a stale-but-complete preview keeps being
            // held rather than a fresher-but-incomplete one replacing it.
            const enrichmentPending = getWireValue(wire, 'pollEnrichment', false) === true;

            if (getRows(content).length > 0) {
                if (!enrichmentPending) {
                    capture(wrapper, key);
                }
            } else if (content.querySelector('.fi-ta-empty-state')) {
                storage.remove(key);
                writeIndex(readIndex().filter((entry) => entry?.key !== key));
            }
        };

        const scan = (context = null) => {
            if (context) {
                pendingScanContexts.add(context);
            } else {
                contexts.forEach((active) => pendingScanContexts.add(active));
            }

            if (pendingScanContexts.size === 0 || scanFrame !== null) {
                return;
            }

            // All callbacks produced by one morph share one capture frame.
            scanFrame = requestAnimationFrame(() => {
                scanFrame = null;
                const scheduled = [...pendingScanContexts];
                pendingScanContexts.clear();

                scheduled.forEach((active) => {
                    if (!active.component.isConnected) {
                        return;
                    }

                    wrappersIn(active.component).forEach(processWrapper);
                });
            });
        };

        const registerMorphHooks = () => {
            if (morphUnsubscribes.length > 0 || typeof window.Livewire?.hook !== 'function') {
                return;
            }

            const register = (name, callback) => {
                const unsubscribe = window.Livewire.hook(name, callback);

                if (typeof unsubscribe === 'function') {
                    morphUnsubscribes.push(unsubscribe);
                }
            };

            // Livewire merges the incoming snapshot before this hook, so
            // buildKey() reads the view being opened, not the one being left.
            register('morph', ({ component, el, toEl }) => {
                if (activeComponents.has(component?.el)) {
                    prepareMorph(el, toEl);
                }
            });

            register('morph.updating', ({ component, el, toEl, skip }) => {
                if (!activeComponents.has(component?.el)) {
                    return;
                }

                if (skipHeldContentUpdate(el)) {
                    skip();

                    return;
                }

                // Replacing an <img> whose src did not change restarts decode
                // even when the bytes are cached. Skip that node so poll/morph
                // cannot make the same icon flash or re-request.
                if (
                    el instanceof HTMLImageElement
                    && toEl instanceof HTMLImageElement
                    && el.matches('.mmr-table-scroll-ctn .mmr-project-icon-cell .fi-ta-image img')
                    && el.getAttribute('src') === toEl.getAttribute('src')
                ) {
                    skip();
                }
            });

            // Filament omits pagination while records are deferred. Preserve the
            // existing real navigation node during that one intermediate morph;
            // it is normally morphed again when fresh records arrive.
            register('morph.removing', ({ component, el, skip }) => {
                if (activeComponents.has(component?.el) && skipHeldPaginationRemoval(el)) {
                    skip();
                }
            });

            register('morphed', ({ component, el }) => {
                const context = contextByComponent.get(component?.el);

                if (!context || !activeComponents.has(component?.el)) {
                    return;
                }

                finishMorph(el);
                scan(context);
            });
        };

        const mutationTouchesWrapper = (mutation) => {
            const target = mutation.target;

            if (target instanceof Element && target.closest(WRAPPER_SELECTOR)) {
                return true;
            }

            return Array.from(mutation.addedNodes).some((node) => node instanceof Element
                && (node.matches(WRAPPER_SELECTOR) || node.querySelector(WRAPPER_SELECTOR)));
        };

        const componentFor = (wrapper) => wrapper.closest('[wire\\:id]') ?? wrapper;

        const discoverContexts = () => {
            document.querySelectorAll(WRAPPER_SELECTOR).forEach((wrapper) => {
                const component = componentFor(wrapper);
                const existing = contextByComponent.get(component);

                if (existing && contexts.has(existing)) {
                    return;
                }

                const context = {
                    component,
                    observer: new MutationObserver((mutations) => {
                        if (mutations.some(mutationTouchesWrapper)) {
                            scan(context);
                        }
                    }),
                };

                contextByComponent.set(component, context);
                activeComponents.add(component);
                contexts.add(context);
                context.observer.observe(component, { childList: true, subtree: true });
            });
        };

        const activate = () => {
            discoverContexts();

            if (contexts.size === 0) {
                return;
            }

            prune();
            registerMorphHooks();
            scan();
        };

        const deactivate = () => {
            morphUnsubscribes.splice(0).forEach((unsubscribe) => unsubscribe());
            contexts.forEach((context) => context.observer.disconnect());
            contexts.clear();
            pendingScanContexts.clear();

            if (scanFrame !== null) {
                cancelAnimationFrame(scanFrame);
                scanFrame = null;
            }
        };

        window.__mmrTableSwrCacheV9 = { scan, activate, deactivate };
        document.addEventListener('livewire:init', activate);
        document.addEventListener('livewire:navigating', deactivate);
        document.addEventListener('livewire:navigated', activate);
        activate();
    })();
