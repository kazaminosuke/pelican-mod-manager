@php
    $initial = \Kazaminosuke\ModManager\Support\RequestPerformanceProfiler::snapshot();
@endphp
<div id="mmr-perf" hidden>
    <div class="mmr-perf-head">
        <strong>Mod Manager Profiler</strong>
        <span>
            <button type="button" id="mmr-perf-copy">Copy JSON</button>
            <button type="button" id="mmr-perf-copy-text">Copy text</button>
            <button type="button" id="mmr-perf-clear">Clear</button>
            <button type="button" id="mmr-perf-toggle">Hide</button>
        </span>
    </div>
    <pre id="mmr-perf-current"></pre>
    <ol id="mmr-perf-history"></ol>
</div>
<style>
    #mmr-perf{position:fixed;right:12px;bottom:12px;z-index:80;width:22rem;max-height:70vh;overflow:auto;padding:8px 10px;border-radius:8px;background:rgba(15,23,42,.92);color:#e2e8f0;font:11px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;box-shadow:0 8px 24px rgba(0,0,0,.35)}
    #mmr-perf.mmr-perf-collapsed{max-height:none;overflow:visible}
    #mmr-perf .mmr-perf-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:6px}
    #mmr-perf button{font:inherit;color:#cbd5e1;background:transparent;border:1px solid #475569;border-radius:4px;padding:1px 5px;cursor:pointer}
    #mmr-perf pre{white-space:pre-wrap;margin:0 0 8px}
    #mmr-perf ol{margin:0;padding-left:1.1rem}
    #mmr-perf li{margin:0 0 6px}
