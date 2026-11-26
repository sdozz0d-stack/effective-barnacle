(() => {
    const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const colorSchemePreference = window.matchMedia('(prefers-color-scheme: dark)');
    const root = document.documentElement;
    const themeStorageKey = 'main-ui-theme';
    let hasSavedTheme = false;
    let themeColorMeta = document.querySelector('meta[name="theme-color"]');
    if (!themeColorMeta) {
        themeColorMeta = document.createElement('meta');
        themeColorMeta.name = 'theme-color';
        document.head.appendChild(themeColorMeta);
    }
    const themeToggle = document.createElement('button');
    themeToggle.type = 'button';
    themeToggle.className = 'ui-theme-toggle no-print';
    themeToggle.setAttribute('role', 'switch');
    themeToggle.setAttribute('aria-label', 'Dark mode');
    themeToggle.setAttribute('aria-checked', 'false');
    const themeTrack = document.createElement('span');
    themeTrack.className = 'ui-theme-switch-track';
    themeTrack.setAttribute('aria-hidden', 'true');
    const themeThumb = document.createElement('span');
    themeThumb.className = 'ui-theme-switch-thumb';
    themeTrack.appendChild(themeThumb);
    const themeLabel = document.createElement('span');
    themeLabel.className = 'ui-theme-switch-label';
    themeToggle.append(themeTrack, themeLabel);

    function applyTheme(theme, persist = false) {
        const isDark = theme === 'dark';
        root.dataset.theme = isDark ? 'dark' : 'light';
        themeLabel.textContent = isDark ? 'Dark mode' : 'Light mode';
        themeToggle.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        themeToggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        themeToggle.setAttribute('aria-checked', String(isDark));
        themeColorMeta.content = isDark ? '#0f1412' : '#107c41';
        if (persist) {
            try {
                window.localStorage.setItem(themeStorageKey, root.dataset.theme);
                hasSavedTheme = true;
            } catch (error) {
                console.warn('Unable to save the color theme preference.', error);
            }
        }
    }

    let savedTheme = '';
    try {
        const storedTheme = window.localStorage.getItem(themeStorageKey);
        if (storedTheme === 'dark' || storedTheme === 'light') {
            savedTheme = storedTheme;
            hasSavedTheme = true;
        }
    } catch (error) {
        console.warn('Unable to read the saved color theme preference.', error);
    }
    applyTheme(savedTheme || (colorSchemePreference.matches ? 'dark' : 'light'));
    themeToggle.addEventListener('click', () => {
        applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
    });
    const followSystemTheme = event => {
        if (!hasSavedTheme) applyTheme(event.matches ? 'dark' : 'light');
    };
    if (typeof colorSchemePreference.addEventListener === 'function') {
        colorSchemePreference.addEventListener('change', followSystemTheme);
    } else {
        colorSchemePreference.addListener(followSystemTheme);
    }
    function mountThemeToggle() {
        if (document.body && !themeToggle.isConnected) {
            document.body.appendChild(themeToggle);
        }
    }

    function installCommandPalette() {
        if (!document.body || document.querySelector('.ui-command-trigger')) return;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'ui-command-trigger no-print';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-label', 'Open quick navigation');
        const platformShortcut = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘ K' : 'Ctrl K';
        const triggerIcon = document.createElement('span');
        triggerIcon.className = 'ui-command-trigger-icon';
        triggerIcon.setAttribute('aria-hidden', 'true');
        triggerIcon.textContent = '⌕';
        const triggerLabel = document.createElement('span');
        triggerLabel.textContent = 'Quick jump';
        const triggerShortcut = document.createElement('kbd');
        triggerShortcut.textContent = platformShortcut;
        trigger.append(triggerIcon, triggerLabel, triggerShortcut);

        const dialog = document.createElement('dialog');
        dialog.className = 'ui-command-dialog';
        dialog.setAttribute('aria-labelledby', 'uiCommandTitle');

        const heading = document.createElement('div');
        heading.className = 'ui-command-heading';
        const title = document.createElement('h2');
        title.id = 'uiCommandTitle';
        title.textContent = 'Jump to';
        const shortcut = document.createElement('kbd');
        shortcut.textContent = 'ESC';
        heading.append(title, shortcut);

        const searchWrap = document.createElement('label');
        searchWrap.className = 'ui-command-search';
        const searchIcon = document.createElement('span');
        searchIcon.setAttribute('aria-hidden', 'true');
        searchIcon.textContent = '⌕';
        const search = document.createElement('input');
        search.type = 'search';
        search.placeholder = 'Search pages and tools...';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Search navigation');
        searchWrap.append(searchIcon, search);

        const results = document.createElement('div');
        results.className = 'ui-command-results';
        results.setAttribute('role', 'listbox');
        results.setAttribute('aria-label', 'Navigation results');
        const footer = document.createElement('div');
        footer.className = 'ui-command-footer';
        const navigateHint = document.createElement('span');
        const upKey = document.createElement('kbd');
        upKey.textContent = '↑';
        const downKey = document.createElement('kbd');
        downKey.textContent = '↓';
        navigateHint.append(upKey, downKey, document.createTextNode(' to navigate'));
        const openHint = document.createElement('span');
        const enterKey = document.createElement('kbd');
        enterKey.textContent = '↵';
        openHint.append(enterKey, document.createTextNode(' to open'));
        footer.append(navigateHint, openHint);
        dialog.append(heading, searchWrap, results, footer);
        document.body.append(trigger, dialog);

        let visibleResults = [];
        let activeIndex = 0;
        let previousFocus = null;

        function collectLinks() {
            const seen = new Set();
            return [...document.querySelectorAll(
                'nav a[href], a.action-card[href], .rail a[href], .user-bar a[href], .topbar a[href], .breadcrumb a[href]'
            )].flatMap(link => {
                if (link.closest('[hidden], [aria-hidden="true"]')) return [];
                const label = (link.getAttribute('aria-label') || link.textContent || '').replace(/\s+/g, ' ').trim();
                if (!label || seen.has(link.href)) return [];
                let url;
                try {
                    url = new URL(link.href, window.location.href);
                } catch {
                    return [];
                }
                if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) return [];
                seen.add(link.href);
                return [{ href: link.href, label }];
            });
        }

        if (!collectLinks().length) {
            trigger.remove();
            dialog.remove();
            return;
        }

        function renderResults() {
            const query = search.value.trim().toLocaleLowerCase();
            visibleResults = collectLinks().filter(item =>
                item.label.toLocaleLowerCase().includes(query)
                || new URL(item.href).pathname.toLocaleLowerCase().includes(query)
            );
            results.replaceChildren();
            activeIndex = 0;
            if (!visibleResults.length) {
                const empty = document.createElement('p');
                empty.className = 'ui-command-empty';
                empty.textContent = query ? 'No matching pages or tools.' : 'No navigation links available on this page.';
                results.appendChild(empty);
                return;
            }

            visibleResults.forEach((item, index) => {
                const option = document.createElement('a');
                option.className = 'ui-command-option';
                option.href = item.href;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(index === activeIndex));
                const name = document.createElement('span');
                name.textContent = item.label;
                const destination = document.createElement('small');
                destination.textContent = new URL(item.href).pathname;
                option.append(name, destination);
                option.addEventListener('mouseenter', () => setActive(index));
                results.appendChild(option);
            });
        }

        function setActive(index) {
            if (!visibleResults.length) return;
            activeIndex = (index + visibleResults.length) % visibleResults.length;
            const options = results.querySelectorAll('.ui-command-option');
            options.forEach((option, optionIndex) => {
                option.setAttribute('aria-selected', String(optionIndex === activeIndex));
            });
        }

        function closePalette() {
            if (dialog.open) dialog.close();
        }

        function openPalette() {
            if (dialog.open) return;
            previousFocus = document.activeElement;
            search.value = '';
            renderResults();
            dialog.showModal();
            search.focus();
        }

        trigger.addEventListener('click', openPalette);
        search.addEventListener('input', renderResults);
        dialog.addEventListener('click', event => {
            if (event.target === dialog) closePalette();
        });
        dialog.addEventListener('close', () => {
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
        });
        dialog.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!visibleResults.length) return;
                event.preventDefault();
                const options = results.querySelectorAll('.ui-command-option');
                setActive(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
                options[activeIndex].focus();
            } else if (event.key === 'Enter' && visibleResults.length) {
                const activeOption = results.querySelectorAll('.ui-command-option')[activeIndex];
                if (document.activeElement === search) {
                    event.preventDefault();
                    window.location.assign(visibleResults[activeIndex].href);
                } else if (activeOption && document.activeElement === activeOption) {
                    event.preventDefault();
                    window.location.assign(activeOption.href);
                }
            }
        });
        document.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                openPalette();
            } else if (event.key === 'Escape' && dialog.open) {
                closePalette();
            }
        });
    }

    function installDashboardInteractions() {
        if (motionPreference.matches
            || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            return;
        }

        document.querySelectorAll('.dashboard-admin .action-card, .dashboard-rater .action-card')
            .forEach(card => {
                card.addEventListener('pointermove', event => {
                    if (event.pointerType !== 'mouse') return;
                    const bounds = card.getBoundingClientRect();
                    const x = (event.clientX - bounds.left) / bounds.width;
                    const y = (event.clientY - bounds.top) / bounds.height;
                    card.style.setProperty('--tilt-x', `${((.5 - y) * 3).toFixed(2)}deg`);
                    card.style.setProperty('--tilt-y', `${((x - .5) * 3).toFixed(2)}deg`);
                    card.style.setProperty('--spot-x', `${(x * 100).toFixed(1)}%`);
                    card.style.setProperty('--spot-y', `${(y * 100).toFixed(1)}%`);
                });
                card.addEventListener('pointerleave', () => {
                    card.style.removeProperty('--tilt-x');
                    card.style.removeProperty('--tilt-y');
                    card.style.removeProperty('--spot-x');
                    card.style.removeProperty('--spot-y');
                });
            });
    }

    if (document.body) {
        mountThemeToggle();
        installCommandPalette();
        installDashboardInteractions();
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            mountThemeToggle();
            installCommandPalette();
            installDashboardInteractions();
        }, { once: true });
    }

    const viewTransitionsSupported = typeof document.startViewTransition === 'function';
    let activeTransitionId = 0;
    const alertQueue = [];
    let activeAlert = null;

    function showNextAlert() {
        if (activeAlert || !alertQueue.length || !document.body) return;

        const request = alertQueue.shift();
        const previousFocus = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'ui-alert-backdrop';

        const dialog = document.createElement('section');
        dialog.className = 'ui-alert-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'uiAlertTitle');
        dialog.setAttribute('aria-describedby', 'uiAlertMessage');
        dialog.tabIndex = -1;

        const title = document.createElement('h2');
        title.id = 'uiAlertTitle';
        title.textContent = 'Notice';

        const message = document.createElement('p');
        message.id = 'uiAlertMessage';
        message.textContent = request.message;

        const actions = document.createElement('div');
        actions.className = 'ui-alert-actions';
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'ui-alert-button';
        closeButton.textContent = 'OK';
        actions.appendChild(closeButton);

        dialog.append(title, message, actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        activeAlert = { overlay, previousFocus, resolve: request.resolve };

        function closeAlert() {
            if (!activeAlert || activeAlert.overlay !== overlay) return;
            overlay.remove();
            activeAlert.resolve();
            activeAlert = null;
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
            showNextAlert();
        }

        closeButton.addEventListener('click', closeAlert);
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeAlert();
            }
        });
        closeButton.focus();
    }

    window.showSiteAlert = message => new Promise(resolve => {
        alertQueue.push({ message: String(message ?? ''), resolve });
        showNextAlert();
    });
    window.alert = window.showSiteAlert;

    window.showSiteConfirm = (message, options = {}) => new Promise(resolve => {
        const previousFocus = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'ui-confirm-backdrop';

        const dialog = document.createElement('section');
        dialog.className = 'ui-confirm-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');

        const title = document.createElement('h2');
        title.textContent = options.title || 'Please confirm';
        const description = document.createElement('p');
        description.textContent = String(message ?? '');

        const actions = document.createElement('div');
        actions.className = 'ui-confirm-actions';
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'ui-confirm-cancel';
        cancelButton.textContent = options.cancelText || 'Cancel';
        const confirmButton = document.createElement('button');
        confirmButton.type = 'button';
        confirmButton.className = options.danger === false ? 'ui-confirm-accept' : 'ui-confirm-danger';
        confirmButton.textContent = options.confirmText || 'Continue';
        actions.append(cancelButton, confirmButton);
        dialog.append(title, description, actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        let finished = false;
        const close = confirmed => {
            if (finished) return;
            finished = true;
            overlay.remove();
            resolve(confirmed);
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
        };
        cancelButton.addEventListener('click', () => close(false));
        confirmButton.addEventListener('click', () => close(true));
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            } else if (event.key === 'Tab') {
                event.preventDefault();
                (document.activeElement === cancelButton ? confirmButton : cancelButton).focus();
            }
        });
        cancelButton.focus();
    });

    window.runUITransition = (update, transition = 'content') => {
        if (typeof update !== 'function') return;
        if (motionPreference.matches || !viewTransitionsSupported) {
            update();
            return;
        }

        const transitionId = String(++activeTransitionId);
        root.dataset.uiTransition = transition;
        root.dataset.uiTransitionId = transitionId;
        const cleanup = () => {
            if (root.dataset.uiTransitionId !== transitionId) return;
            delete root.dataset.uiTransition;
            delete root.dataset.uiTransitionId;
        };
        const viewTransition = document.startViewTransition(update);
        viewTransition.finished.then(cleanup, cleanup);
        return viewTransition;
    };

    if (motionPreference.matches || !('IntersectionObserver' in window)) return;

    const revealSelector = 'main > .card, .pg > .cd, #positions > .position, .recent-form, .section-table, .sheet-row';
    const revealedElements = new Set();
    let staggerIndex = 0;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const element = entry.target;
            element.classList.add('ui-visible');
            observer.unobserve(element);
        });
    }, { threshold: 0.01, rootMargin: '0px 0px -24px 0px' });

    function observeElement(element) {
        if (revealedElements.has(element) || element.closest('#tables-wrapper')) return;
        revealedElements.add(element);
        element.classList.add('ui-reveal');
        element.style.setProperty('--ui-stagger', `${Math.min(staggerIndex++, 6) * 55}ms`);
        observer.observe(element);
    }

    function scan(node) {
        if (!(node instanceof Element) || node.closest('#tables-wrapper')) return;
        if (node.matches(revealSelector)) observeElement(node);
        node.querySelectorAll(revealSelector).forEach(observeElement);
    }

    document.querySelectorAll(revealSelector).forEach(observeElement);

    const mutations = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(scan));
    });
    mutations.observe(document.body, { childList: true, subtree: true });
})();
