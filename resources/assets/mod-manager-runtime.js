(() => {
    'use strict';

    if (window.__mmrRuntimeInstalled) {
        return;
    }

    window.__mmrRuntimeInstalled = true;

    const projectIconPlaceholder = document.currentScript?.dataset.mmrProjectIconPlaceholder ?? '';
    let headerScrollFrame = null;

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
    });
})();
