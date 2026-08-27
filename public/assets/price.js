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
    };

    input.addEventListener('input', update);

    if (backToTools instanceof HTMLAnchorElement) {
        const sectionNavigation = document.querySelector('.section-nav');
        let ticking = false;

        backToTools.hidden = true;

        const updateBackToTools = () => {
            const threshold = sectionNavigation instanceof HTMLElement
                ? sectionNavigation.getBoundingClientRect().bottom + window.scrollY + 80
                : 600;
            backToTools.hidden = window.scrollY <= threshold;
            ticking = false;
        };

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(updateBackToTools);
                ticking = true;
            }
        }, { passive: true });

        window.addEventListener('resize', updateBackToTools);
        updateBackToTools();
    }
})();
