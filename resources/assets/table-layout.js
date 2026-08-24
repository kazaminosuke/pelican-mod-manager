    (() => {
        'use strict';

        // Everything about the table's vertical layout is expressed in CSS
        // (see mod-manager.css): .fi-ta-main is
        // a fixed-height flex column, the row viewport takes the slack, and
        // the paginator is the last auto-sized item in that column. The three
        // numbers CSS cannot derive on its own are all this script supplies.
        //
        // Every one of them is measured so that (a) it does not depend on how
        // far the page is scrolled and (b) applying the result cannot change
        // what the next measurement sees. Both properties matter: the version
        // this replaced derived the row viewport from document.scrollHeight
        // and window.scrollY, so the answer moved with the scroll position and
        // each pass fed the next. Because these readings are idempotent, how
        // many things trigger a re-measure stops being a correctness concern.
        const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn';
        const DEBUG_STORAGE_KEY = 'mmrSwrDebug';

        if (window.__mmrTableLayout) {
            window.__mmrTableLayout.activate('re-execute');

            return;
        }

        const root = document.documentElement;

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

            console.info(`[mmr-layout +${Math.round(window.performance?.now?.() ?? 0)}ms] ${event}`, detail);
        };

        // The page's trailing chrome below the table - Filament's page
        // container carries a bottom padding, and a panel may add more.
        // Guessing at it is what left the document 64px taller than the
        // viewport, so it is measured too.
        //
        // The catch is that .fi-body is min-h-dvh, so while the page fits, the
        // body's box is held open to the viewport and reading its bottom would
        // report the slack rather than the real trailing space - and a reading
        // that is too large shrinks the table, which keeps the page fitting,
        // which keeps the reading too large. Forcing the table tall for the
        // duration of the read takes that clamp out of the picture. It happens
        // within one task, so nothing is painted in between, and the document
        // ends up the size it started at, so the scroll position is untouched.
        const measureTrailingSpace = (target, viewport) => {
            const previousHeight = target.style.height;
            target.style.height = `${Math.max(viewport * 2, 2000)}px`;

            const space = Math.round(
                document.body.getBoundingClientRect().bottom - target.getBoundingClientRect().bottom,
            );

            target.style.height = previousHeight;

            return Math.max(space, 0);
        };

        const measure = (wrapper, caller = 'unknown') => {
            // Measure the element that actually carries the height. Reading
            // the wrapper instead would fold in whatever schema markup
            // Filament nests in between, which differs per Filament release.
            const target = wrapper.querySelector('.fi-ta-main') ?? wrapper;

            if (target.getClientRects().length === 0) {
                debugLog(`measure skipped (from: ${caller})`, { reason: 'not-rendered' });

                return;
            }

            // clientHeight, not innerHeight: it excludes a horizontal
            // scrollbar, and it is the same box the CSS viewport units resolve
            // against, so the arithmetic below stays exact.
            const viewport = root.clientHeight;
            const rect = target.getBoundingClientRect();

            // rect.top falls by exactly as much as scrollY rises, so this sum
            // is the same number whether the page sits at the top or at the
            // bottom. That is what makes repeated measurement idempotent.
            const top = Math.round(rect.top + window.scrollY);
            const bottom = measureTrailingSpace(target, viewport);

            if (!Number.isFinite(top) || top < 0 || !Number.isFinite(viewport) || viewport <= 0) {
                debugLog(`measure skipped (from: ${caller})`, { reason: 'implausible-geometry', top, viewport });

                return;
            }

            const previous = {
                top: Number(root.dataset.mmrTableTop ?? NaN),
                bottom: Number(root.dataset.mmrTableBottom ?? NaN),
                viewport: Number(root.dataset.mmrViewportHeight ?? NaN),
            };

            // A scrollbar appearing, a font swapping in or a sub-pixel
            // rounding difference would otherwise rewrite the variables on
            // every observer callback, and each rewrite resizes the table,
            // which calls the observer again.
            const unchanged = (was, now) => Number.isFinite(was) && Math.abs(was - now) < 1;

            if (unchanged(previous.top, top) && unchanged(previous.bottom, bottom) && unchanged(previous.viewport, viewport)) {
                return;
            }

            root.dataset.mmrTableTop = String(top);
            root.dataset.mmrTableBottom = String(bottom);
            root.dataset.mmrViewportHeight = String(viewport);
            root.style.setProperty('--mmr-table-top', `${top}px`);
            root.style.setProperty('--mmr-table-bottom', `${bottom}px`);
            root.style.setProperty('--mmr-viewport-height', `${viewport}px`);

            debugLog(`measured (from: ${caller})`, {
                top,
                bottom,
                viewport,
                previous,
                // Zero once the layout has settled; anything else means the
                // page is still able to scroll.
                documentOverflow: Math.max(root.scrollHeight - viewport, 0),
            });
        };

        const OVERVIEW_CHUNK_CLASS = 'mmr-pagination-overview-chunk';
        // Keep the first two semantic phrases together and allow just one
        // optional break before the final "... items shown" phrase. This caps
        // the Japanese overview at two lines without inserting a forced break
        // when the available width is sufficient.
        const JAPANESE_OVERVIEW_PATTERN = /^(.+?件中.+?件目から)(.+?件目を表示)$/u;

        const formatPaginationOverview = (overview) => {
            const text = overview.textContent.trim();

            if (
                overview.dataset.mmrPaginationOverview === text
                && overview.querySelector(':scope > .mmr-pagination-overview-content')
            ) {
                return;
            }

            const matches = text.match(JAPANESE_OVERVIEW_PATTERN);

            if (!matches) {
                delete overview.dataset.mmrPaginationOverview;

                return;
            }

            const content = document.createElement('span');
            content.className = 'mmr-pagination-overview-content';

            matches.slice(1).forEach((chunk, index) => {
                const span = document.createElement('span');
                span.className = OVERVIEW_CHUNK_CLASS;
                span.textContent = chunk;
                content.append(span);

                if (index < matches.length - 2) {
                    content.append(document.createElement('wbr'));
                }
            });

            overview.replaceChildren(content);
            overview.dataset.mmrPaginationOverview = text;
        };

        const stripLivewireAttributes = (element) => {
            [element, ...element.querySelectorAll('*')].forEach((node) => {
                node.getAttributeNames()
                    .filter((name) => name.startsWith('wire:'))
                    .forEach((name) => node.removeAttribute(name));
            });
        };

        const createPaginationPlaceholder = (sample, edge) => {
            const placeholder = sample.cloneNode(true);

            stripLivewireAttributes(placeholder);
            placeholder.removeAttribute('rel');
            placeholder.classList.remove('fi-active');
            placeholder.classList.add('fi-disabled', 'mmr-pagination-placeholder');
            placeholder.dataset.mmrPaginationPlaceholder = edge;

            // A placeholder remains a complete native pagination item: the
            // same li, button and icon as its source. Mirror Filament's
            // disabled paginator output instead of hiding it, so both edge
            // controls are always present and their disabled state is obvious.
            const button = placeholder.querySelector(':scope > button.fi-pagination-item-btn');
            const icon = button?.querySelector('.fi-pagination-item-icon');

            if (!placeholder.matches('li.fi-pagination-item') || !button || !icon) {
                return null;
            }

            button.disabled = true;
            button.setAttribute('aria-hidden', 'true');
            button.removeAttribute('aria-current');
            // The nearest real item is necessarily the opposite direction
            // when an edge is missing, so flip the native arrow only.
            icon.classList.add('mmr-pagination-placeholder-icon');

            return placeholder;
        };

        const syncPaginationPlaceholders = (wrapper) => {
            wrapper.querySelectorAll('.fi-pagination-overview').forEach(formatPaginationOverview);

            wrapper.querySelectorAll('.fi-pagination-items').forEach((items) => {
                Array.from(items.children).forEach((item) => {
                    if (item.dataset.mmrPaginationPlaceholder) {
                        item.remove();
                    }
                });

                const realItems = Array.from(items.children)
                    .filter((item) => item.matches('.fi-pagination-item'));
                const hasPrevious = realItems.some((item) => item.getAttribute('rel') === 'prev');
                const hasNext = realItems.some((item) => item.getAttribute('rel') === 'next');
                const hasNavigation = realItems.some((item) => ['first', 'prev', 'next', 'last'].includes(item.getAttribute('rel')));

                if (!hasNavigation || realItems.length === 0) {
                    return;
                }

                const firstItem = realItems[0];
                const lastItem = realItems.at(-1);

                if (!hasPrevious) {
                    const sample = realItems.find((item) => item.getAttribute('rel') === 'next') ?? firstItem;
                    const placeholder = createPaginationPlaceholder(sample, 'previous');

                    if (placeholder) {
                        firstItem.before(placeholder);
                    }
                }

                if (!hasNext) {
                    const sample = realItems.find((item) => item.getAttribute('rel') === 'prev') ?? lastItem;
                    const placeholder = createPaginationPlaceholder(sample, 'next');

                    if (placeholder) {
                        lastItem.after(placeholder);
                    }
                }
            });
        };

        const contexts = new Set();
        const contextByComponent = new WeakMap();
        const pendingContexts = new Set();
        let refreshFrame = null;
        let morphUnsubscribe = null;
        let resizeListening = false;

        const refreshContext = (context, caller = 'unknown') => {
            const wrapper = context.component.querySelector(WRAPPER_SELECTOR);

            if (!wrapper) {
                return;
            }

            context.wrapper = wrapper;
            measure(wrapper, caller);
            syncPaginationPlaceholders(wrapper);
        };

        const scheduleRefresh = (context, caller = 'unknown') => {
            if (!context?.component?.isConnected) {
                return;
            }

            context.caller = caller;
            pendingContexts.add(context);

            // Mutation, ResizeObserver and Livewire can all report the same
            // morph. One shared frame makes that morph one layout pass.
            if (refreshFrame !== null) {
                return;
            }

            refreshFrame = requestAnimationFrame(() => {
                refreshFrame = null;
                const scheduled = [...pendingContexts];
                pendingContexts.clear();

                scheduled.forEach((pending) => refreshContext(pending, pending.caller));
            });
        };

        const refreshAll = (caller = 'unknown') => {
            contexts.forEach((context) => scheduleRefresh(context, caller));
        };

        const componentFor = (wrapper) => wrapper.closest('[wire\\:id]') ?? wrapper;

        const discoverContexts = () => {
            document.querySelectorAll(WRAPPER_SELECTOR).forEach((wrapper) => {
                const component = componentFor(wrapper);
                const existing = contextByComponent.get(component);

                if (existing && contexts.has(existing)) {
                    return;
                }

                const context = { component, wrapper, observer: null, caller: 'activate' };
                contextByComponent.set(component, context);
                contexts.add(context);

                if (typeof window.ResizeObserver === 'function') {
                    context.observer = new ResizeObserver(() => scheduleRefresh(context, 'resize-observer'));
                    // Observe only the manager component. A document/body
                    // observer kept running after SPA navigation and caused
                    // unrelated pages to pay this page's layout cost.
                    context.observer.observe(component);
                }

                scheduleRefresh(context, 'activate');
            });
        };

        const registerMorphHook = () => {
            if (morphUnsubscribe || typeof window.Livewire?.hook !== 'function') {
                return;
            }

            const unsubscribe = window.Livewire.hook('morphed', ({ component }) => {
                const context = contextByComponent.get(component?.el);

                if (context) {
                    scheduleRefresh(context, 'morphed');
                }
            });
            morphUnsubscribe = typeof unsubscribe === 'function' ? unsubscribe : () => {};
        };

        const onResize = () => refreshAll('window-resize');

        const activate = (caller = 'activate') => {
            discoverContexts();

            if (contexts.size === 0) {
                return;
            }

            registerMorphHook();

            if (!resizeListening) {
                window.addEventListener('resize', onResize);
                resizeListening = true;
            }

            refreshAll(caller);
        };

        const deactivate = () => {
            morphUnsubscribe?.();
            morphUnsubscribe = null;

            contexts.forEach((context) => context.observer?.disconnect());
            contexts.clear();
            pendingContexts.clear();

            if (refreshFrame !== null) {
                cancelAnimationFrame(refreshFrame);
                refreshFrame = null;
            }

            if (resizeListening) {
                window.removeEventListener('resize', onResize);
                resizeListening = false;
            }

            delete root.dataset.mmrTableTop;
            delete root.dataset.mmrTableBottom;
            delete root.dataset.mmrViewportHeight;
            root.style.removeProperty('--mmr-table-top');
            root.style.removeProperty('--mmr-table-bottom');
            root.style.removeProperty('--mmr-viewport-height');
        };

        window.__mmrTableLayout = { activate, deactivate, refresh: refreshAll };

        document.addEventListener('livewire:init', () => activate('livewire-init'));
        document.addEventListener('livewire:navigating', deactivate);
        document.addEventListener('livewire:navigated', () => activate('navigated'));

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => activate('dom-ready'), { once: true });
        } else {
            activate('initial');
        }
    })();
