(() => {
    'use strict';

    if (window.__mmrCatalogUrlHistory) {
        window.__mmrCatalogUrlHistory.activate();

        return;
    }

    // Livewire tracks source and page as separate URL properties. A tab
    // switch can therefore change both in one commit. Keep Livewire's URL
    // hydration and pop handling, but collapse same-commit pushes to one entry.
    const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn[data-mmr-swr-scope]';
    const activeComponents = new Set();
    const activeCommits = new Set();
    const fallbackFrames = new Set();
    let commitUnsubscribe = null;
    let originalPushState = null;
    let originalReplaceState = null;
    let wrappedPushState = null;

    const discoverComponents = () => {
        document.querySelectorAll(WRAPPER_SELECTOR).forEach((wrapper) => {
            activeComponents.add(wrapper.closest('[wire\\:id]') ?? wrapper);
        });
    };

    const scheduleCommitFallback = (commit) => {
        const firstFrame = requestAnimationFrame(() => {
            fallbackFrames.delete(firstFrame);

            const secondFrame = requestAnimationFrame(() => {
                fallbackFrames.delete(secondFrame);
                activeCommits.delete(commit);
            });
            fallbackFrames.add(secondFrame);
        });
        fallbackFrames.add(firstFrame);
    };

    const activate = () => {
        discoverComponents();

        if (
            activeComponents.size === 0
            || commitUnsubscribe
            || typeof window.Livewire?.hook !== 'function'
        ) {
            return;
        }

        originalPushState = window.history.pushState;
        originalReplaceState = window.history.replaceState;
        wrappedPushState = function (state, title, url) {
            // A global history hook cannot identify which commit invoked it.
            // Coalesce only while exactly one manager commit is active; with
            // concurrent commits, preserving every push is the safe fallback.
            const commit = activeCommits.size === 1 ? activeCommits.values().next().value : null;

            if (!commit) {
                return originalPushState.call(window.history, state, title, url);
            }

            commit.pushes++;

            if (commit.pushes > 1) {
                return originalReplaceState.call(window.history, state, title, url);
            }

            return originalPushState.call(window.history, state, title, url);
        };
        window.history.pushState = wrappedPushState;

        const unsubscribe = window.Livewire.hook('commit', ({ component, succeed, fail, respond }) => {
            if (!activeComponents.has(component?.el)) {
                return;
            }

            const canonical = component?.canonical;
            if (
                !canonical
                || !Object.prototype.hasOwnProperty.call(canonical, 'source')
                || !Object.prototype.hasOwnProperty.call(canonical, 'catalogPage')
            ) {
                return;
            }

            const commit = { pushes: 0 };
            activeCommits.add(commit);

            succeed(() => {
                // URL effects are success callbacks too; defer cleanup until
                // every callback belonging to this commit has run.
                queueMicrotask(() => activeCommits.delete(commit));
            });
            fail?.(() => activeCommits.delete(commit));
            respond?.(() => scheduleCommitFallback(commit));
        });

        commitUnsubscribe = typeof unsubscribe === 'function' ? unsubscribe : () => {};
    };

    const deactivate = () => {
        commitUnsubscribe?.();
        commitUnsubscribe = null;

        if (window.history.pushState === wrappedPushState && originalPushState) {
            window.history.pushState = originalPushState;
        }

        fallbackFrames.forEach((frame) => cancelAnimationFrame(frame));
        fallbackFrames.clear();
        activeCommits.clear();
        activeComponents.clear();
        originalPushState = null;
        originalReplaceState = null;
        wrappedPushState = null;
    };

    window.__mmrCatalogUrlHistory = { activate, deactivate };
    document.addEventListener('livewire:init', activate);
    document.addEventListener('livewire:navigating', deactivate);
    document.addEventListener('livewire:navigated', activate);
    activate();
})();
