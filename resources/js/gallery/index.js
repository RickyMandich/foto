import { Carousel } from './carousel.js';
import { initGrid } from './grid.js';
import { Autoplay } from './autoplay.js';
import { initAudio } from './audio.js';
import { initShare } from './share.js';
import { replaceParams } from './url-state.js';

export default function init(root) {
    const dataEl = document.getElementById('gallery-data');
    if (!dataEl) return;

    const data = JSON.parse(dataEl.textContent);
    const state = {
        index: data.start ?? 0,
        view: root.dataset.view ?? 'carousel',
    };

    const carouselView = root.querySelector('#carousel-view');
    const gridView = root.querySelector('#grid-view');
    const btnViewCarousel = root.querySelector('#btn-view-carousel');
    const btnViewGrid = root.querySelector('#btn-view-grid');
    const btnFullscreen = root.querySelector('#fullscreen');

    const carousel = new Carousel(root, data.photos, { onChange: onSlideChange });
    const autoplay = new Autoplay(root, {
        next: () => carousel.next(),
        isActive: () => state.view === 'carousel',
    });

    initAudio(root, data.tracks ?? []);
    initShare(root);

    initGrid(root, data.photos, {
        openAt: (i) => {
            setView('carousel');
            carousel.goTo(i, { smooth: false });
        },
    });

    function onSlideChange(i) {
        state.index = i;
        const counter = root.querySelector('#counter');
        if (counter) {
            counter.textContent = `${i + 1}/${data.photos.length}`;
        }

        const downloadLink = root.querySelector('#download-photo');
        if (downloadLink && data.photos[i]) {
            downloadLink.href = data.photos[i].orig;
            downloadLink.download = data.photos[i].name;
        }

        if (data.photos[i]) {
            replaceParams({ f: data.photos[i].id });
        }

        autoplay.restart();
        root.dispatchEvent(new CustomEvent('gallery:change', {
            detail: { index: i, photo: data.photos[i] },
        }));
    }

    function setView(view) {
        state.view = view;
        root.dataset.view = view;

        const isGrid = view === 'grid';
        if (carouselView) carouselView.classList.toggle('hidden', isGrid);
        if (gridView) gridView.classList.toggle('hidden', !isGrid);

        btnViewCarousel?.setAttribute('aria-checked', !isGrid ? 'true' : 'false');
        btnViewGrid?.setAttribute('aria-checked', isGrid ? 'true' : 'false');

        replaceParams({ vista: isGrid ? 'griglia' : null });

        if (isGrid) {
            autoplay.clear();
            const currentThumb = gridView?.querySelector(`[data-index="${state.index}"]`);
            currentThumb?.scrollIntoView({ block: 'center' });
        } else {
            carousel.goTo(state.index, { smooth: false });
            autoplay.restart();
        }
    }

    btnViewCarousel?.addEventListener('click', () => setView('carousel'));
    btnViewGrid?.addEventListener('click', () => setView('grid'));

    // Schermo intero
    if (btnFullscreen) {
        if (!document.fullscreenEnabled) {
            btnFullscreen.classList.add('hidden');
        } else {
            btnFullscreen.addEventListener('click', () => {
                if (!document.fullscreenElement) {
                    root.requestFullscreen().catch(() => {});
                } else {
                    document.exitFullscreen().catch(() => {});
                }
            });
        }
    }

    // Navigazione da tastiera
    window.addEventListener('keydown', (e) => {
        if (['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;
        if (e.ctrlKey || e.altKey || e.metaKey) return;

        if (e.key === 'ArrowRight') {
            e.preventDefault();
            carousel.next();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            carousel.prev();
        } else if (e.key === 'Home') {
            e.preventDefault();
            carousel.goTo(0);
        } else if (e.key === 'End') {
            e.preventDefault();
            carousel.goTo(data.photos.length - 1);
        } else if (e.key === ' ') {
            e.preventDefault();
            autoplay.enabled = !autoplay.enabled;
            autoplay.updateButton();
            autoplay.restart();
        } else if (e.key.toLowerCase() === 'g') {
            e.preventDefault();
            setView(state.view === 'carousel' ? 'grid' : 'carousel');
        } else if (e.key.toLowerCase() === 'f' && document.fullscreenEnabled) {
            e.preventDefault();
            if (!document.fullscreenElement) {
                root.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
            }
        }
    });

    carousel.goTo(state.index, { smooth: false });
    setView(state.view);
}
