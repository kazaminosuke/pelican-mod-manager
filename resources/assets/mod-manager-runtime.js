(() => {
    'use strict';

    if (window.__mmrRuntimeInstalled) {
        return;
    }

    window.__mmrRuntimeInstalled = true;

    const projectIconPlaceholder = document.currentScript?.dataset.mmrProjectIconPlaceholder ?? '';
    const catalogViewStorageKey = 'pelican-mod-manager.catalog-view';
    let headerScrollFrame = null;
    let catalogViewFrame = null;

    const storedCatalogView = () => {
        try {
            return localStorage.getItem(catalogViewStorageKey) === 'panel' ? 'panel' : 'list';
        } catch (_) {
            return 'list';
        }
    };

    const applyCatalogView = () => {
        catalogViewFrame = null;
        const wrapper = document.querySelector('.mmr-table-scroll-ctn');
        const toggle = document.querySelector('[data-mmr-view-toggle]');
        if (!wrapper || !toggle) {
            wrapper?.removeAttribute('data-mmr-catalog-view');
            return;
        }

        const view = storedCatalogView();
        const target = view === 'panel' ? 'list' : 'panel';
        const label = target === 'panel'
            ? toggle.dataset.mmrViewPanelLabel
            : toggle.dataset.mmrViewListLabel;
        wrapper.dataset.mmrCatalogView = view;
        toggle.dataset.mmrViewTarget = target;
        toggle.setAttribute('aria-label', label || '');
        toggle.setAttribute('title', label || '');
        toggle.querySelectorAll('[data-mmr-view-icon]').forEach((icon) => {
            icon.hidden = icon.dataset.mmrViewIcon !== target;
        });
    };

    const queueCatalogView = () => {
        if (catalogViewFrame !== null) {
            return;
        }
        catalogViewFrame = requestAnimationFrame(applyCatalogView);
    };

    document.addEventListener('click', (event) => {
        const toggle = event.target instanceof Element ? event.target.closest('[data-mmr-view-toggle]') : null;
        if (!toggle) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        const target = toggle.dataset.mmrViewTarget === 'list' ? 'list' : 'panel';
        try {
            localStorage.setItem(catalogViewStorageKey, target);
        } catch (_) {
            // A locked-down browser may disable storage; the current view can
            // still change for this rendered page.
        }
        const wrapper = document.querySelector('.mmr-table-scroll-ctn');
        if (wrapper) {
            wrapper.dataset.mmrCatalogView = target;
        }
        queueCatalogView();
    }, true);

    new MutationObserver(queueCatalogView).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('livewire:navigated', queueCatalogView);
    queueCatalogView();

    // Image errors do not bubble, so one capture-phase listener replaces a
    // large inline onerror attribute on every catalog row and survives morphs.
    document.addEventListener('error', (event) => {
        const image = event.target;

        if (
            !(image instanceof HTMLImageElement)
            || !image.matches('.mmr-table-scroll-ctn .mmr-project-icon-cell .fi-ta-image img')
        ) {
            return;
        }

        const source = image.currentSrc || image.src;
        if (!projectIconPlaceholder || !source || source === projectIconPlaceholder) {
            return;
        }

        image.src = projectIconPlaceholder;
    }, true);

    // PHP emits only this event name after a pagination change. The sizeable
    // geometry/animation body is downloaded once with the immutable asset.
    window.addEventListener('mmr:scroll-header', () => {
        if (headerScrollFrame !== null) {
            cancelAnimationFrame(headerScrollFrame);
        }

        headerScrollFrame = requestAnimationFrame(() => {
            headerScrollFrame = requestAnimationFrame(() => {
                headerScrollFrame = null;

                // The stock Filament page header contains this page's title;
                // the schema header remains a fallback for customized views.
                const header = document.querySelector('.fi-page .fi-header')
                    ?? document.querySelector('.mmr-page-header');
                if (!header) {
                    return;
                }

                const topbarHeight = document.querySelector('.fi-topbar')
                    ?.getBoundingClientRect().height ?? 0;
                const top = window.scrollY + header.getBoundingClientRect().top - topbarHeight - 16;

                window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
            });
        });
    });

    document.addEventListener('livewire:navigating', () => {
        if (headerScrollFrame !== null) {
            cancelAnimationFrame(headerScrollFrame);
            headerScrollFrame = null;
        }
        if (catalogViewFrame !== null) {
            cancelAnimationFrame(catalogViewFrame);
            catalogViewFrame = null;
        }
    });
})();