</style>
<script data-navigate-once>
    (() => {
        'use strict';

        if (window.__mmrPerfProfiler) {
            return;
        }

        window.__mmrPerfProfiler = true;

        const HISTORY_KEY = 'mmrPerfHistory';
        const MAX_HISTORY = 25;
        const POLL_METHODS = new Set(['pollInstalledOperation', 'pollEnrichment']);
        const IMAGE_SELECTOR = '.mmr-table-scroll-ctn .mmr-project-icon-cell .fi-ta-image img';
        const initial = @json($initial);
        const panel = document.getElementById('mmr-perf');
        const currentEl = document.getElementById('mmr-perf-current');
        const historyEl = document.getElementById('mmr-perf-history');
        const pending = new Map();
        let lastClickAt = 0;
        let seq = 0;

        const now = () => performance.now();
        const kb = (bytes) => bytes == null ? 'n/a' : `${(bytes / 1024).toFixed(1)}KB`;

        const storageGet = () => {
            try {
                return JSON.parse(window.sessionStorage.getItem(HISTORY_KEY) || '[]');
            } catch (_error) {
                return [];
            }
        };

        const storageSet = (entries) => {
            try {
                window.sessionStorage.setItem(HISTORY_KEY, JSON.stringify(entries.slice(0, MAX_HISTORY)));
            } catch (_error) {
                // Ignore quota / private-mode failures.
            }
        };

        let history = storageGet();

        const summary = (entry) => [
            `Source: ${entry.source ?? 'n/a'}`,
            `Page: ${entry.page ?? 'n/a'}`,
            `Cache: ${entry.cache ?? 'NONE'}`,
            `API: ${entry.api_ms ?? 0}ms`,
            `PHP: ${entry.php_ms ?? 0}ms`,
            `Livewire: ${entry.livewire_ms ?? 'n/a'}ms`,
            `Morph: ${entry.morph_ms ?? 'n/a'}ms`,
            `Usable: ${entry.click_to_usable_ms ?? 'n/a'}ms`,
            `effects.html: ${kb(entry.effects_html_bytes)}`,
            `Images 8: ${entry.images_8_ms ?? 'n/a'}ms`,
            `Images 20: ${entry.images_20_ms ?? 'n/a'}ms`,
        ].join('\n');

        const render = (current) => {
            if (!panel) {
                return;
            }

            panel.hidden = false;
            if (currentEl && !panel.classList.contains('mmr-perf-collapsed')) {
                currentEl.textContent = current ? summary(current) : 'Waiting for a catalog operation…';
            }
            if (historyEl && !panel.classList.contains('mmr-perf-collapsed')) {
                historyEl.replaceChildren(...history.slice(0, MAX_HISTORY).map((entry) => {
                    const item = document.createElement('li');
                    const method = entry.method ? ` ${entry.method}` : '';
                    item.textContent = `${entry.source ?? '?'} p${entry.page ?? '?'} ${entry.cache ?? 'NONE'}${method} usable=${entry.click_to_usable_ms ?? 'n/a'}ms lw=${entry.livewire_ms ?? 'n/a'}ms`;

                    return item;
                }));
            }
        };

        const writeClipboard = async (text) => {
            try {
                await navigator.clipboard.writeText(text);
            } catch (_error) {
                const area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }
        };

        document.getElementById('mmr-perf-copy')?.addEventListener('click', () => {
            writeClipboard(JSON.stringify(history, null, 2));
        });
        document.getElementById('mmr-perf-copy-text')?.addEventListener('click', () => {
            writeClipboard(history.map((entry) => summary(entry)).join('\n\n---\n\n'));
        });
        document.getElementById('mmr-perf-clear')?.addEventListener('click', () => {
            history = [];
            storageSet(history);
            render(null);
        });
        document.getElementById('mmr-perf-toggle')?.addEventListener('click', (event) => {
            const collapsed = panel.classList.toggle('mmr-perf-collapsed');
            if (currentEl) {
                currentEl.hidden = collapsed;
            }
            if (historyEl) {
                historyEl.hidden = collapsed;
            }
            event.currentTarget.textContent = collapsed ? 'Show' : 'Hide';
        });

        const isCatalogTarget = (target) => target instanceof Element && Boolean(
            target.closest('.fi-tabs, .fi-pagination, #mmr-catalog-sort, .mmr-table-scroll-ctn, .fi-ta-search-field'),
        );

        document.addEventListener('pointerdown', (event) => {
            if (isCatalogTarget(event.target)) {
                lastClickAt = now();
            }
        }, true);

        const isModManager = (component) => Boolean(component?.el?.querySelector?.('.mmr-table-scroll-ctn'));

        const livewireBytes = () => {
            const entries = performance.getEntriesByType('resource')
                .filter((entry) => typeof entry.name === 'string' && entry.name.includes('/livewire/update'));
            const last = entries.at(-1);

            return last ? (last.transferSize || last.encodedBodySize || last.decodedBodySize || null) : null;
        };

        const catalogUsable = () => {
            const root = document.querySelector('.mmr-table-scroll-ctn');

            return Boolean(root?.querySelector('.fi-ta-row, .fi-ta-empty-state'));
        };

        const finish = (op) => {
            if (op.finished) {
                return;
            }

            op.finished = true;
            op.click_to_livewire_ms = op.livewire_response_at != null && op.click_at != null
                ? Math.round(op.livewire_response_at - op.click_at)
                : null;
            op.livewire_to_morph_ms = op.morph_end_at != null && op.livewire_response_at != null
                ? Math.round(op.morph_end_at - op.livewire_response_at)
                : null;
            op.click_to_usable_ms = op.usable_at != null && op.click_at != null
                ? Math.round(op.usable_at - op.click_at)
                : null;
            op.list_to_images_8_ms = op.images_8_ms ?? null;
            op.list_to_images_20_ms = op.images_20_ms ?? null;

            history = [op, ...history.filter((entry) => entry.request_id !== op.request_id)].slice(0, MAX_HISTORY);
            storageSet(history);
            render(op);
        };

        const waitImages = (op) => {
            const startAt = op.usable_at ?? now();

            const tick = () => {
                if (op.finished) {
                    return;
                }

                const images = [...document.querySelectorAll(IMAGE_SELECTOR)];
                const shown = images.filter((image) => image.complete).length;
                const elapsed = Math.round(now() - startAt);

                if (shown >= 1 && op.images_first_ms == null) {
                    op.images_first_ms = elapsed;
                }
                if (shown >= Math.min(8, images.length || 8) && op.images_8_ms == null) {
                    op.images_8_ms = elapsed;
                }
                if (shown >= Math.min(20, images.length || 20) && op.images_20_ms == null) {
                    op.images_20_ms = elapsed;
                }

                const expected = images.length;
                const done = expected === 0
                    || (shown >= expected && (expected < 8 || op.images_8_ms != null) && (expected < 20 || op.images_20_ms != null || shown >= expected));

                if (done || elapsed > 10000) {
                    if (expected === 0) {
                        op.images_first_ms = op.images_first_ms ?? 0;
                        op.images_8_ms = op.images_8_ms ?? 0;
                        op.images_20_ms = op.images_20_ms ?? 0;
                    } else if (expected < 8) {
                        op.images_8_ms = op.images_8_ms ?? elapsed;
                    }
                    if (expected > 0 && expected < 20) {
                        op.images_20_ms = op.images_20_ms ?? elapsed;
                    }
                    finish(op);

                    return;
                }

                requestAnimationFrame(tick);
            };

            document.querySelectorAll(IMAGE_SELECTOR).forEach((image) => {
                if (image.complete) {
                    return;
                }
                image.addEventListener('load', tick, { once: true });
                image.addEventListener('error', tick, { once: true });
            });
            tick();
        };

        const tryFinalize = (op) => {
            if (op.finished || !op.server || op.livewire_response_at == null) {
                return;
            }

            Object.assign(op, op.server);

            if (POLL_METHODS.has(op.method)) {
                op.finished = true;
                pending.delete(op.local_id);

                return;
            }

            if (op.usable_at == null && (op.morph_end_at != null || op.method === 'initial') && catalogUsable()) {
                op.usable_at = op.morph_end_at ?? now();
            }

            if (op.usable_at == null) {
                if (op.morph_end_at != null) {
                    finish(op);
                }

                return;
            }

            waitImages(op);
        };

        const opFor = (id) => {
            let op = pending.get(id);
            if (!op) {
                op = {
                    local_id: id,
                    click_at: lastClickAt > 0 ? lastClickAt : now(),
                    livewire_start_at: null,
                    livewire_response_at: null,
                    morph_start_at: null,
                    morph_end_at: null,
                    usable_at: null,
                    livewire_ms: null,
                    morph_ms: null,
                    livewire_bytes: null,
                    effects_html_bytes: null,
                    method: null,
                    server: null,
                };
                pending.set(id, op);
            }

            return op;
        };

        const bootLivewire = () => {
            if (!window.Livewire) {
                return;
            }

            Livewire.hook('request', ({ succeed }) => {
                const id = `r${++seq}`;
                const op = opFor(id);
                op.livewire_start_at = now();
                window.__mmrPerfActive = id;

                succeed(() => {
                    op.livewire_response_at = now();
                    op.livewire_ms = Math.round(op.livewire_response_at - op.livewire_start_at);
                    op.livewire_bytes = livewireBytes();
                    tryFinalize(op);
                });
            });

            Livewire.hook('commit', ({ component, commit, succeed }) => {
                if (!isModManager(component) || !window.__mmrPerfActive) {
                    return;
                }

                const op = opFor(window.__mmrPerfActive);
                const calls = Array.isArray(commit?.calls) ? commit.calls : [];
                op.method = calls.map((call) => call.method).filter(Boolean).join(',') || 'update';

                succeed(({ effects }) => {
                    const html = effects?.html;
                    op.effects_html_bytes = typeof html === 'string' ? new Blob([html]).size : 0;
                    if (POLL_METHODS.has(op.method)) {
                        op.finished = true;
                        pending.delete(op.local_id);

                        return;
                    }
                    tryFinalize(op);
                });
            });

            Livewire.hook('morph', ({ component }) => {
                if (!isModManager(component) || !window.__mmrPerfActive) {
                    return;
                }

                opFor(window.__mmrPerfActive).morph_start_at = now();
            });

            Livewire.hook('morphed', ({ component }) => {
                if (!isModManager(component) || !window.__mmrPerfActive) {
                    return;
                }

                const op = opFor(window.__mmrPerfActive);
                op.morph_end_at = now();
                op.morph_ms = op.morph_start_at != null ? Math.round(op.morph_end_at - op.morph_start_at) : null;
                requestAnimationFrame(() => tryFinalize(op));
            });

            Livewire.on('mmr-profiler-server', (payload) => {
                const snapshot = payload?.snapshot ?? payload?.[0]?.snapshot ?? payload;
                if (!snapshot || typeof snapshot !== 'object') {
                    return;
                }

                const id = window.__mmrPerfActive;
                const op = id ? opFor(id) : opFor(`s${++seq}`);
                op.server = snapshot;
                tryFinalize(op);
            });
        };

        if (window.Livewire) {
            bootLivewire();
        } else {
            document.addEventListener('livewire:init', bootLivewire, { once: true });
        }

        if (initial?.request_id) {
            const op = opFor('initial');
            op.method = 'initial';
            op.click_at = 0;
            op.livewire_response_at = now();
            op.morph_end_at = now();
            op.server = initial;
            tryFinalize(op);
        } else {
            render(history[0] ?? null);
        }
    })();
</script>
