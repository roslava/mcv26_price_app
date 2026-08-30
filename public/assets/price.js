(() => {
    document.documentElement.classList.add('js');

    const input = document.querySelector('#price-search');
    const sections = Array.from(document.querySelectorAll('[data-price-section]'));
    const status = document.querySelector('#search-status');
    const noResults = document.querySelector('#no-results');
    const backToTools = document.querySelector('.back-to-tools');
    if (!(input instanceof HTMLInputElement) || !status || !noResults || sections.length === 0) {
        return;
    }

    const normalize = (value) => value
        .normalize('NFKC')
        .toLocaleLowerCase('ru-RU')
        .trim()
        .replace(/\s+/g, ' ');

    const sectionData = sections.map((section) => {
        const heading = section.querySelector('h2');
        const services = Array.from(section.querySelectorAll('[data-service]'));
        return {
            element: section,
            name: normalize(heading?.textContent ?? ''),
            services: services.map((service) => ({
                element: service,
                text: normalize(service.textContent ?? ''),
            })),
            navItem: document.querySelector(
                `[data-nav-section="${section.dataset.priceSection}"]`
            ),
        };
    });

    const setActiveSection = (section) => {
        sectionData.forEach((item) => {
            const active = item.element === section;
            if (item.navItem instanceof HTMLElement) {
                const link = item.navItem.querySelector('a');
                link?.classList.toggle('is-active', active);
                if (active) {
                    link?.setAttribute('aria-current', 'location');
                } else {
                    link?.removeAttribute('aria-current');
                }
            }
        });
    };

    const setActiveSectionFromPosition = () => {
        const marker = 110;
        const visible = sectionData.filter((item) => !item.element.hidden);
        const passed = visible.filter((item) => item.element.getBoundingClientRect().top <= marker);
        const current = passed.length > 0 ? passed[passed.length - 1] : visible[0];
        if (current) {
            setActiveSection(current.element);
        }
    };

    sectionData.forEach((item) => {
        item.navItem?.querySelector('a')?.addEventListener('click', () => setActiveSection(item.element));
    });

    if ('IntersectionObserver' in window) {
        const sectionObserver = new IntersectionObserver((entries) => {
            const intersecting = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
            if (intersecting.length > 0) {
                setActiveSection(intersecting[0].target);
            }
        }, { rootMargin: '-100px 0px -65% 0px', threshold: [0, 1] });
        sections.forEach((section) => sectionObserver.observe(section));
    }

    setActiveSectionFromPosition();
    window.addEventListener('load', setActiveSectionFromPosition, { once: true });
    window.addEventListener('pageshow', setActiveSectionFromPosition);

    const update = () => {
        const query = normalize(input.value);
        let visibleServices = 0;

        sectionData.forEach((section) => {
            const sectionMatches = query !== '' && section.name.includes(query);
            let sectionServices = 0;

            section.services.forEach((service) => {
                const matches = query === '' || sectionMatches || service.text.includes(query);
                service.element.hidden = !matches;
                if (matches) {
                    sectionServices += 1;
                }
            });

            const sectionVisible = sectionServices > 0;
            section.element.hidden = !sectionVisible;
            if (section.navItem instanceof HTMLElement) {
                section.navItem.hidden = !sectionVisible;
            }
            visibleServices += sectionServices;
        });

        noResults.hidden = visibleServices !== 0;
        status.textContent = visibleServices === 0 ? '' : `Показано услуг: ${visibleServices}`;
        setActiveSectionFromPosition();
    };

    input.addEventListener('input', update);

    if (backToTools instanceof HTMLAnchorElement) {
        const searchPanel = document.querySelector('.search-panel');
        let ticking = false;
        let revealAt = 400;

        const recalculateRevealAt = () => {
            if (searchPanel instanceof HTMLElement) {
                revealAt = searchPanel.getBoundingClientRect().bottom + window.scrollY + 80;
            }
        };

        const updateBackToToolsVisibility = () => {
            backToTools.hidden = window.scrollY <= revealAt;
            ticking = false;
        };

        const scheduleBackToToolsVisibility = () => {
            if (!ticking) {
                window.requestAnimationFrame(updateBackToToolsVisibility);
                ticking = true;
            }
        };

        window.addEventListener('scroll', () => {
            scheduleBackToToolsVisibility();
        }, { passive: true });

        window.addEventListener('resize', () => {
            recalculateRevealAt();
            scheduleBackToToolsVisibility();
        });
        // Initialize immediately and after browser history restoration (bfcache).
        recalculateRevealAt();
        updateBackToToolsVisibility();
        window.addEventListener('load', () => {
            recalculateRevealAt();
            updateBackToToolsVisibility();
        }, { once: true });
        window.addEventListener('pageshow', () => {
            recalculateRevealAt();
            updateBackToToolsVisibility();
        });
    }

})();
