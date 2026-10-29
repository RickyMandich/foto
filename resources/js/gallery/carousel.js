import { nextIndex, prevIndex } from '../lib/nav.js';

export class Carousel {
    constructor(root, photos, { onChange }) {
        this.root = root;
        this.photos = photos;
        this.onChange = onChange;
        this.currentIndex = 0;

        this.track = root.querySelector('#carousel-track');
        this.btnPrev = root.querySelector('#prev');
        this.btnNext = root.querySelector('#next');

        this.slides = [];
        this.buildTrack();
        this.setupObserver();
        this.bindEvents();
    }

    buildTrack() {
        if (!this.track) return;
        this.track.innerHTML = '';

        this.photos.forEach((photo, idx) => {
            const slide = document.createElement('div');
            slide.className = 'flex h-full w-full shrink-0 items-center justify-center snap-center';
            slide.dataset.index = idx;
            slide.setAttribute('aria-label', `${idx + 1} di ${this.photos.length}`);
            this.track.appendChild(slide);
            this.slides.push(slide);
        });
    }

    setupObserver() {
        if (!window.IntersectionObserver) return;

        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    const idx = parseInt(entry.target.dataset.index, 10);
                    if (!isNaN(idx) && idx !== this.currentIndex) {
                        this.currentIndex = idx;
                        this.renderAround(idx);
                        this.onChange(idx);
                    }
                }
            });
        }, {
            root: this.track,
            threshold: 0.6,
        });

        this.slides.forEach((s) => this.observer.observe(s));
    }

    bindEvents() {
        this.btnPrev?.addEventListener('click', () => this.prev());
        this.btnNext?.addEventListener('click', () => this.next());

        window.addEventListener('resize', () => {
            this.goTo(this.currentIndex, { smooth: false });
        });
    }

    renderAround(index) {
        this.slides.forEach((slide, idx) => {
            const dist = Math.abs(idx - index);
            if (dist <= 1) {
                if (!slide.querySelector('img')) {
                    const img = document.createElement('img');
                    img.src = this.photos[idx].web;
                    img.alt = `Foto ${idx + 1} di ${this.photos.length}`;
                    img.className = 'max-h-full max-w-full object-contain select-none';
                    img.onerror = () => {
                        img.src = this.photos[idx].orig;
                    };
                    slide.appendChild(img);
                }
            } else if (dist === 2) {
                // Precarica in memoria
                const preload = new Image();
                preload.src = this.photos[idx].web;
            } else {
                // Rimuovi per liberare memoria
                slide.innerHTML = '';
            }
        });
    }

    goTo(index, { smooth = true } = {}) {
        this.currentIndex = index;
        this.renderAround(index);

        if (this.track && this.slides[index]) {
            const behavior = smooth ? 'smooth' : 'auto';
            this.track.scrollTo({
                left: index * this.track.clientWidth,
                behavior: behavior,
            });
        }
    }

    next() {
        this.goTo(nextIndex(this.currentIndex, this.photos.length));
    }

    prev() {
        this.goTo(prevIndex(this.currentIndex, this.photos.length));
    }
}

