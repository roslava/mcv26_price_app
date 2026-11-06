(() => {
    document.documentElement.classList.add('js');

    const input = document.querySelector('#price-search');
    const sections = Array.from(document.querySelectorAll('[data-price-section]'));
    const status = document.querySelector('#search-status');
    const noResults = document.querySelector('#no-results');
    const backToTools = document.querySelector('.back-to-tools');
    if (sections.length === 0) {
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

    let sectionTicking = false;
    const scheduleActiveSectionUpdate = () => {
        if (sectionTicking) {
            return;
        }
        sectionTicking = true;
        window.requestAnimationFrame(() => {
            setActiveSectionFromPosition();
            sectionTicking = false;
        });
    };

    setActiveSectionFromPosition();
    window.addEventListener('load', setActiveSectionFromPosition, { once: true });
    window.addEventListener('pageshow', setActiveSectionFromPosition);
    window.addEventListener('hashchange', scheduleActiveSectionUpdate);
    window.addEventListener('scroll', scheduleActiveSectionUpdate, { passive: true });
    window.addEventListener('resize', scheduleActiveSectionUpdate);

    if (!(input instanceof HTMLInputElement) || !status || !noResults) {
        return;
    }

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

// A shadow is only needed when the search panel is pinned.
(() => {
    const panel = document.querySelector('.search-panel');
    if (!panel) return;
    const marker = document.createElement('div');
    marker.setAttribute('aria-hidden', 'true');
    panel.before(marker);
    let frame = 0;
    const update = () => {
        frame = 0;
        const style = getComputedStyle(panel);
        const top = parseFloat(style.top) || 0;
        const stuck = style.position === 'sticky'
            && marker.getBoundingClientRect().top < top
            && Math.abs(panel.getBoundingClientRect().top - top) < 1;
        panel.classList.toggle('is-stuck', stuck);
        document.body.classList.toggle('search-is-stuck', stuck);
    };
    const schedule = () => {
        if (!frame) frame = requestAnimationFrame(update);
    };
    addEventListener('scroll', schedule, { passive: true });
    addEventListener('resize', schedule);
    addEventListener('pageshow', schedule);
    addEventListener('load', schedule);
    new ResizeObserver(schedule).observe(panel);
    update();
})();

// Collapse mobile section tags after two measured rows.
(() => {
    const nav = document.querySelector('.section-nav');
    const list = nav?.querySelector('ul');
    if (!list) return;
    list.id = 'price-section-tags';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'section-nav-toggle';
    button.setAttribute('aria-controls', list.id);
    button.hidden = true;
    list.after(button);
    let expanded = false;
    let frame = 0;
    const update = () => {
        frame = 0;
        const mobile = matchMedia('(max-width: 767px)').matches;
        const items = Array.from(list.children);
        const visible = items.filter(item => !item.hidden);
        const top = list.getBoundingClientRect().top;
        const rows = [];
        visible.forEach(item => {
            const y = Math.round(item.getBoundingClientRect().top - top);
            if (!rows.some(row => Math.abs(row - y) < 2)) rows.push(y);
        });
        rows.sort((a, b) => a - b);
        const collapsible = mobile && rows.length > 2;
        const collapsed = collapsible && !expanded;
        let bottom = 0;
        items.forEach(item => {
            const rect = item.getBoundingClientRect();
            const outside = collapsed && Math.round(rect.top - top) > rows[1] + 1;
            item.inert = outside;
            if (outside) item.setAttribute('aria-hidden', 'true');
            else item.removeAttribute('aria-hidden');
            if (!item.hidden && !outside) bottom = Math.max(bottom, rect.bottom - top);
        });
        list.style.maxHeight = collapsed ? Math.ceil(bottom) + 'px' : '';
        list.classList.toggle('is-collapsed', collapsed);
        button.hidden = !collapsible;
        button.textContent = expanded ? 'Свернуть разделы' : 'Показать все разделы';
        button.setAttribute('aria-expanded', String(!collapsed));
    };
    const schedule = () => {
        if (!frame) frame = requestAnimationFrame(update);
    };
    button.addEventListener('click', () => { expanded = !expanded; update(); });
    addEventListener('resize', schedule);
    addEventListener('load', schedule);
    document.fonts?.ready.then(schedule);
    new ResizeObserver(schedule).observe(nav);
    new MutationObserver(schedule).observe(list, {
        subtree: true, attributes: true, attributeFilter: ['hidden']
    });
    update();
})();

(() => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mobile-back-to-top';
    button.textContent = '↑ Вернуться в начало';
    button.hidden = true;
    document.body.append(button);
    let scheduled = false;
    const update = () => {
        scheduled = false;
        button.hidden = !matchMedia('(max-width: 767px)').matches || scrollY < 500;
    };
    const schedule = () => {
        if (!scheduled) { scheduled = true; requestAnimationFrame(update); }
    };
    button.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'
        });
    });
    addEventListener('scroll', schedule, { passive: true });
    addEventListener('resize', schedule);
    addEventListener('pageshow', schedule);
    update();
})();
